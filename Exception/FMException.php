<?php

namespace MSDev\DoctrineFMDataAPIDriver\Exception;

use Exception;
use Throwable;

class FMException extends Exception
{
    public function __construct($message = "", $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}