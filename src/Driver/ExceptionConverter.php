<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Driver;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

/**
 * The driver's own exceptions (FMException and friends) deliberately don't implement
 * Doctrine's driver exception interface, so they reach application code unchanged
 * rather than being wrapped in a DriverException. This only sees anything which does.
 */
class ExceptionConverter implements ExceptionConverterInterface
{
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        return new DriverException($exception, $query);
    }
}
