<?php

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Doctrine\DBAL\Connection;
use Exception;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\FMConnection;
use Throwable;


class ScriptAccess
{

    protected FMRequest $conn;

    public function __construct(Connection $conn)
    {
        try {
            $this->conn = $conn->getNativeConnection();
        } catch (Exception | Throwable $except) {
            $this->conn = null;
        }
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
        if(null === $this->conn) {
            throw new FMException('No connection to FileMaker');
        }

        $uri = sprintf('/layouts/%s/script/%s?script.param=%s', $layout, $script, $param);
        if(null !== $recId) {
            $uri = sprintf('/layouts/%s/records/%s?script=%s&script.param=%s', $layout, $recId, $script, $param);
        }

        try {
            $scriptResult = $this->conn->performFMRequest('GET', $uri, [], $returnScriptResult);
            if($returnScriptResult) {
                return $scriptResult;
            }

            $uri = sprintf('/layouts/%s/records/%s', $layout, $recId);
            $record = $this->conn->performFMRequest('GET', $uri, []);

            return $record[0]['fieldData'];
        } catch(Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

}
