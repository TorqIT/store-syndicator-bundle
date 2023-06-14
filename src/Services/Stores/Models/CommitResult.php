<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores\Models;

use Pimcore\Model\DataObject\Concrete;

class CommitResult
{

    /**
     * @var Concrete[] $updated 
     */
    private array $updated;

    /**
     * @var Concrete[] $created 
     */
    private array $created;

    /** @var String[] $errors */
    private array $errors;

    private array $logs;

    public function __construct()
    {
        $this->updated = [];
        $this->created = [];
        $this->errors = [];
        $this->logs = [];
    }

    public function addCreated(Concrete $object)
    {
        $this->created[] = $object;
    }

    public function addUpdated(Concrete $object)
    {
        $this->updated[] = $object;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Add a error
     *
     * @param string $error
     */
    public function addError(string $error)
    {
        $this->errors[] = $error;
    }

    /**
     * Get the value of errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Add a logs
     *
     * @param array $logs
     */
    public function addLog(string $log)
    {
        $this->logs[] = $log;
    }

    /**
     * Get the value of logs
     *
     * @return array
     */
    public function getLogs(): array
    {
        return $this->logs;
    }
}
