<?php

namespace Fixzy\Kriptobot\Agent\Tools;

/**
 * Class ToolResult - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class ToolResult
{
    public bool $success;
    public mixed $data;
    public ?string $error;

    /**
     * __construct.
     * @param bool $success
     * @param mixed $data (default: null)
     * @param string|null $error (default: null)
     */
    public function __construct(bool $success, mixed $data = null, ?string $error = null)
    {
        $this->success = $success;
        $this->data    = $data;
        $this->error   = $error;
    }

    /**
     * To Array.
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data'    => $this->data,
            'error'   => $this->error,
        ];
    }

    /**
     * To Json.
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Ok.
     * @param mixed $data
     * @return self
     */
    public static function ok(mixed $data): self
    {
        return new self(true, $data);
    }

    /**
     * Fail.
     * @param string $error
     * @return self
     */
    public static function fail(string $error): self
    {
        return new self(false, null, $error);
    }
}
