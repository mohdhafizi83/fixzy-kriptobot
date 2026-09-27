<?php

namespace Fixzy\Kriptobot\Tests\Unit;

use Fixzy\Kriptobot\Security\EncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * Class EncryptionServiceTest - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    /**
     * Set Up.
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new EncryptionService('base64:' . base64_encode(str_repeat('k', 32)));
    }

    /**
     * Test Encrypt Decrypt Round Trip.
     * @return void
     */
    public function testEncryptDecryptRoundTrip(): void
    {
        $plain = 'my-secret-api-secret-123';
        $encrypted = $this->service->encrypt($plain);

        $this->assertNotSame($plain, $encrypted);
        $this->assertSame($plain, $this->service->decrypt($encrypted));
    }

    /**
     * Test Encrypt Produces Unique Ciphertexts.
     * @return void
     */
    public function testEncryptProducesUniqueCiphertexts(): void
    {
        $plain = 'same-input';
        $a = $this->service->encrypt($plain);
        $b = $this->service->encrypt($plain);

        // Random IV per call must produce different ciphertexts
        $this->assertNotSame($a, $b);
        $this->assertSame($plain, $this->service->decrypt($a));
        $this->assertSame($plain, $this->service->decrypt($b));
    }

    /**
     * Test Decrypt With Wrong Key Fails.
     * @return void
     */
    public function testDecryptWithWrongKeyFails(): void
    {
        $encrypted = $this->service->encrypt('secret');
        $other = new EncryptionService('base64:' . base64_encode(str_repeat('x', 32)));

        $this->expectException(\Exception::class);
        $other->decrypt($encrypted);
    }

    /**
     * Test Rejects Non32Byte Key.
     * @return void
     */
    public function testRejectsNon32ByteKey(): void
    {
        $this->expectException(\Exception::class);
        new EncryptionService('too-short');
    }

    /**
     * Test Raw Key Also Works.
     * @return void
     */
    public function testRawKeyAlsoWorks(): void
    {
        $service = new EncryptionService(str_repeat('r', 32));
        $encrypted = $service->encrypt('hello');
        $this->assertSame('hello', $service->decrypt($encrypted));
    }

    /**
     * Test Decrypt Tampered Data Fails.
     * @return void
     */
    public function testDecryptTamperedDataFails(): void
    {
        $encrypted = $this->service->encrypt('important-data');
        $bytes = base64_decode($encrypted);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0xFF);
        $tampered = base64_encode($bytes);

        $this->expectException(\Exception::class);
        $this->service->decrypt($tampered);
    }
}
