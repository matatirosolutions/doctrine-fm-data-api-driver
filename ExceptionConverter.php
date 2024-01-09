<?php

namespace MSDev\DoctrineFMDataAPIDriver;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

class ExceptionConverter implements ExceptionConverterInterface
{

    /**
     * @inheritDoc
     */
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        // TODO this needs to be built upon to return better exceptions
        return new DriverException($exception, $query);
    }
}
