<?php

namespace App\Exceptions;

use Exception;

class EventFullException extends Exception
{
    protected $message = 'This event is fully booked.';
}
