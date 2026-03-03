<?php

namespace App\Services;

use RuntimeException;

class NotificationServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode,
    ) {
        parent::__construct($message);
    }
}
