<?php

namespace Services;

namespace TorqIT\StoreSyndicatorBundle\Services;

use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Bundle\DataHubBundle\Configuration;
use TorqIT\StoreSyndicatorBundle\Services\Stores\BaseStore;
use TorqIT\StoreSyndicatorBundle\Services\Stores\ShopifyStore;

/*
    Gets the correct StoreInterface from the config file.

    It then gets all the paths from the config, and calls export on the paths.
*/

class ExecutionService
{
    private Configuration $config;
    private string $classType;
    private BaseStore $storeInterface;

    public function __construct(ShopifyStore $storeInterface, private ApplicationLogger $applicationLogger)
    {
        $this->storeInterface = $storeInterface;
    }

    public function export(Configuration $config)
    {
        $this->config = $config;
        $configData = $this->config->getConfiguration();
        $this->storeInterface->setup($config);

        $this->config->setConfiguration($configData);
        $this->config->save();

        $classType = $configData["products"]["class"];
        $classType = ClassDefinition::getById($classType);
        $this->classType = "Pimcore\\Model\\DataObject\\" . ucfirst($classType->getName());

        $productListing = $this->getClassListing($configData);

        $rejects = []; //array of products we cant export
        foreach ($productListing as $product) {
            if ($product) {
                $this->proccess($product, $rejects);
            }
        }
        $this->storeInterface->commit();

        $this->config->setConfiguration($configData);
        $this->config->save();
    }

    private function proccess($dataObject, &$rejects)
    {
        /** @var Concrete $dataObject */
        if (is_a($dataObject, $this->classType)) {
            if (count($dataObject->getChildren([Concrete::OBJECT_TYPE_VARIANT], true)) > 100) {
                $rejects[] = $dataObject->getId();
            } else {
                if (!$this->storeInterface->existsInStore($dataObject)) {
                    $this->storeInterface->createProduct($dataObject);
                } else {
                    $this->storeInterface->updateProduct($dataObject);
                }
                foreach ($dataObject->getChildren([Concrete::OBJECT_TYPE_VARIANT], true) as $childVariant) {
                    if ($this->storeInterface->existsInStore($childVariant)) {
                        $this->storeInterface->updateVariant($dataObject, $childVariant);
                    } else {
                        $this->storeInterface->createVariant($dataObject, $childVariant);
                    }
                }
            }
        }
    }

    private function getClassListing($configData): Dataobject\Listing
    {
        $sql = $configData["products"]["sqlCondition"];
        $listing = $this->classType . '\\Listing';
        $listing = new $listing();
        /** @var Dataobject\Listing $listing */
        $listing->setObjectTypes(['object']);
        $listing->setCondition($sql);
        if (array_key_exists("includeUnpublished", $configData)) {
            $listing->setUnpublished($configData["includeUnpublished"]);
        }
        return $listing;
    }
}
