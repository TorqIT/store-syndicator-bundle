<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Authenticators;

use Exception;
use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\Data\EncryptedField;
use Pimcore\Model\DataObject\TorqStoreExporterShopifyCredentials;
use Throwable;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\AbstractAuthenticator;

class ShopifyAuthenticator extends AbstractAuthenticator
{
    protected $host;
    protected $apiAccessToken;
    protected $apiKey;
    protected $apiSecret;

    public function connect(): array
    {
        try {
            $host = $this->host;
            Context::initialize(
                $this->apiKey->getPlain(),
                $this->apiSecret->getPlain(),
                ["read_products", "write_products"],
                $host,
                new FileSessionStorage('/tmp/php_sessions')
            );
            $offlineSession = new Session("offline_$host", $host, false, 'state');
            $offlineSession->setScope(Context::$SCOPES->toString());
            $offlineSession->setAccessToken($this->apiAccessToken->getPlain());
            $session = $offlineSession;
            $client = new Graphql($session->getShop(), $session->getAccessToken());
            return [
                'host' => $this->host,
                'secret' => $this->apiSecret->getPlain(),
                'key' => $this->apiKey->getPlain(),
                'token' => $this->apiAccessToken->getPlain(),
                'session' => $session,
                'client' => $client
            ];
        } catch (Throwable $e) {
            throw new Exception("unable to login using provided credentials: ", 0, $e);
        }
    }
}
