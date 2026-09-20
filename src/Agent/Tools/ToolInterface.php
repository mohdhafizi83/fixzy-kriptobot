<?php

namespace Fixzy\Kriptobot\Agent\Tools;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(string $lang = 'en'): string;

    public function getParameters(): array;

    public function execute(array $params, int $userId): ToolResult;
}
