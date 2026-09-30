<?php

namespace App\Services\Vk;

use RuntimeException;

class VkApiException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly array $error = [])
    {
        parent::__construct($message, $code);
    }
}
