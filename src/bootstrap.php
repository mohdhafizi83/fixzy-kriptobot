<?php
/**
 * bootstrap.php — shared entry point for CLI scripts and HTTP endpoints.
 *
 * Loads the autoloader + Config, and returns a small service container array:
 *   ['db' => Doctrine\DBAL\Connection]
 */
require_once __DIR__ . '/../vendor/autoload.php';
\Fixzy\Kriptobot\Config\Config::load();

return [
    'db' => \Fixzy\Kriptobot\Database\Database::getConnection(),
];
