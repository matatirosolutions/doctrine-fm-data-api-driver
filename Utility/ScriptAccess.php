<?php

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Doctrine\DBAL\Connection;
use Exception;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\FMConnection;
use Throwable;


class ScriptAccess
{

    protected ?FMRequest $conn = null;

    private bool $connectionAttempted = false;

    private Connection $dbalConn;

    public function __construct(Connection $conn)
    {
        $this->dbalConn = $conn;
    }

    private function getConn(): ?FMRequest
    {
        if (!$this->connectionAttempted) {
            $this->connectionAttempted = true;
            try {
                $this->conn = $this->dbalConn->getNativeConnection();
            } catch (Exception | Throwable $except) {
                $this->conn = null;
            }
        }
        return $this->conn;
    }


    /**
     * @throws FMException
     */
    public function performScript(
        string $layout,
        ?int $recId,
        string $script,
        $param = '',
        bool $returnScriptResult = false
    ): array
    {
        $conn = $this->getConn();
        if(null === $conn) {
            throw new FMException('No connection to FileMaker');
        }

        $uri = sprintf('/layouts/%s/script/%s?script.param=%s', $layout, $script, $param);
        if(null !== $recId) {
            $uri = sprintf('/layouts/%s/records/%s?script=%s&script.param=%s', $layout, $recId, $script, $param);
        }

        try {
            $scriptResult = $conn->performFMRequest('GET', $uri, [], $returnScriptResult);
            if($returnScriptResult) {
                return $scriptResult;
            }

            $uri = sprintf('/layouts/%s/records/%s', $layout, $recId);
            $record = $conn->performFMRequest('GET', $uri, []);

            return $record[0]['fieldData'];
        } catch(Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @throws FMException
     */
    public function executeScript(string $layout, string $script, string $param = ''): array
    {
        $conn = $this->getConn();
        if(null === $conn) {
            throw new FMException('No connection to FileMaker');
        }

        $uri = sprintf('layouts/%s/script/%s?script.param=%s', $layout, $script, $param);
        try {
            return $conn->performFMRequest('GET', $uri, [], true);
        } catch(Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

}
