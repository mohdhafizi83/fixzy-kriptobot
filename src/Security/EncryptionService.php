<?php

namespace Fixzy\Kriptobot\Security;

use Exception;

/**
 * AES-256-CBC encryption with HMAC-SHA256 authentication (encrypt-then-MAC).
 *
 * Storage format (current):  "v2:" + base64( IV || ciphertext || HMAC-SHA256 )
 * Legacy format (accepted):   base64( IV || ciphertext )          (no MAC)
 *
 * Legacy values written by older versions are still decryptable. New values are
 * always authenticated: any tampering with IV or ciphertext is detected and
 * rejected before decryption.
 */
class EncryptionService
{
    private const V2_PREFIX = 'v2:';
    private const MAC_LENGTH = 32; // SHA-256 raw output

    private string $cipher = 'aes-256-cbc';
    private string $key;
    private string $macKey;

    /**
     * @param string $masterKey 32-byte secret from .env (raw or "base64:" prefixed)
     */
    public function __construct(string $masterKey)
    {
        // Extract the key properly if using base64 format
        $this->key = str_starts_with($masterKey, 'base64:')
            ? base64_decode(substr($masterKey, 7))
            : $masterKey;

        if (strlen($this->key) !== 32) {
            throw new Exception("Master key must be exactly 32 bytes for AES-256.");
        }

        // Derive a separate MAC key so encryption and authentication keys differ
        $this->macKey = hash_hmac('sha256', 'kriptobot-enc-integrity-v2', $this->key, true);
    }

    /**
     * Encrypt plaintext (e.g. an API secret). Authenticated, random IV per call.
     */
    public function encrypt(string $plainText): string
    {
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt($plainText, $this->cipher, $this->key, 0, $iv);

        if ($encrypted === false) {
            throw new Exception("Encryption failed.");
        }

        $payload = $iv . $encrypted;
        $mac = hash_hmac('sha256', $payload, $this->macKey, true);

        return self::V2_PREFIX . base64_encode($payload . $mac);
    }

    /**
     * Decrypt back to the original text.
     *
     * @throws Exception if authentication fails, the key is wrong, or data is corrupt.
     */
    public function decrypt(string $encryptedData): string
    {
        if (str_starts_with($encryptedData, self::V2_PREFIX)) {
            return $this->decryptV2(substr($encryptedData, strlen(self::V2_PREFIX)));
        }

        // Legacy unauthenticated format (pre-v2): base64(IV || ciphertext)
        return $this->decryptLegacy($encryptedData);
    }

    private function decryptV2(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) <= self::MAC_LENGTH) {
            throw new Exception("Decryption failed: malformed payload.");
        }

        $mac = substr($data, -self::MAC_LENGTH);
        $payload = substr($data, 0, strlen($data) - self::MAC_LENGTH);

        $expected = hash_hmac('sha256', $payload, $this->macKey, true);
        if (!hash_equals($expected, $mac)) {
            throw new Exception("Decryption failed: authentication tag mismatch (data tampered or wrong key).");
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($payload, 0, $ivLength);
        $encryptedText = substr($payload, $ivLength);

        $decrypted = openssl_decrypt($encryptedText, $this->cipher, $this->key, 0, $iv);

        if ($decrypted === false) {
            throw new Exception("Decryption failed: data corrupt.");
        }

        return $decrypted;
    }

    private function decryptLegacy(string $encryptedData): string
    {
        $data = base64_decode($encryptedData);
        $ivLength = openssl_cipher_iv_length($this->cipher);

        $iv = substr($data, 0, $ivLength);
        $encryptedText = substr($data, $ivLength);

        $decrypted = openssl_decrypt($encryptedText, $this->cipher, $this->key, 0, $iv);

        if ($decrypted === false) {
            throw new Exception("Decryption failed. Key may be invalid or data corrupt.");
        }

        return $decrypted;
    }
}
