<?php

namespace Services;

namespace TorqIT\StoreSyndicatorBundle\Services;

use Exception;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use TorqIT\StoreSyndicatorBundle\Services\Stores\BaseStore;
use TorqIT\StoreSyndicatorBundle\Services\Stores\Models\CommitResult;
use TorqIT\StoreSyndicatorBundle\Services\Stores\ShopifyStore;
use TorqIT\StoreSyndicatorBundle\Services\Stores\StoreFactory;
use TorqIT\StoreSyndicatorBundle\Services\Stores\StoreInterface;

/*
    Gets the correct StoreInterface from the config file.

    It then gets all the paths from the config, and calls export on the paths.
*/

class ExecutionService
{
    private Configuration $config;
    private string $classType;
    private BaseStore $storeInterface;
    private array $filters;

    public function __construct(ShopifyStore $storeInterface)
    {
        $this->storeInterface = $storeInterface;
    }

    public function export(Configuration $config)
    {
        $this->config = $config;
        $configData = $this->config->getConfiguration();
        $this->storeInterface->setup($config);

        $this->classType = $configData["products"]["class"];
        $this->classType = "Pimcore\\Model\\DataObject\\" . $this->classType;

        $this->filters = $this->buildFilterArray($configData);

        $productsToExport = $this->buildExportArray($configData);

        $rejects = []; //array of products we cant export
        foreach ($productsToExport as $productToExport) {
            $this->recursiveExport($productToExport, $rejects);
        }

        $results = $this->storeInterface->commit();
        $results->addError("products with over 100 variants: " . json_encode($rejects));
        return $results;
    }

    private function recursiveExport($dataObject, &$rejects)
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

        $products = $dataObject->getChildren([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER], true);

        foreach ($products as $product) {
            if (!in_array($product->getRealFullPath(), $this->filters)) {
                $this->recursiveExport($product, $rejects);
            }
        }
    }

    private function buildFilterArray(array $configData): array
    {
        $filterArray = [];
        foreach ($configData["products"]['excludeProducts'] ?? [] as $array) {
            $filterArray[] = $array["cpath"];
        }
        return $filterArray;
    }

    private function buildExportArray(array $configData)
    {
        $productPaths = $configData["products"]["products"];

        $toExport = [];
        foreach ($productPaths as $pathArray) {
            $path = $pathArray["cpath"];
            if ($objectOrFolder = DataObject::getByPath($path)) {
                $toExport[] = $objectOrFolder;
            } //could throw error here "object not found at path"
        }
        return array_filter($toExport, array($this, "filter"));
    }

    private function filter(DataObject $object)
    {
        do {
            if (in_array($object->getRealFullPath(), $this->filters)) {
                return false;
            }
        } while ($object = $object->getParent());
        return true;
    }
}
