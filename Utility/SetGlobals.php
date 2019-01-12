<?php
/**
 * Created by PhpStorm.
 * User: SteveWinter
 * Date: 10/04/2017
 * Time: 15:10
 */

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Doctrine\DBAL\Connection;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\FMConnection;

class SetGlobals
{

    /**
     * @var FMConnection
     */
    protected $conn;

    /**
     * ScriptAccess constructor.
     * @param Connection $conn
     */
    function __construct(Connection $conn)
    {
        $this->conn = $conn->getWrappedConnection();
    }


    /**
     * @param array $globals
     *
     * @return array
     *
     * @throws FMException
     */
    public function setGlobals($globals = [])
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