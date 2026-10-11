<?php

namespace Fixzy\Kriptobot\Tests\Unit;

use Fixzy\Kriptobot\Security\SessionGuard;
use PHPUnit\Framework\TestCase;

/**
 * Class SessionGuardTest — CSRF helper behaviour (fail-closed).
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class SessionGuardTest extends TestCase
{
    /**
     * Set Up.
     * @return void
     */
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    /**
     * Tear Down.
     * @return void
     */
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Test Csrf Token Generated And Stable.
     * @return void
     */
    public function testCsrfTokenGeneratedAndStable(): void
    {
        $token = SessionGuard::csrfToken();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, SessionGuard::csrfToken());
    }

    /**
     * Test Verify Csrf Fails Closed Without Token.
     * @return void
     */
    public function testVerifyCsrfFailsClosedWithoutToken(): void
    {
        $this->assertFalse(SessionGuard::verifyCsrf(''));
        $this->assertFalse(SessionGuard::verifyCsrf('anything'));
    }

    /**
     * Test Verify Csrf Rejects Wrong Token.
     * @return void
     */
    public function testVerifyCsrfRejectsWrongToken(): void
    {
        $valid = SessionGuard::csrfToken();
        $this->assertFalse(SessionGuard::verifyCsrf($valid . 'x'));
        $this->assertFalse(SessionGuard::verifyCsrf(substr($valid, 0, 63)));
        $this->assertTrue(SessionGuard::verifyCsrf($valid));
    }
}
