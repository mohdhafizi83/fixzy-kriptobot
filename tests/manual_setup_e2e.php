#!/usr/bin/env php
<?php
// E2E test: fresh-install setup flow (temp env + temp DB; production untouched).
$tmp = sys_get_temp_dir() . '/kb_setup_test_' . getmypid();
@mkdir($tmp, 0777, true);
$dbPath = $tmp . '/test.sqlite';

// Isolated .env for this test run (AES key pre-set; DB_PATH points at temp file).
file_put_contents($tmp . '/.env', implode("\n", [
    'DB_PATH=' . $dbPath,
    'AES_MASTER_KEY=base64:' . base64_encode(str_repeat('T', 32)),
]) . "\n");
putenv('KRIPTOBOT_ENV_FILE=' . $tmp . '/.env');

require_once __DIR__ . '/../vendor/autoload.php';

use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Service\SetupService;
use Fixzy\Kriptobot\Service\SettingService;

$pass = 0; $fail = 0;
function check(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else       { $fail++; echo "  FAIL  $name\n"; }
}

echo "== Fresh install flow ==\n";
$setup = new SetupService();
check('setup required on empty db', $setup->setupRequired());

$setup->completeSetup('tester@example.com', 'supersecret123', 'supersecret123',
    'TNET_KEY_123', 'TNET_SECRET_456');
check('setup no longer required after completion', !$setup->setupRequired());

$db = \Fixzy\Kriptobot\Database\Database::getConnection();
$row = $db->fetchAssociative("SELECT email, password_hash, testnet_api_key, testnet_api_secret_encrypted, is_demo_mode FROM users WHERE id=1");
check('email saved', $row['email'] === 'tester@example.com');
check('bcrypt hash stored', str_starts_with($row['password_hash'], '$2y$'));
check('testnet key saved', $row['testnet_api_key'] === 'TNET_KEY_123');
check('testnet secret encrypted (not plaintext)', $row['testnet_api_secret_encrypted'] !== 'TNET_SECRET_456' && str_starts_with($row['testnet_api_secret_encrypted'], 'v2:'));
check('demo mode on', (int)$row['is_demo_mode'] === 1);

$svc = new SettingService();
check('unset setting returns empty', $svc->get('AI_API_KEY') === '');

echo "== SettingService + Config overlay ==\n";
$svc->set('AI_API_KEY', 'sk-abc123456789');
$svc->set('AI_BASE_URL', 'https://api.example.com/v1');
check('DB value readable', $svc->get('AI_API_KEY') === 'sk-abc123456789');
check('Config overlay wins', Config::get('AI_API_KEY') === 'sk-abc123456789');
check('plain setting stored unencrypted', $svc->get('AI_BASE_URL') === 'https://api.example.com/v1');

$masked = $svc->maskedAll();
check('masked secret hides middle', $masked['AI_API_KEY']['set'] && strpos($masked['AI_API_KEY']['masked'], 'abc123456789') === false);
check('masked source = db', $masked['AI_API_KEY']['source'] === 'db');
check('unset shows none', $masked['CRYPTOPANIC_API_KEY']['set'] === false);

$svc->set('AI_API_KEY', ''); // delete
check('empty string deletes -> overlay falls back', Config::get('AI_API_KEY') === '');

echo "== Self-lock ==\n";
try {
    $setup->completeSetup('hacker@evil.com', 'whatever123', 'whatever123');
    check('second setup rejected', false);
} catch (\Throwable $e) {
    check('second setup rejected', str_contains($e->getMessage(), 'already been completed'));
}

echo "== Validation (fresh db) ==\n";
function freshSetup(string $tmp, string $name): SetupService {
    file_put_contents($tmp . '/' . $name . '.env', implode("\n", [
        'DB_PATH=' . $tmp . '/' . $name . '.sqlite',
        'AES_MASTER_KEY=base64:' . base64_encode(str_repeat('T', 32)),
    ]) . "\n");
    putenv('KRIPTOBOT_ENV_FILE=' . $tmp . '/' . $name . '.env');
    Config::reload();
    $ref = new ReflectionClass(\Fixzy\Kriptobot\Database\Database::class);
    $p = $ref->getProperty('connection'); $p->setAccessible(true); $p->setValue(null, null);
    return new SetupService();
}

$s2 = freshSetup($tmp, 'v2');
try { $s2->completeSetup('not-an-email', 'abcdefgh', 'abcdefgh'); check('invalid email rejected', false); }
catch (\Throwable $e) { check('invalid email rejected', str_contains($e->getMessage(), 'valid email')); }
try { $s2->completeSetup('ok@example.com', 'short', 'short'); check('short password rejected', false); }
catch (\Throwable $e) { check('short password rejected', str_contains($e->getMessage(), 'at least 8')); }
try { $s2->completeSetup('ok@example.com', 'abcdefgh', 'different1'); check('mismatch rejected', false); }
catch (\Throwable $e) { check('mismatch rejected', str_contains($e->getMessage(), 'do not match')); }
try { $s2->completeSetup('ok@example.com', 'abcdefgh', 'abcdefgh', 'KEY_ONLY', ''); check('key without secret rejected', false); }
catch (\Throwable $e) { check('key without secret rejected', str_contains($e->getMessage(), 'together')); }
$s2->completeSetup('ok@example.com', 'abcdefgh', 'abcdefgh');
check('skip testnet keys allowed', !$s2->setupRequired());

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
