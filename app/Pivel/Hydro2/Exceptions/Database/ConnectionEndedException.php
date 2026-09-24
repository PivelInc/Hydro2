<?php

namespace Pivel\Hydro2\Exceptions\Database;

use Exception;
use Throwable;

class ConnectionEndedException extends Exception
{
    public function __construct(string $message, string|int $code=0, null|Throwable $previous=null) {
        // some code

        // make sure everything is assigned properly
        parent::__construct($message, $code, $previous);
    }
}