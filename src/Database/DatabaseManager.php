<?php

namespace Fixzy\Kriptobot\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Exception;

class DatabaseManager
{
    private Connection $connection;

    public function __construct(array $config)
    {
        try {
            // Dynamic connection parameters
            $connectionParams = [
                'dbname'   => $config['DB_NAME'] ?? null,
                'user'     => $config['DB_USER'] ?? null,
                'password' => $config['DB_PASSWORD'] ?? null,
                'host'     => $config['DB_HOST'] ?? 'localhost',
                'driver'   => $config['DB_DRIVER'] ?? 'pdo_mysql',
                'path'     => $config['DB_PATH'] ?? null, // Specific to SQLite
            ];

            $this->connection = DriverManager::getConnection($connectionParams);
        } catch (Exception $e) {
            throw new Exception("Failed to connect to database: " . $e->getMessage());
        }
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}