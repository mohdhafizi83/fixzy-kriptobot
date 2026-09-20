<?php

namespace Fixzy\Kriptobot\Security;

use Doctrine\DBAL\Connection;
use Exception;

class AuthService
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Register a new user with BCRYPT password encryption.
     * Returns the new user ID on success.
     */
    public function register(string $email, string $password): int
    {
        $email = trim(strtolower($email));

        // Check if the email already exists
        $existingUser = $this->db->fetchAssociative("SELECT id FROM users WHERE email = ?", [$email]);
        if ($existingUser) {
            throw new Exception("This email has already been registered.");
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $this->db->insert('users', [
            'email' => $email,
            'password_hash' => $passwordHash
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Authenticate the email and password.
     * Returns the user data array on success, null on failure.
     */
    public function authenticate(string $email, string $password): ?array
    {
        $email = trim(strtolower($email));
        
        $user = $this->db->fetchAssociative("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Remove the password hash from memory before returning, for security
            unset($user['password_hash']);
            return $user;
        }

        return null;
    }
}