<?php

namespace App\Exceptions;

use Exception;

class AlreadyCancelledException extends Exception
{
    protected $message = 'This reservation is already cancelled.';
}
