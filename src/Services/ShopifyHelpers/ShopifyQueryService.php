<?php

namespace TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers;

use GraphQL\Error\SyntaxError;
use Shopify\Clients\Graphql;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;

/**
 * class to make queries to shopify and proccess their result for you into readable arrays
 */
class ShopifyQueryService
{
    private Graphql $graphql;
    public function __construct(
        ShopifyAuthenticator $abstractAuthenticator
    ) {
        $this->graphql = $abstractAuthenticator->connect()['client'];
    }

    /**
     * query all variants and their metafields
     *
     * @param string $query will run this query if provided to allow for custom variant queries like created_at.
     * @return array
     **/
    public function queryVariants($query = null): array
    {
        if (!$query) {
            $query = ShopifyGraphqlHelperService::buildVariantsQuery();
        }
        $queryResult = $this->runQuery($query);
        while (!$resultFileURL = $this->queryFinished("QUERY")) {
            sleep(1); //wait a second between checks
        }
        $formattedResults = [];

        $resultFile = fopen($resultFileURL, "r");
        while ($variantOrMetafield = fgets($resultFile)) {
            $variantOrMetafield = json_decode($variantOrMetafield, true);
            if (array_key_exists("key", $variantOrMetafield)) {
                $formattedResults[$variantOrMetafield["__parentId"]]['metafields'][$variantOrMetafield["namespace"] . "." . $variantOrMetafield["key"]] = $variantOrMetafield;
            } else {
                $formattedResults[$variantOrMetafield["id"]] = $formattedResults[$variantOrMetafield["id"]] ?? [];
                $formattedResults[$variantOrMetafield["id"]]['title'] = $variantOrMetafield["title"];
                $formattedResults[$variantOrMetafield["id"]]['product'] = $variantOrMetafield["product"]['id'];
            }
        }
        return $formattedResults;
    }

    /**
     * query all variants and their metafields
     *
     * @param string $query will run this query if provided to allow for custom product queries like created_at.
     * @return array
     **/
    public function queryProducts($query = null): array
    {
        if (!$query) {
            $query = ShopifyGraphqlHelperService::buildProductsQuery();
        }
        $queryResult = $this->runQuery($query);
        while (!$resultFileURL = $this->queryFinished("QUERY")) {
            sleep(1);
        }
        $formattedResults = [];

        $resultFile = fopen($resultFileURL, "r");
        while ($productOrMetafield = fgets($resultFile)) {
            $productOrMetafield = (array)json_decode($productOrMetafield);
            if (array_key_exists("key", $productOrMetafield)) {
                $formattedResults[$productOrMetafield["__parentId"]]['metafields'][$productOrMetafield["namespace"] . "." . $productOrMetafield["key"]] = $productOrMetafield;
            } else {
                $formattedResults[$productOrMetafield["id"]] = $formattedResults[$productOrMetafield["id"]] ?? [];
                $formattedResults[$productOrMetafield["id"]]['title'] = $productOrMetafield["title"];
            }
        }
        return $formattedResults;
    }

    public function queryMetafieldDefinitions()
    {

        $data = [
            "product" => [],
            "variant" => []
        ];
        $query = ShopifyGraphqlHelperService::buildMetafieldsQuery();
        $response = $this->runQuery($query);
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data["product"][$node["node"]["namespace"] . "." . $node["node"]["key"]] = [
                "namespace" => $node["node"]["namespace"],
                "name" => $node["node"]["key"],
                "type" => $node["node"]["type"]["name"]
            ];
        }

        //get variant metafields
        $query = ShopifyGraphqlHelperService::buildVariantMetafieldsQuery();
        $response = $this->runQuery($query);
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data["variant"][$node["node"]["namespace"] . "." . $node["node"]["key"]] = [
                "namespace" => $node["node"]["namespace"],
                "name" => $node["node"]["key"],
                "type" => $node["node"]["type"]["name"]
            ];
        }
        return $data;
    }

    public function updateProducts(array $inputArray)
    {
        $inputString = "";
        foreach ($inputArray as $inputObj) {
            $inputString .= json_encode(["input" => $inputObj]) . PHP_EOL;
        }
        $file = $this->makeFile($inputString);
        $filename = stream_get_meta_data($file)['uri'];
        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        fclose($file);

        $product_update_query = ShopifyGraphqlHelperService::buildUpdateQuery($remoteFileKey);
        $result = $this->graphql->query(["query" => $product_update_query])->getDecodedBody();

        while (!$resultFileURL = $this->queryFinished("MUTATION")) {
            sleep(1);
        }
        return $resultFileURL;
    }

    public function createProducts(array $inputArray)
    {
        $inputString = "";
        foreach ($inputArray as $inputObj) {
            $inputString .= json_encode(["input" => $inputObj]) . PHP_EOL;
        }
        $file = $this->makeFile($inputString);
        $filename = stream_get_meta_data($file)['uri'];
        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        fclose($file);

        $product_update_query = ShopifyGraphqlHelperService::buildCreateProductsQuery($remoteFileKey);
        $result = $this->graphql->query(["query" => $product_update_query])->getDecodedBody();

        while (!$resultFileURL = $this->queryFinished("MUTATION")) {
            sleep(1);
        }
        return $resultFileURL;
    }

    public function updateProductMedia(array $inputArray)
    {
        $inputString = "";
        foreach ($inputArray as $inputObj) {
            $inputString .= json_encode(["input" => $inputObj]) . PHP_EOL;
        }
        $file = $this->makeFile($inputString);
        $filename = stream_get_meta_data($file)['uri'];
        $remoteKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);

        $bulkParamsFilekey = $remoteKeys[$filename]["key"];
        fclose($file);
        $imagesCreateQuery = ShopifyGraphqlHelperService::buildCreateMediaQuery($bulkParamsFilekey);

        $results = $this->graphql->query(["query" => $imagesCreateQuery])->getDecodedBody();
        while (!$resultFileURL = $this->queryFinished("MUTATION")) {
            sleep(1);
        }
        return $resultFileURL;
    }

    public function updateVariants(array $inputArray)
    {
        $resultFiles = [];
        $file = tmpfile();
        foreach ($inputArray as $parentId => $variantMap) {
            fwrite($file, json_encode(["input" => $variantMap]) . PHP_EOL);
            if (fstat($file)["size"] >= 15000000) { //at 2mb the file upload will fail
                $filename = stream_get_meta_data($file)['uri'];

                $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
                $remoteFileKey = $remoteFileKeys[$filename]["key"];
                $variantQuery = ShopifyGraphqlHelperService::buildUpdateVariantsQuery($remoteFileKey);
                $result = $this->graphql->query(["query" => $variantQuery])->getDecodedBody();
                fclose($file);
                $file = tmpfile();
                while (!$resultFileURL = $this->queryFinished("MUTATION")) {
                    sleep(1);
                }
                $resultFiles[] = $resultFileURL;
            }
        }
        if (fstat($file)["size"] > 0) { //if there are any variants in here
            $filename = stream_get_meta_data($file)['uri'];

            $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
            $remoteFileKey = $remoteFileKeys[$filename]["key"];
            $variantQuery = ShopifyGraphqlHelperService::buildUpdateVariantsQuery($remoteFileKey);
            $result = $this->graphql->query(["query" => $variantQuery])->getDecodedBody();
            fclose($file);
            $file = tmpfile();
            while (!$resultFileURL = $this->queryFinished("MUTATION")) {
                sleep(1);
            }
            $resultFiles[] = $resultFileURL;
        }
        return $resultFiles;
    }

    public function updateMetafields(array $inputArray)
    {
        $resultFiles = [];
        $file = tmpfile();
        foreach ($inputArray as $metafieldArray) {
            fwrite($file, json_encode(["input" => $metafieldArray]) . PHP_EOL);
            if (fstat($file)["size"] >= 15000000) { //at 2mb the file upload will fail
                $filename = stream_get_meta_data($file)['uri'];

                $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
                $remoteFileKey = $remoteFileKeys[$filename]["key"];
                $variantQuery = ShopifyGraphqlHelperService::buildMetafieldSetQuery($remoteFileKey);
                $result = $this->graphql->query(["query" => $variantQuery])->getDecodedBody();
                fclose($file);
                $file = tmpfile();
                while (!$resultFileURL = $this->queryFinished("MUTATION")) {
                    sleep(1);
                }
                $resultFiles[] = $resultFileURL;
            }
        }
        if (fstat($file)["size"] > 0) { //if there are any variants in here
            $filename = stream_get_meta_data($file)['uri'];

            $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
            $remoteFileKey = $remoteFileKeys[$filename]["key"];
            $variantQuery = ShopifyGraphqlHelperService::buildMetafieldSetQuery($remoteFileKey);
            $result = $this->graphql->query(["query" => $variantQuery])->getDecodedBody();
            fclose($file);
            $file = tmpfile();
            while (!$resultFileURL = $this->queryFinished("MUTATION")) {
                sleep(1);
            }
            $resultFiles[] = $resultFileURL;
        }
        return $resultFiles;
    }

    private function makeFile($content)
    {
        $file = tmpfile();
        fwrite($file, $content);
        return $file;
    }

    /**
     * wrap query call for error catching and such
     * 
     * @param string $query the query to be ran
     * @return type
     * @throws conditon
     **/
    private function runQuery($query)
    {
        try {
            $response = $this->graphql->query(["query" => $query]);
            $response = $response->getDecodedBody();
        } catch (SyntaxError $e) {
            //we could do some error logging here
            return null;
        }

        return $response;
    }

    /**
     * uploads the files at the strings you provide
     * @param array<array<string, string>> $var [[filename, resource]..]
     * @return array<array<string, string>> [[filename => [url, remoteFileKey]..]
     **/
    public function uploadFiles(array $files): array
    {
        //build query and query variables 
        $query = ShopifyGraphqlHelperService::buildFileUploadQuery();
        $variables["input"] = [];
        $stagedUploadUrls = [];
        $count = 0;
        foreach ($files as $file) {
            $filepatharray = explode("/", $file["filename"]);
            $filename = end($filepatharray);
            $variables["input"][] = [
                "filename" => $filename,
                "resource" => $file["resource"],
                "mimeType" => mime_content_type($file["filename"]),
                //"mimeType" => "text/jsonl",
                "httpMethod" => "POST",
            ];
            $count++;
            if ($count % 200 == 0) { //if the query asks for more it will fail so loop the call if needed. 
                $response = $this->graphql->query(["query" => $query, "variables" => $variables])->getDecodedBody();
                $stagedUploadUrls = array_merge($stagedUploadUrls, $response["data"]["stagedUploadsCreate"]["stagedTargets"]);
                $count = 0;
                $variables["input"] = [];
            }
        }
        if ($count > 0) {
            $response = $this->graphql->query(["query" => $query, "variables" => $variables])->getDecodedBody();
            $stagedUploadUrls = array_merge($stagedUploadUrls, $response["data"]["stagedUploadsCreate"]["stagedTargets"]);
        }

        //upload all the files
        $fileKeys = [];
        foreach ($stagedUploadUrls as $fileInd => $uploadTarget) {
            $file = $files[$fileInd];
            $filepatharray = explode("/", $file["filename"]);
            $filename = end($filepatharray);

            $curl_opt_url = $uploadTarget["url"];
            $parameters = $uploadTarget["parameters"];
            $curl_key = $parameters[3]["value"];
            $curl_policy = $parameters[8]["value"];
            $curl_x_goog_credentials = $parameters[5]["value"];
            $curl_x_goog_algorithm = $parameters[6]["value"];
            $curl_x_goog_date = $parameters[4]["value"];
            $curl_x_goog_signature = $parameters[7]["value"];

            //send upload
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $curl_opt_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            $mimetype = mime_content_type($file["filename"]);
            $post = array(
                'key' => $curl_key,
                'x-goog-credential' => $curl_x_goog_credentials,
                'x-goog-algorithm' => $curl_x_goog_algorithm,
                'x-goog-date' => $curl_x_goog_date,
                'x-goog-signature' => $curl_x_goog_signature,
                'policy' => $curl_policy,
                'acl' => 'private',
                'Content-Type' => $mimetype,
                'success_action_status' => '201',
                'file' => new \CURLFile($file["filename"], $mimetype, $filename)
            );
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                echo 'Error:' . curl_error($ch);
            }
            $arr_result = simplexml_load_string(
                $result,
            );
            curl_close($ch);
            $fileKeys[$file["filename"]] = ["url" => (string) $arr_result->Location, "key" => (string) $arr_result->Key];
        }

        return $fileKeys;
    }

    private function queryFinished($queryType): bool|string
    {
        $query = ShopifyGraphqlHelperService::buildQueryFinishedQuery($queryType);
        $response = $this->runQuery($query);

        if ($response['data']["currentBulkOperation"] && $response['data']["currentBulkOperation"]["completedAt"]) {
            return $response['data']["currentBulkOperation"]["url"] ?? "none"; //if the query returns nothing
        } else {
            return false;
        }
    }
}
