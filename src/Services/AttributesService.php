<?php

namespace TorqIT\StoreSyndicatorBundle\Services;

use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Pimcore\Model\Asset\Image;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject\Data\BlockElement;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyRelation;
use Pimcore\Model\DataObject\Objectbrick\Definition as ObjectbrickDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use Pimcore\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyObjectRelation;
use Pimcore\Model\DataObject\Fieldcollection\Definition as FieldcollectionDefinition;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;
use Pimcore\Model\DataObject\ClassDefinition\Data\Classificationstore as ClassificationStoreDefinition;
use Pimcore\Model\DataObject\Product\Listing;

class AttributesService
{
    static array $baseFields = [
        "descriptionHtml",
        "title",
        "options",//array
        "productType",
        "vendor",
        "tags",//array
        "status",// "ACTIVE" "ARCHIVED" or "DRAFT"
    ];

    static array $fieldTypes = [
        "base product",
        "Images",
        "metafields",
        "variant metafields",
        "base variant",
    ];

    static array $variantFields = [
        "cost",//productVariantInput.inventoryItem
        "tracked",//productVariantInput.inventoryItem
        "price",
        "compareAtPrice",
        "taxCode",
        "taxable",
        "sku",
        "barcode",
        "continueSellingOutOfStock",//inventoryPolicy "CONTINUE" or "DENY"
        "weight",
        "weightUnit",
        "requiresShipping"
    ];

    public function getRemoteFields(Graphql $client): array
    {
        //get metafields
        $query = ShopifyGraphqlHelperService::buildMetafieldsQuery();
        $response = $client->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = ["name" => $node["node"]["namespace"] .  "." . $node["node"]["key"], "type" => "metafields", "fieldDefType" => $node["node"]["type"]["name"]];
        }

        //get variant metafields
        $query = ShopifyGraphqlHelperService::buildVariantMetafieldsQuery();
        $response = $client->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = ["name" => $node["node"]["namespace"] .  "." . $node["node"]["key"], "type" => "variant metafields", "fieldDefType" => $node["node"]["type"]["name"]];
        }

        //get base fields
        foreach (self::$baseFields as $baseField) {
            $data[] = ["name" => $baseField, "type" => "base product"];
        }

        //get base variant fields
        foreach (self::$variantFields as $variantField) {
            $data[] = ["name" => $variantField, "type" => "base variant"];
        }
        return $data;
    }

    public function getRemoteTypes(): array
    {
        return self::$fieldTypes;
    }

    public function getLocalFields(Configuration $configuration): array
    {
        $config = $configuration->getConfiguration();

        $class = $config["products"]["class"];
        if (!$class = ClassDefinition::getByName($class)) {
            return [];
        }

        $attributes = ["Key"];
        $this->getFieldDefinitionsRecursive($class, $attributes, "");

        return $attributes;
    }

    private function getFieldDefinitionsRecursive($class, &$attributes, $prefix)
    {
        if (!method_exists($class, "getFieldDefinitions")) {
            $attributes[] = $prefix . $class->getName();
            return;
        }
        $fields = $class->getFieldDefinitions();
        foreach ($fields as $field) {
            if ($field instanceof Objectbricks) {
                $allowedTypes = $field->getAllowedTypes();
                foreach ($allowedTypes as $allowedType) {
                    $allowedTypeClass = ObjectbrickDefinition::getByKey($allowedType);
                    $this->getFieldDefinitionsRecursive($allowedTypeClass, $attributes, $prefix . $field->getName() . "." . $allowedType . ".");
                }
            } elseif ($field instanceof Fieldcollections) {
                $allowedTypes = $field->getAllowedTypes();
                foreach ($allowedTypes as $allowedType) {
                    $allowedTypeClass = FieldcollectionDefinition::getByKey($allowedType);
                    $this->getFieldDefinitionsRecursive($allowedTypeClass, $attributes, $prefix . $field->getName() . ".");
                }
            } elseif ($field instanceof ClassificationStoreDefinition) {
                $fields = $this->getStoreKeys($field->getStoreId());
                foreach ($fields as $field) {
                    $attributes[] = $prefix . $field->getName();
                }
            } elseif ($field instanceof Localizedfields) {
                $fields = $field->getChildren();
                foreach ($fields as $childField) {
                    if (!method_exists($childField, "getFieldDefinitions")) {
                        $attributes[] = $prefix . $childField->getName();
                    } else {
                        $this->getFieldDefinitionsRecursive($childField, $attributes, $prefix . $childField->getName() . ".");
                    }
                }
                if ($fields = $field->getReferencedFields()) {
                    $names = [];
                    foreach ($fields as $field) {
                        if (!in_array($field->getName(), $names)) { //sometimes returns the same field multiple times...
                            $this->getFieldDefinitionsRecursive($field, $attributes, $prefix);
                            $names[] = $field->getName();
                        }
                    }
                }
            } elseif ($field instanceof AbstractRelations) {
                if ($field instanceof AdvancedManyToManyRelation || $field instanceof AdvancedManyToManyObjectRelation) {
                    $classes = [["classes" => $field->getAllowedClassId()]];
                } else {
                    $classes = $field->classes;
                }
                foreach ($classes as $allowedClass) {
                    $allowedClass = ClassDefinition::getByName($allowedClass["classes"]);
                    $this->getFieldDefinitionsRecursive($allowedClass, $attributes, $prefix . $field->getName() . ".");
                }
            } else {
                $attributes[] = $prefix . $field->getName();
            }
        }
    }

    //get the value(s) at the end of the fieldPath array on an object
    public static function getObjectFieldValues($rootField, array $fieldPath)
    {
        $field = $fieldPath[0];
        array_shift($fieldPath);
        $getter = "get$field"; //need to do this instead of getValueForFieldName for bricks
        $fieldVal = $rootField->$getter();
        if (is_iterable($fieldVal)) { //this would be like manytomany fields
            $vals = [];
            foreach ($fieldVal as $singleVal) {
                if ($singleVal && is_object($singleVal) && method_exists($singleVal, "get" . $fieldPath[0])) {
                    $vals[] = self::getObjectFieldValues($singleVal, $fieldPath);
                } elseif ($singleVal && is_array($singleVal) && array_key_exists($fieldPath[0], $singleVal)) { //blocks
                    $vals[] = self::processLocalValue($singleVal[$fieldPath[0]]->getData());
                } else {
                    $vals[] = $singleVal;
                }
            }
            return count($vals) > 0 ? $vals : null;
        } elseif (count($fieldPath) == 0) {
            return self::processLocalValue($fieldVal);
        } elseif ($fieldVal instanceof BlockElement) {
            $vals = [];
            foreach ($fieldVal as $blockItem) {
                //assuming the next fieldname is the value we want
                $vals[] = self::processLocalValue($blockItem[$fieldPath[0]]->getData());
            }
            return count($vals) > 0 ? $vals : null;
        } else {
            if ($fieldVal && method_exists($fieldVal, "get" . $fieldPath[0])) {
                return self::getObjectFieldValues($fieldVal, $fieldPath);
            }
        }
    }

    private static function processLocalValue($field)
    {
        if ($field instanceof Image) {
            return $field;
        } elseif ($field instanceof ImageGallery) {
            $returnArray = [];
            foreach ($field->getItems() as $hotspot) {
                $returnArray[] = $hotspot->getImage();
            }
            return $returnArray;
        } elseif ($field instanceof QuantityValue) {
            return self::processLocalValue($field->getValue());
        } elseif (is_bool($field)) {
            return $field ? "true" : "false";
        } elseif (is_numeric($field)) {
            return strval($field);
        } elseif (empty($field)) {
            return null;
        } else {
            return strval($field);
        }
    }

    private function getStoreKeys($storeId)
    {
        $db = \Pimcore\Db::get();

        $condition = '(storeId = ' . $db->quote($storeId) . ')';
        $list = new Classificationstore\KeyConfig\Listing();
        $list->setCondition($condition);
        return $list->load();
    }
}
