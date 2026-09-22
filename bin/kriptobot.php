#!/usr/bin/env php
<?php
/**
 * kriptobot — CLI Trading Bot Manager
 * 
 * Usage: ./bin/kriptobot <command> [options]
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Config\Config;

// ── Color helpers ──────────────────────────────────────────
function green(string $s): string  { return "\033[32m{$s}\033[0m"; }
function red(string $s): string    { return "\033[31m{$s}\033[0m"; }
function yellow(string $s): string { return "\033[33m{$s}\033[0m"; }
function cyan(string $s): string   { return "\033[36m{$s}\033[0m"; }
function bold(string $s): string   { return "\033[1m{$s}\033[0m"; }
function dim(string $s): string    { return "\033[2m{$s}\033[0m"; }

// ── Helpers ────────────────────────────────────────────────
function json_pretty(string $json): string {
    $d = json_decode($json, true);
    return is_array($d) ? json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $json;
}

function prompt(string $q, string $default = ''): string {
    $d = $default !== '' ? " [$default]" : '';
    echo cyan("  ? ") . $q . $d . ": ";
    $answer = trim(fgets(STDIN));
    return $answer !== '' ? $answer : $default;
}

function prompt_bool(string $q, bool $default = true): bool {
    $yn = $default ? 'Y/n' : 'y/N';
    $a = strtolower(prompt($q, $default ? 'y' : 'n'));
    return $a === 'y' || $a === 'yes';
}

// ── Banner ─────────────────────────────────────────────────
function banner(): void {
    echo bold(cyan("
   ╔══════════════════════════════════════╗
   ║    🚀 FIXZY KRIPTOBOT CLI v2.0     ║
   ║   Single-User • SQLite • Testnet   ║
   ╚══════════════════════════════════════╝
"));
}

// ── Commands ───────────────────────────────────────────────
function cmd_status(): void {
    $conn = Database::getConnection();
    $userId = Database::USER_ID;

    $botCount = $conn->executeQuery("SELECT COUNT(*) FROM bots")->fetchOne();
    $enabledCount = $conn->executeQuery("SELECT COUNT(*) FROM bots WHERE status = 1")->fetchOne();
    $activeDeals = $conn->executeQuery(
        "SELECT COUNT(*) FROM bots WHERE status = 1 AND runtime_state NOT LIKE '%\"status\":\"IDLE\"%'"
    )->fetchOne();

    echo bold("─── System Status ───") . "\n";
    echo "  Env:      " . yellow(Config::get('APP_ENV')) . "\n";
    echo "  Mode:     " . yellow('TESTNET (Binance)') . "\n";
    echo "  Database: " . green('SQLite OK') . "\n";
    echo "  Bots:     {$botCount} total, {$enabledCount} enabled, {$activeDeals} active\n";

    // Telegram
    $tg = Config::get('TELEGRAM_BOT_TOKEN');
    echo "  Telegram: " . ($tg ? green('configured') : red('not set')) . "\n";

    // AI (OpenAI-compatible provider: AI_API_KEY / AI_BASE_URL / AI_MODEL)
    $aiCfg = Config::aiConfig();
    echo "  AI:       " . ($aiCfg['api_key'] ? green('enabled (' . $aiCfg['model'] . ')') : yellow('disabled')) . "\n";
}

function cmd_bots(): void {
    $conn = Database::getConnection();
    $bots = $conn->executeQuery(
        "SELECT id, coin_pair, allocated_capital, status, runtime_state FROM bots ORDER BY id"
    )->fetchAllAssociative();

    if (empty($bots)) {
        echo yellow("No bots configured.\n");
        echo "Run: " . cyan("./bin/kriptobot bot:create") . "\n";
        return;
    }

    echo bold("─── Bots ───") . "\n\n";
    foreach ($bots as $b) {
        $state = json_decode($b['runtime_state'], true) ?: [];
        $status = $state['status'] ?? 'IDLE';
        $pnl = isset($state['live_pnl_percent']) ? number_format($state['live_pnl_percent'], 2) . '%' : '-';
        $enabled = $b['status'] ? green('ON') : red('OFF');

        $color = ($pnl !== '-' && (float)$state['live_pnl_percent'] >= 0) ? 'green' : 'red';
        $pnlColored = ($pnl !== '-') ? ($color === 'green' ? green($pnl) : red($pnl)) : yellow($pnl);

        printf("  [%s] Bot #%d  %s\n", $enabled, $b['id'], bold($b['coin_pair']));
        printf("       Capital: \$%s  Status: %s  PNL: %s\n",
            number_format($b['allocated_capital'], 2), cyan($status), $pnlColored);
        echo "\n";
    }
}

function cmd_bot_create(): void {
    banner();
    echo bold("─── Create New Bot ───") . "\n\n";

    $pairs = prompt("Coin pairs (comma separated)", "BTC/USDT");
    $capital = (float)prompt("Allocated capital (USDT)", "100");
    $maxDeals = (int)prompt("Max active deals", "1");
    $targetProfit = (float)prompt("Target profit %", "2.0");
    $maxDca = (int)prompt("Max DCA steps", "3");
    $dropTrigger = (float)prompt("Price drop trigger %", "5.0");

    echo "\n" . yellow("Creating bot...") . "\n";

    $config = [
        'general' => [
            'name' => 'CLI Bot',
            'exchange' => 'binance',
            'pair_strategy' => 'custom_list',
            'custom_pairs' => $pairs,
            'capital' => $capital,
            'max_active_deals' => $maxDeals,
        ],
        'base_order' => [
            'order_type' => 'market',
            'cooldown_seconds' => 3600,
            'trailing_enabled' => false,
            'conditions' => [['type' => 'start_asap', 'value' => '']],
        ],
        'dca' => [
            'max_steps' => $maxDca,
            'price_drop_trigger' => $dropTrigger,
            'volume_scale' => 1.5,
            'step_scale' => 1.0,
            'conditions' => [],
            'trailing_enabled' => false,
        ],
        'risk_management' => [
            'tp_type' => 'average_price',
            'target_profit' => $targetProfit,
            'trailing_tp_enabled' => false,
            'cut_loss_enabled' => true,
            'cut_loss_percent' => 15,
            'min_guard_enabled' => true,
            'min_guard_percent' => 0.3,
            'sell_conditions' => [],
        ],
    ];

    $state = [
        'status' => 'IDLE',
        'current_holdings' => 0.0,
        'average_entry_price' => 0.0,
        'live_pnl_percent' => 0.0,
        'dca_current_step' => 0,
        'latest_tv_signal' => '',
        'latest_external_signal' => '',
        'latest_ai_sentiment' => '',
    ];

    $conn = Database::getConnection();
    $conn->executeStatement(
        "INSERT INTO bots (user_id, coin_pair, allocated_capital, status, configuration, runtime_state) VALUES (?, ?, ?, 0, ?, ?)",
        [Database::USER_ID, $pairs, $capital, json_encode($config), json_encode($state)]
    );

    $id = $conn->lastInsertId();
    echo green("✓ Bot #{$id} created!") . "\n";
    echo "  Run " . cyan("./bin/kriptobot bot:activate {$id}") . " to enable.\n";
}

function cmd_bot_activate(int $botId): void {
    $conn = Database::getConnection();
    $bot = $conn->executeQuery("SELECT id, status FROM bots WHERE id = ?", [$botId])->fetchAssociative();

    if (!$bot) { echo red("Bot #{$botId} not found.\n"); return; }

    $newStatus = $bot['status'] ? 0 : 1;
    $label = $newStatus ? 'enabled' : 'disabled';
    $conn->executeStatement("UPDATE bots SET status = ? WHERE id = ?", [$newStatus, $botId]);
    echo green("✓ Bot #{$botId} {$label}.\n");
}

function cmd_bot_edit(int $botId): void {
    $conn = Database::getConnection();
    $bot = $conn->executeQuery("SELECT id, coin_pair, allocated_capital, status, configuration FROM bots WHERE id = ?", [$botId])->fetchAssociative();
    if (!$bot) { echo red("Bot #{$botId} not found.\n"); return; }

    $config = json_decode($bot['configuration'], true) ?: [];

    echo bold("─── Edit Bot #{$botId} ───") . "\n";
    echo "  Leave blank to keep current value.\n\n";

    $pairs = prompt("Coin pairs", $bot['coin_pair']);
    $capital = prompt("Capital (USDT)", (string)$bot['allocated_capital']);
    $tp = prompt("Take profit %", (string)($config['risk_management']['target_profit'] ?? 2));
    $sl = prompt("Stop loss %", (string)($config['risk_management']['cut_loss_percent'] ?? 15));
    $dca = prompt("Max DCA steps", (string)($config['dca']['max_steps'] ?? 3));
    $dcaDrop = prompt("DCA price drop trigger %", (string)($config['dca']['price_drop_trigger'] ?? 5));

    $config['general']['custom_pairs'] = $pairs;
    $config['general']['capital'] = (float)$capital;
    $config['risk_management']['target_profit'] = (float)$tp;
    $config['risk_management']['cut_loss_percent'] = (float)$sl;
    $config['dca']['max_steps'] = (int)$dca;
    $config['dca']['price_drop_trigger'] = (float)$dcaDrop;

    $conn->executeStatement(
        "UPDATE bots SET coin_pair = ?, allocated_capital = ?, configuration = ? WHERE id = ?",
        [$pairs, (float)$capital, json_encode($config), $botId]
    );

    echo green("✓ Bot #{$botId} updated.") . "\n";
}

function cmd_bot_delete(int $botId): void {
    $conn = Database::getConnection();
    $bot = $conn->executeQuery("SELECT id, coin_pair FROM bots WHERE id = ?", [$botId])->fetchAssociative();
    if (!$bot) { echo red("Bot #{$botId} not found.\n"); return; }

    $confirm = prompt_bool("Delete Bot #{$botId} ({$bot['coin_pair']})?");
    if (!$confirm) { echo yellow("Cancelled.\n"); return; }

    $conn->executeStatement("DELETE FROM bots WHERE id = ?", [$botId]);
    echo green("✓ Bot #{$botId} deleted.\n");
}

function cmd_daemon_run(): void {
    echo bold("─── Starting Daemon ───") . "\n\n";

    $script = __DIR__ . '/bot_daemon.php';
    $wrapper = __DIR__ . '/php_sqlite.sh';

    if (!file_exists($wrapper)) {
        echo red("Wrapper php_sqlite.sh not found. Run: php {$script}\n");
        return;
    }

    echo yellow("Running: {$wrapper} {$script}") . "\n\n";
    passthru("{$wrapper} {$script} 2>&1", $exitCode);
    echo "\n" . ($exitCode === 0 ? green("Daemon finished.") : red("Daemon exited with code {$exitCode}.")) . "\n";
}

function cmd_setup(): void {
    banner();
    echo bold("─── First-Run Setup ───") . "\n\n";

    $setup = new \Fixzy\Kriptobot\Service\SetupService();

    if (!$setup->setupRequired()) {
        echo yellow("Setup already completed. Admin account has a password set.\n");
        echo "Use " . cyan("password:reset") . " to change it, or edit settings via the Web UI.\n";
        return;
    }

    echo dim("This wizard configures your admin account and (optionally) Binance Testnet keys.\n\n");

    // 1. Master key auto-generation
    $mk = $setup->ensureMasterKey();
    if ($mk['error'] !== '') {
        echo red("Cannot prepare encryption key: {$mk['error']}\n");
        return;
    }
    echo ($mk['created'] ? green("✓ AES-256 master key generated and saved to .env") : dim("• AES master key already present")) . "\n";

    // 2. Admin credentials
    $email = prompt('Admin email', 'admin@kriptobot.local');
    echo "  Password (min 8 chars): ";
    $pass = trim((string) (fgets(STDIN) ?: ''));
    if (strlen($pass) < 8) {
        echo red("Password too short (minimum 8 characters). Aborted.\n");
        return;
    }
    echo "  Confirm password: ";
    $pass2 = trim((string) (fgets(STDIN) ?: ''));
    if ($pass !== $pass2) {
        echo red("Passwords do not match. Aborted.\n");
        return;
    }

    // 3. Testnet keys (strongly recommended, skippable)
    echo "\n" . bold("Binance Testnet keys") . dim(" (strongly recommended — the bot can't trade without them)") . "\n";
    echo dim("Get free keys at https://testnet.binance.vision/ — press Enter to skip.\n");
    $key = trim(prompt('Testnet API Key', ''));
    $secret = '';
    if ($key !== '') {
        echo "  Testnet API Secret: ";
        $secret = trim((string) (fgets(STDIN) ?: ''));
        if ($secret === '') {
            echo red("API secret required when a key is provided. Aborted.\n");
            return;
        }
    }

    try {
        $setup->completeSetup($email, $pass, $pass2, $key, $secret);
    } catch (\Throwable $e) {
        echo red("Setup failed: " . $e->getMessage() . "\n");
        return;
    }

    echo green("✓ Setup complete!\n\n");
    echo "  Admin:  {$email}\n";
    echo "  Keys:   " . ($key !== '' ? green('testnet keys saved (encrypted)') : yellow('skipped — add later via Web UI Settings → Environment')) . "\n\n";
    echo "Next: " . cyan("bot:create") . " to create your first bot, or open the Web UI.\n";
}

function cmd_password_reset(string $email = ''): void {
    $conn = Database::getConnection();
    if ($email === '') {
        $email = prompt('Account email', Config::get('DEV_ADMIN_EMAIL', 'admin@kriptobot.local'));
    }
    $email = trim(strtolower($email));

    $user = $conn->fetchAssociative("SELECT id, email FROM users WHERE email = ?", [$email]);
    if (!$user) {
        echo red("No user found with email: {$email}\n");
        return;
    }

    echo bold("Reset password for {$email}\n");
    echo "  New password (min 8 chars): ";
    $pass = trim((string) (fgets(STDIN) ?: ''));
    if (strlen($pass) < 8) {
        echo red("Password too short (minimum 8 characters). Aborted.\n");
        return;
    }
    echo "  Confirm password: ";
    $pass2 = trim((string) (fgets(STDIN) ?: ''));
    if ($pass !== $pass2) {
        echo red("Passwords do not match. Aborted.\n");
        return;
    }

    $conn->executeStatement(
        "UPDATE users SET password_hash = ? WHERE id = ?",
        [password_hash($pass, PASSWORD_BCRYPT), (int)$user['id']]
    );
    echo green("✓ Password updated. Existing web sessions remain valid until logout/timeout.\n");
}

function cmd_keys_show(): void {
    $conn = Database::getConnection();
    $user = $conn->executeQuery(
        "SELECT id, email, is_demo_mode, testnet_api_key FROM users WHERE id = ?",
        [Database::USER_ID]
    )->fetchAssociative();

    $keys = $conn->executeQuery(
        "SELECT exchange_name, api_key FROM user_api_keys WHERE user_id = ?",
        [Database::USER_ID]
    )->fetchAllAssociative();

    echo bold("─── User ───") . "\n";
    echo "  Email:    {$user['email']}\n";
    echo "  Demo:     " . ($user['is_demo_mode'] ? green('YES (testnet)') : red('NO')) . "\n";
    echo "  Testnet:  " . ($user['testnet_api_key'] ? green('SET') : red('EMPTY')) . "\n\n";

    echo bold("─── API Keys ───") . "\n";
    foreach ($keys as $k) {
        $masked = substr($k['api_key'], 0, 8) . '...' . substr($k['api_key'], -4);
        echo "  {$k['exchange_name']}: {$masked}\n";
    }
    if (empty($keys)) echo yellow("  No API keys configured.\n");
}

function cmd_market_scan(): void {
    $conn = Database::getConnection();
    $user = $conn->executeQuery("SELECT testnet_api_key, testnet_api_secret_encrypted FROM users WHERE id=1")->fetchAssociative();
    
    $key = $user['testnet_api_key'];
    $secretEnc = $user['testnet_api_secret_encrypted'];
    
    $masterKey = Config::get('AES_MASTER_KEY');
    try {
        $enc = new \Fixzy\Kriptobot\Security\EncryptionService($masterKey);
        $secret = $enc->decrypt($secretEnc);
    } catch (\Exception $e) {
        $secret = base64_decode($secretEnc);
    }

    echo bold("─── Market Scan (Binance Testnet) ───") . "\n\n";

    try {
        $exchange = new \Fixzy\Kriptobot\Trading\ExchangeService('binance', $key, $secret, true);
        $tickers = $exchange->getExchange()->fetch_tickers();
        
        $usdtPairs = [];
        foreach ($tickers as $sym => $t) {
            if (str_ends_with($sym, '/USDT') && isset($t['quoteVolume'])) {
                $usdtPairs[$sym] = $t;
            }
        }
        
        uasort($usdtPairs, fn($a, $b) => ($b['quoteVolume'] ?? 0) <=> ($a['quoteVolume'] ?? 0));
        $top = array_slice($usdtPairs, 0, 20);

        printf("  %-14s %12s %12s %8s\n", "Pair", "Price", "24h Vol", "Change");
        echo dim(str_repeat('─', 55)) . "\n";
        
        foreach ($top as $sym => $t) {
            $price = '$' . number_format($t['last'] ?? 0, 2);
            $vol = $t['quoteVolume'] ?? 0;
            $volStr = $vol > 1_000_000 ? round($vol/1_000_000, 1).'M' : round($vol/1000, 1).'K';
            $change = $t['percentage'] ?? 0;
            $changeStr = sprintf('%+6.2f%%', $change);
            $changeColored = $change >= 0 ? green($changeStr) : red($changeStr);

            printf("  %-14s %12s %12s %8s\n", $sym, $price, $volStr, $changeColored);
        }
        
        echo "\n" . dim("Total USDT pairs: " . count($usdtPairs)) . "\n";
        echo green("✓ Market scan complete.") . "\n";
        
    } catch (\Exception $e) {
        echo red("ERROR: " . $e->getMessage()) . "\n";
    }
}

// ── Router ─────────────────────────────────────────────────
$command = $argv[1] ?? 'status';
$arg2 = $argv[2] ?? null;

switch ($command) {
    case 'status':
        banner();
        cmd_status();
        break;

    case 'bots':
    case 'bot:list':
        cmd_bots();
        break;

    case 'bot:create':
        cmd_bot_create();
        break;

    case 'bot:create-full':
        $script = realpath(__DIR__ . '/bot-create-full');
        passthru("php {$script} 2>&1");
        break;

    case 'bot:activate':
        cmd_bot_activate((int)$arg2);
        break;

    case 'bot:edit':
        cmd_bot_edit((int)$arg2);
        break;

    case 'bot:edit-full':
        $script = realpath(__DIR__ . '/bot-edit-full');
        passthru("php {$script} " . escapeshellarg($arg2) . " 2>&1");
        break;

    case 'bot:delete':
        cmd_bot_delete((int)$arg2);
        break;

    case 'daemon:run':
        cmd_daemon_run();
        break;

    case 'daemon:cron':
        echo bold("─── Cron Setup ───") . "\n\n";
        echo "On a VPS/dedicated server, prefer the systemd unit (bin/kriptobot-daemon.service).\n\n";
        echo "On shared/cPanel hosting, cron is the only scheduler. Use SINGLE-TICK mode\n";
        echo "(--once) so each cron run does exactly one tick and exits (no overlap,\n";
        echo "guarded by flock). Add this line to crontab (crontab -e):\n\n";
        echo yellow("  * * * * * " . realpath(__DIR__) . "/php_sqlite.sh " . realpath(__DIR__) . "/bot_daemon.php --once >> " . dirname(__DIR__) . "/storage/logs/cron.log 2>&1") . "\n\n";
        echo "Note: cron granularity is 1 minute, so active-deal ticks run every minute\n";
        echo "instead of every 10s. Fine for DCA/recovery logic; slightly slower exits.\n\n";
        break;

    case 'dashboard':
    case 'tui':
        $tui = realpath(__DIR__ . '/kriptobot-tui');
        $php = realpath(__DIR__ . '/php_sqlite.sh');
        passthru("{$php} {$tui} 2>&1");
        break;

    case 'market:scan':
        cmd_market_scan();
        break;

    case 'keys':
    case 'keys:show':
        cmd_keys_show();
        break;

    case 'setup':
        cmd_setup();
        break;

    case 'password:reset':
        cmd_password_reset((string)($arg2 ?? ''));
        break;

    case 'help':
    default:
        banner();
        echo bold("Commands:") . "\n";
        echo "  " . cyan("status") . "           Show system status\n";
        echo "  " . cyan("bots") . "             List all bots\n";
        echo "  " . cyan("bot:create") . "       Quick create bot (interactive)\n";
        echo "  " . cyan("bot:create-full") . "  Create bot via JSON editor\n";
        echo "  " . cyan("bot:activate <id>") . " Toggle bot on/off\n";
        echo "  " . cyan("bot:edit <id>") . "     Quick edit settings\n";
        echo "  " . cyan("bot:edit-full <id>") . " Edit ALL config via editor\n";
        echo "  " . cyan("bot:delete <id>") . "  Delete a bot\n";
        echo "  " . cyan("daemon:run") . "       Run trading daemon once\n";
        echo "  " . cyan("daemon:cron") . "     Show cron setup\n";
        echo "  " . cyan("dashboard") . "        Live TUI dashboard (Ctrl+C to quit)\n";
        echo "  " . cyan("market:scan") . "      Scan top 20 pairs on Binance testnet\n";
        echo "  " . cyan("keys:show") . "        Show API keys status\n";
        echo "  " . cyan("setup") . "           First-run setup wizard (admin account + keys)\n";
        echo "  " . cyan("password:reset") . "  Reset admin password\n";
        echo "  " . cyan("help") . "             This help\n";
        break;
}
