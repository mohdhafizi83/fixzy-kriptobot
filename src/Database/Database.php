<?php
namespace Fixzy\Kriptobot\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;

/**
 * Class Database - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class Database
{
    private static ?Connection $connection = null;
    public const USER_ID = 1;

    /**
     * Get Connection.
     * @return Connection
     */
    public static function getConnection(): Connection
    {
        if (self::$connection === null) {
            $path = \Fixzy\Kriptobot\Config\Config::get('DB_PATH')
                ?: __DIR__ . '/../../database/kriptobot.sqlite';
            self::$connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path'   => realpath($path) ?: $path,
            ]);
        }
        return self::$connection;
    }

    /**
     * Get User Id.
     * @return int
     */
    public static function getUserId(): int
    {
        return self::USER_ID;
    }
}
