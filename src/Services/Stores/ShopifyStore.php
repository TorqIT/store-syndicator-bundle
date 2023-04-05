<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Exception;
use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Pimcore\Model\DataObject;
use Pimcore\Model\Asset\Image;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\Concrete;
use Shopify\Rest\Admin2023_01\Product;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Shopify\Exception\RestResourceRequestException;
use TorqIT\StoreSyndicatorBundle\Services\AttributesService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\AbstractAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyQueryService;

class ShopifyStore extends BaseStore
{
    const IMAGEPROPERTYNAME = "ShopifyImageURL";
    private ShopifyQueryService $shopifyQueryService;
    private array $updateProductArrays;
    private array $createProductArrays;
    private array $updateVariantsArrays;
    private array $metafieldSetArrays;
    private array $updateImageMap;
    private array $metafieldTypeDefinitions;
    // private array $productMetafieldsMapping;
    //private array $variantMetafieldsMapping;

    private AttributesService $attributeService;

    public function __construct(private ConfigurationService $configurationService)
    {
        $this->attributeService = new AttributesService();
    }

    public function setup(Configuration $config)
    {
        $this->config = $config;
        $remoteStoreName = $this->configurationService->getStoreName($config);
        $this->propertyName = "TorqSS:" . $remoteStoreName . ":shopifyId";

        $configData = $this->config->getConfiguration();
        $configData["ExportLogs"] = [];
        $this->config->setConfiguration($configData);
        $this->config->save();

        $authenticator = ShopifyAuthenticator::getAuthenticatorFromConfig($config);
        $this->shopifyQueryService = new ShopifyQueryService($authenticator);

        //$this->productMetafieldsMapping = $this->getAllProducts();
        //$this->variantMetafieldsMapping = $this->getAllVariants();
        $this->metafieldTypeDefinitions = $this->shopifyQueryService->queryMetafieldDefinitions();
    }

    public function getAllProducts()
    {
        // $query = ShopifyGraphqlHelperService::buildProductsQuery();
        // $result = $this->client->query(["query" => $query])->getDecodedBody();
        // while (!$resultFileURL = $this->queryFinished("QUERY")) {
        // }
        // $products = [];
        // if ($resultFileURL != "none") {
        //     $resultFile = fopen($resultFileURL, "r");
        //     while ($productOrMetafield = fgets($resultFile)) {
        //         $productOrMetafield = (array)json_decode($productOrMetafield);
        //         if (array_key_exists("key", $productOrMetafield)) {
        //             $products[$productOrMetafield['__parentId']]['metafields'][$productOrMetafield["key"]] = [
        //                 "namespace" => $productOrMetafield["namespace"],
        //                 "key" => $productOrMetafield["key"],
        //                 "value" => $productOrMetafield["value"],
        //                 "id" => $productOrMetafield['id'],
        //             ];
        //         } elseif (array_key_exists("title", $productOrMetafield)) {
        //             $products[$productOrMetafield["id"]]['id'] = $productOrMetafield["id"];
        //             $products[$productOrMetafield["id"]]['title'] = $productOrMetafield["title"];
        //         }
        //     }
        // }
        // return $products;
    }

    private function getAllVariants()
    {
        // $query = ShopifyGraphqlHelperService::buildVariantsQuery();
        // $result = $this->client->query(["query" => $query])->getDecodedBody();
        // while (!$resultFileURL = $this->queryFinished("QUERY")) {
        // }
        // $variants = [];
        // if ($resultFileURL != "none") {
        //     $resultFile = fopen($resultFileURL, "r");
        //     while ($variantOrMetafield = fgets($resultFile)) {
        //         $variantOrMetafield = (array)json_decode($variantOrMetafield);
        //         if (array_key_exists("key", $variantOrMetafield)) {
        //             $variants[$variantOrMetafield['__parentId']][$variantOrMetafield["namespace"] . "." . $variantOrMetafield["key"]] = $variantOrMetafield['id'];
        //         }
        //     }
        // }
        // return $variants;
    }


    public function updateProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $remoteId = $this->getStoreProductId($object);

        $graphQLInput = [];
        $graphQLInput["title"] = $fields["title"][0] ?? $object->getKey();
        if (isset($fields['metafields'])) {
            foreach ($fields['metafields'] as $attribute) {
                $metafield = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["product"]);
                $metafield["ownerId"] = $remoteId;
                $this->metafieldSetArrays[] = $metafield;
            }
            unset($fields['metafields']);
        }
        if (isset($fields["Images"])) {
            /** @var Image $image */
            foreach ($fields["Images"] as $image) {
                $this->updateImageMap[$object->getId()][] = ["update", $image];
            }
            unset($fields["Images"]);
        }
        foreach ($fields['base product'] as $field => $value) {
            $graphQLInput[$field] = $value[0];
        }
        $graphQLInput["id"] = $remoteId;
        $graphQLInput["handle"] = $graphQLInput["title"] . "-" . $remoteId;
        $this->updateProductArrays[$object->getId()] = $graphQLInput;
    }

    public function createProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $fields["metafields"][] = [
            "fieldName" => "pimcore_id",
            "value" => [strval($object->getId())],
            "namespace" => "custom",
        ];
        $graphQLInput = [];
        $graphQLInput["title"] = $object->getKey();
        if (isset($fields['metafields'])) {
            foreach ($fields['metafields'] as $attribute) {
                $graphQLInput["metafields"][] = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["product"]);
            }
            unset($fields['metafields']);
        }
        if (isset($fields["Images"])) {
            /** @var Image $image */
            foreach ($fields["Images"] as $image) {
                $this->updateImageMap[$object->getId()][] = ["create", $image];
            }
            unset($fields["Images"]);
        }
        foreach ($fields['base product'] as $field => $value) {
            $graphQLInput[$field] = $value[0];
        }
        $this->createProductArrays[$object->getId()] = $graphQLInput;
    }

    public function createVariant(Concrete $parent, Concrete $child): void
    {
        $fields = $this->getAttributes($child);

        $fields['variant metafields'][] = [
            "fieldName" => "pimcore_id",
            "value" => [strval($child->getId())],
            "namespace" => "custom",
        ];

        foreach ($fields['variant metafields'] as $attribute) {
            $graphQLInputString["metafields"][] = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["variant"]);
        }

        foreach ($fields['base variant'] as $field => $value) {
            if ($field == 'weight') { //wants this as a non-string wrapped number
                $value[0] = (float)$value[0];
            }
            $graphQLInputString[$field] = $value[0];
        }

        if (!isset($thisVariantArray["title"])) {
            $thisVariantArray["title"] = $child->getKey();
        }
        if (!isset($thisVariantArray["options"])) {
            $thisVariantArray["options"] = [$thisVariantArray["title"]];
        }

        $this->createProductArrays[$parent->getId()]["variants"][] = $thisVariantArray;
    }

    public function updateVariant(Concrete $parent, Concrete $child): void
    {
        $remoteId = $this->getStoreProductId($child);

        $fields = $this->getAttributes($child);

        $fields['variant metafields'][] = [
            "fieldName" => "pimcore_id",
            "value" => [strval($child->getId())],
            "namespace" => "custom",
        ];

        foreach ($fields['variant metafields'] as $attribute) {
            $metafield = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["variant"]);
            $metafield["ownerId"] = $remoteId;
            $this->metafieldSetArrays[] = $metafield;
        }

        foreach ($fields['base variant'] as $field => $value) {
            if ($field == 'weight') { //wants this as a non-string wrapped number
                $value[0] = (float)$value[0];
            }
            $thisVariantArray[$field] = $value[0];
        }

        if (!isset($thisVariantArray["title"])) {
            $thisVariantArray["title"] = $child->getKey();
        }
        if (!isset($thisVariantArray["options"])) {
            $thisVariantArray["options"] = [$thisVariantArray["title"]];
        }

        $this->updateVariantsArrays[] = $thisVariantArray;
    }

    /**
     * @param array $attribute [key => *, namespace => *, value => *]
     * @param array $mappingArray $this->metafieldTypeDefinitions["variant"/"product"]
     * @return array full metafield shopify object
     **/
    private function createMetafield($attribute, $mappingArray)
    {
        if (array_key_exists($attribute["namespace"] . "." .  $attribute["fieldName"], $mappingArray)) {
            if (str_contains($mappingArray[$attribute["namespace"] . "." .  $attribute["fieldName"]]["type"], "list.")) {
                $attribute["value"] = json_encode($attribute["value"]);
            }
            $tmpMetafield = $mappingArray[$attribute["namespace"] . "." .  $attribute["fieldName"]];
            $tmpMetafield["value"] = $attribute["value"];
        } else {
            throw new Exception("undefined metafield definition: " . $attribute["namespace"] . "." .  $attribute["fieldName"]);
        }
        return $tmpMetafield;
    }

    public function commit(): Models\CommitResult
    {
        $commitResults = new Models\CommitResult();

        //upload new images and add the src to 
        if (isset($this->updateImageMap)) {
            $pushArray = [];
            $mapBackArray = [];
            //upload assets with no shopify url
            foreach ($this->updateImageMap as $productId => $product) {
                foreach ($product as $image) {
                    $updateOrCreate = $image[0];
                    $image = $image[1];
                    if (!$image->getProperty(self::IMAGEPROPERTYNAME)) {
                        $pushArray[] = ["filename" => $image->getLocalFile(), "resource" => "PRODUCT_IMAGE"];
                    }
                    $mapBackArray[$image->getLocalFile()] = [$image, $productId, $updateOrCreate];
                }
            }
            $remoteFileKeys = $this->shopifyQueryService->uploadFiles($pushArray);
            $this->addLogRow("uploaded images", count($remoteFileKeys));
            //and save their url's
            foreach ($remoteFileKeys as $fileName => $remoteFileKey) {
                /** @var Image $image */
                $image = $mapBackArray[$fileName][0];
                $image->setProperty(self::IMAGEPROPERTYNAME, "text", $remoteFileKey["url"]);
                $image->save();
            }
            //add them to update/create queries
            foreach ($mapBackArray as $mapBackImage) {
                if ($mapBackImage[2] == "create") {
                    $this->createProductArrays[$mapBackImage[1]]["images"][] = ["src" => $mapBackImage[0]->getProperty(self::IMAGEPROPERTYNAME)];
                } elseif ($mapBackArray[$fileName][2] == "update") {
                    $this->updateProductArrays[$mapBackImage[1]]["images"][] = ["src" => $mapBackImage[0]->getProperty(self::IMAGEPROPERTYNAME)];
                }
            }
        }

        if ($this->createProductArrays) {
            //create unmade products
            $resultFileURL = $this->shopifyQueryService->createProducts($this->createProductArrays);
            $this->addLogRow("create product & variant result file", $resultFileURL);
        }

        //also takes care of creating variants
        if ($this->updateProductArrays) {
            $this->shopifyQueryService->updateProducts($this->updateProductArrays);
        }

        if (isset($this->updateVariantsArrays)) {
            $resultFiles = $this->shopifyQueryService->updateVariants($this->updateVariantsArrays);
            foreach ($resultFiles as $resultFileURL) {
                $this->addLogRow("update variant result file", $resultFileURL);
            }
        }

        if (isset($this->metafieldSetArrays)) {
            $resultFiles = $this->shopifyQueryService->updateMetafields($this->metafieldSetArrays);
            foreach ($resultFiles as $resultFileURL) {
                $this->addLogRow("update metafield result file", $resultFileURL);
            }
        }
        $this->config->save();
        return $commitResults;
    }
}
