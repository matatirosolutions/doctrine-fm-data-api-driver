<?php

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Doctrine\DBAL\Connection;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\FMConnection;

class SetGlobals
{

    /**
     * @var FMRequest
     */
    protected $conn;

    function __construct(Connection $conn)
    {
        $this->conn = $conn->getNativeConnection();
    }

    /**
     * @throws FMException
     */
    public function setGlobals(array $globals = []): array
    {
        $uri = 'globals';
        $opts = [
            'body' => json_encode([
                'globalFields' => $globals
            ])
        ];
        try {
            return $this->conn->performFMRequest('PATCH', $uri, $opts);
        } catch(\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

}
