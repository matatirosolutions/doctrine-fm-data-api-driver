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
use GuzzleHttp\Client;


class ContainerAccess
{

    /**
     * @var FMConnection
     */
    protected $con;

    /**
     * @var ScriptAccess
     */
    protected $script;

    public function __construct(Connection $conn, ScriptAccess $script)
    {
        $this->con = $conn->getWrappedConnection();
        $this->script = $script;
    }

    /**
     * Retrieve externally stored container content
     *
     * @param string $path
     *
     * @return string
     *
     * @throws FMException
     */
    public function getExternalContainerContent($path) {
        $client = new Client();
        $url = $this->generateURL($path);

        try {
            $response = $client->get($url);
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Retrieve DataAPI streamed content
     *
     * @param $url
     * @return string
     *
     * @throws FMException
     */
    public function getStreamedContainerContent($url) {
        $client = new Client(['cookies' => true]);
        try {
            $response = $client->get($url);
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Calls a FileMaker script to insert content into a container
     *
     * @param string $layout        The FM layout which the field to insert into is on
     * @param string $idField       The name of the field to locate the record using
     * @param string $uuid          The uuid to locate the record by
     * @param string $field         The name of the field
     * @param string $assetPath     A URL which the asset can be retrieved from
     *                              This either needs to be publically accessible, or include
     *                              the necessary credentials to access the asset using the
     *                              FileMaker InsertFromURL script step
     * @throws FMException
     *
     */
    public function insertIntoContainer($layout, $idField, $uuid, $field, $assetPath)
    {
        $data = json_encode([
            'idField' => $idField,
            'uuid' => $uuid,
            'field' => $field,
            'asset' => $assetPath,
        ]);

        try {
            $this->script->performScript($layout, $uuid,'ImportToContainer', $data);
        } catch(\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @param string $layout
     * @param int $recId
     * @param $field
     * @param $file
     * @param int $repetition
     *
     * @return array
     *
     * @throws FMException
     */
    public function performContainerInsert($layout, $recId, $field, $file, $repetition = 1)
    {
        $uri = sprintf('layouts/%s/records/%s/containers/%s/%s', $layout, $recId, $field, $repetition);
        $options = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->con->getToken()),
            ],
            'multipart' => [
                [
                    'name'     => 'upload',
                    'contents' => fopen($file, 'r')
                ],
            ]
        ];

        try {
            return $this->con->performFMRequest('POST', $uri, $options);
        } catch(\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Generate a request URL for container data from Doctrine parameters
     * and the passed FM path
     *
     * @param string $path
     * @return string
     */
    private function generateURL($path)
    {
        $params = $this->con->getParameters();
        $url = parse_url($params['host']);
        $host = array_key_exists('host', $url)
            ? $url['host']
            : $url['path'];
        $proto = (array_key_exists('scheme', $url)
                ? $url['scheme']
                : 'https')
            . '://';

        if(!empty($params['port'])) {
            $host .= ':'.$params['port'];
        }

        $cred = empty($params['user'])
            ? ''
            : ((empty($params['password']) ? $params['user'] : $params['user'].':'.$params['password']).'@');

        return $proto.$cred.$host.htmlspecialchars_decode($path);
    }
}