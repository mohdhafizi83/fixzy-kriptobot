<?php

namespace Fixzy\Kriptobot\Http;

/**
 * ApiException — thrown by services/handlers to produce a clean JSON error.
 */
class ApiException extends \Exception
{
    private int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
