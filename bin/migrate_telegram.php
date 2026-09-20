<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
use Fixzy\Kriptobot\Database\Database;
$conn = Database::getConnection();
try {
    $conn->executeStatement("ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(50) DEFAULT NULL");
    echo "telegram_chat_id column added successfully.";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
