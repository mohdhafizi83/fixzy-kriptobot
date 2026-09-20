<?php
namespace Fixzy\Kriptobot\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;

class Database
{
    private static ?Connection $connection = null;
    public const USER_ID = 1;

    public static function getConnection(): Connection
    {
        if (self::$connection === null) {
            $path = __DIR__ . '/../../database/kriptobot.sqlite';
            self::$connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path'   => realpath($path) ?: $path,
            ]);
        }
        return self::$connection;
    }

    public static function getUserId(): int
    {
        return self::USER_ID;
    }
}
