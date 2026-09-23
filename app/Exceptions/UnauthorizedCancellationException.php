<?php

namespace App\Exceptions;

use Exception;

class UnauthorizedCancellationException extends Exception
{
    protected $message = 'You are not authorized to cancel this reservation.';
}
