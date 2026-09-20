CREATE TABLE `backtest_ingestion_log` (

  `id` INTEGER NOT NULL,
  `symbol` TEXT NOT NULL,
  `date` TEXT NOT NULL,
  `candles_count` INTEGER NOT NULL DEFAULT 0,
  `status` TEXT NOT NULL DEFAULT 'success',
  `message` text DEFAULT NULL,
  `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);
CREATE TABLE `bots` (

  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `coin_pair` text NOT NULL,
  `allocated_capital` REAL NOT NULL DEFAULT 0,
  `status` INTEGER NOT NULL DEFAULT 0,
  `configuration` TEXT NOT NULL,
  `runtime_state` TEXT NOT NULL,
  `parent_id` INTEGER DEFAULT NULL, is_agent_managed INTEGER NOT NULL DEFAULT 0, agent_decision_id INTEGER DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `FK_71BFF0FDA76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE

);
CREATE TABLE `historical_ohlcv` (

  `id` INTEGER NOT NULL,
  `symbol` TEXT NOT NULL,
  `open_time` INTEGER NOT NULL,
  `open` REAL NOT NULL,
  `high` REAL NOT NULL,
  `low` REAL NOT NULL,
  `close` REAL NOT NULL,
  `volume` REAL NOT NULL,
  `close_time` INTEGER NOT NULL,
  PRIMARY KEY (`id`)
);
CREATE TABLE `trade_logs` (

  `id` INTEGER NOT NULL,
  `bot_id` INTEGER NOT NULL,
  `action` TEXT NOT NULL,
  `price` REAL DEFAULT NULL,
  `amount` REAL DEFAULT NULL,
  `audit_data` TEXT NOT NULL,
  `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `FK_9274B1A792C1C487` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE

);
CREATE TABLE `user_api_keys` (

  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `exchange_name` TEXT NOT NULL,
  `api_key` TEXT NOT NULL,
  `api_secret_encrypted` text NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `FK_75F4D61FA76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE

);
CREATE TABLE `users` (

  `id` INTEGER NOT NULL,
  `email` TEXT NOT NULL,
  `password_hash` TEXT NOT NULL,
  `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_demo_mode` INTEGER DEFAULT 1,
  `testnet_api_key` TEXT DEFAULT NULL,
  `testnet_api_secret_encrypted` text DEFAULT NULL,
  `global_filters` text DEFAULT NULL,
  `smart_recovery_mode` INTEGER DEFAULT 1,
  `telegram_chat_id` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`)
);
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bot_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    event_summary TEXT DEFAULT NULL,
    context_data TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX `uq_symbol_date` ON `backtest_ingestion_log` (`symbol`,`date`);
CREATE INDEX `idx_symbol` ON `backtest_ingestion_log` (`symbol`);
CREATE INDEX `IDX_71BFF0FDA76ED395` ON `bots` (`user_id`);
CREATE UNIQUE INDEX `uq_symbol_time` ON `historical_ohlcv` (`symbol`,`open_time`);
CREATE INDEX `idx_symbol_time` ON `historical_ohlcv` (`symbol`,`open_time`);
CREATE INDEX `IDX_9274B1A792C1C487` ON `trade_logs` (`bot_id`);
CREATE INDEX `IDX_75F4D61FA76ED395` ON `user_api_keys` (`user_id`);
CREATE UNIQUE INDEX `UNIQ_1483A5E9E7927C74` ON `users` (`email`);
CREATE INDEX idx_audit_bot_time ON audit_logs (bot_id, created_at);
CREATE INDEX idx_audit_user_time ON audit_logs (user_id, created_at);
CREATE INDEX idx_audit_event_type ON audit_logs (event_type);
CREATE TABLE agent_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT,
    context TEXT,
    language TEXT DEFAULT 'en',
    status TEXT DEFAULT 'active',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE agent_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    role TEXT NOT NULL,
    content TEXT,
    tool_calls TEXT,
    tool_results TEXT,
    token_usage INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES agent_sessions(id) ON DELETE CASCADE
);
CREATE TABLE agent_decisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER,
    user_id INTEGER NOT NULL,
    decision_type TEXT NOT NULL,
    bot_id INTEGER,
    proposed_config TEXT,
    original_config TEXT,
    justification TEXT,
    market_snapshot TEXT,
    risk_profile TEXT,
    backtest_results TEXT,
    requiring_approval INTEGER DEFAULT 1,
    status TEXT DEFAULT 'pending_approval',
    approved_config TEXT,
    approved_by TEXT DEFAULT 'user',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL
);
CREATE TABLE agent_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    agent_enabled INTEGER DEFAULT 0,
    autonomy_mode TEXT DEFAULT 'approval_required',
    max_capital_per_bot REAL DEFAULT 1000.0,
    max_total_capital REAL DEFAULT 10000.0,
    allowed_actions TEXT DEFAULT '["analyze_market","view_data","run_backtest"]',
    telegram_notifications INTEGER DEFAULT 1,
    language_preference TEXT DEFAULT 'auto',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE price_ticks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bot_id INTEGER NOT NULL DEFAULT 1,
    price REAL NOT NULL,
    pnl_percent REAL DEFAULT 0,
    holdings REAL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TEXT DEFAULT NULL,
            revoked_at TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
CREATE TABLE feature_status (
            feature_key TEXT PRIMARY KEY,
            status TEXT NOT NULL DEFAULT 'disabled',
            reason TEXT NOT NULL DEFAULT '',
            last_checked_at TEXT DEFAULT NULL,
            last_verified_at TEXT DEFAULT NULL
        );
