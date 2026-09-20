<?php

namespace Fixzy\Kriptobot\Agent\Tools;

class ToolResult
{
    public bool $success;
    public mixed $data;
    public ?string $error;

    public function __construct(bool $success, mixed $data = null, ?string $error = null)
    {
        $this->success = $success;
        $this->data    = $data;
        $this->error   = $error;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data'    => $this->data,
            'error'   => $this->error,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function ok(mixed $data): self
    {
        return new self(true, $data);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, $error);
    }
}
