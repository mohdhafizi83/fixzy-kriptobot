<?php

namespace Fixzy\Kriptobot\Http;

/**
 * ApiException — thrown by services/handlers to produce a clean JSON error.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class ApiException extends \Exception
{
    private int $status;

    /**
     * __construct.
     * @param string $message
     * @param int $status (default: 400)
     */
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    /**
     * Get Status.
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }
}
