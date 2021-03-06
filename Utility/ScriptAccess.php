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

class ScriptAccess
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
     * @param string $layout
     * @param int $recId
     * @param string $script
     * @param string $param
     *
     * @return array
     *
     * @throws FMException
     */
    public function performScript($layout, $recId, $script, $param = '')
    {
        $uri = sprintf('/layouts/%s/records/%s?script=%s&script.param=%s', $layout, $recId, $script, $param);
        try {
            $this->conn->performFMRequest('GET', $uri, []);

            $uri = sprintf('/layouts/%s/records/%s', $layout, $recId);
            $record = $this->conn->performFMRequest('GET', $uri, []);

            return $record[0]['fieldData'];
        } catch(\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }
}