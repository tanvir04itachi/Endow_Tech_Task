<?php

namespace App\Exceptions;

use Exception;

class DuplicateReservationException extends Exception
{
    protected $message = 'You already have an active reservation for this event.';
}
