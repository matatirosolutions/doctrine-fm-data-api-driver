<?php

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Doctrine\DBAL\Connection;
use Exception;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use Throwable;

class SetGlobals
{

    protected ?FMRequest $conn = null;

    private bool $connectionAttempted = false;

    private Connection $dbalConn;

    /**
     * The connection is only looked up when it is first needed, so creating this has no side effects.
     */
    function __construct(Connection $conn)
    {
        $this->dbalConn = $conn;
    }

    private function getConn(): ?FMRequest
    {
        if (!$this->connectionAttempted) {
            $this->connectionAttempted = true;
            try {
                $this->conn = $this->dbalConn->getNativeConnection();
            } catch (Exception | Throwable $e) {
                $this->conn = null;
            }
        }

        return $this->conn;
    }

    /**
     * @throws FMException
     */
    public function setGlobals(array $globals = []): array
    {
        $conn = $this->getConn();
        if(null === $conn) {
            throw new FMException('No connection to FileMaker');
        }

        $uri = 'globals';
        $opts = [
            'body' => json_encode([
                'globalFields' => $globals
            ])
        ];
        try {
            return $conn->performFMRequest('PATCH', $uri, $opts);
        } catch(\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }
}
