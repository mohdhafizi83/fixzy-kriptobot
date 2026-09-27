<?php

namespace Fixzy\Kriptobot\Agent\Tools;

/**
 * Class ToolInterface - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
interface ToolInterface
{
    /**
     * Get Name.
     * @return string
     */
    public function getName(): string;

    /**
     * Get Description.
     * @param string $lang (default: 'en')
     * @return string
     */
    public function getDescription(string $lang = 'en'): string;

    /**
     * Get Parameters.
     * @return array
     */
    public function getParameters(): array;

    /**
     * Execute.
     * @param array $params
     * @param int $userId
     * @return ToolResult
     */
    public function execute(array $params, int $userId): ToolResult;
}
