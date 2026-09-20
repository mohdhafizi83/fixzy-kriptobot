# Plan: Refactor Backtesting to Use the Real Kriptobot Engine

**Status:** Not Started  
**Planned Date:** 2026-05-23  
**Objective:** Replace the dummy logic in BacktestEngine with the real engine so that backtesting not only tests strategies, but also catches bugs in the same engine code used by live trading.

---

## Files Involved

| # | File | Action |
|---|------|----------|
| 1 | `src/Backtest/BacktestEngine.php` | Major REFACTOR — replace dummy logic with the real engine |
| 2 | `public/api/backtest_run.php` | UPDATE — inject new dependencies into BacktestEngine |
| 3 | `src/Trading/OrderManager.php` | UPDATE — add `calculateBaseOrderSize()` method |
| 4 | `src/Backtest/BinanceDataIngester.php` | NO changes |
| 5 | `public/settings.php` | NO changes |

---

## Step 1: Add Dependency Injection to BacktestEngine

Add properties and modify the constructor:
- `private Connection $db`
- `private AuditLogger $auditLogger`
- `private ExecutionGuardService $executionGuard`

`backtest_run.php` instantiates all dependencies and passes them to `BacktestEngine`.

---

## Step 2: Replace evaluateStartConditions() — Use BaseOrderConditionEngine

**Old code** (lines 319-372): Builds Rules manually with its own evaluation.

**New code:** Use the same `BaseOrderConditionEngine` as the live daemon:
```php
$baseOrderEngine = new BaseOrderConditionEngine(
    $config['base_order'],      // same format as live
    $runtimeState,              // current state (webhook/AI signals = empty)
    $candles                    // OHLCV data from the selected timeframe
);
$result = $baseOrderEngine->evaluate();
$ruleResults = $baseOrderEngine->getLastRuleResults();
$auditLogger->logRuleEval($botId, $userId, 'BACKTEST_BASE', $ruleResults);
```

The `tv_webhook`, `external_signal`, `news_sentiment` signals are always empty in backtests.

---

## Step 3: Replace the DCA Block — Use DcaConditionEngine

**Old code** (lines 234-268): Only computes geometric price-drop, no custom conditions.

**New code:** Use the full `DcaConditionEngine`:
```php
$dcaEngine = new DcaConditionEngine(
    $config['dca'], $entryPrice, $currentPrice, $dcaStep,
    '', '', $candles           // webhook/ai = empty
);
if ($dcaEngine->evaluate()) {
    $dcaEvalDetails = $dcaEngine->getLastEvaluationDetails();
    $dcaRuleResults = $dcaEngine->getLastRuleResults();
    $auditLogger->log(..., 'DCA_EVAL', ...);
    if (!empty($dcaRuleResults)) {
        $auditLogger->logRuleEval(..., 'BACKTEST_DCA', $dcaRuleResults);
    }
}
```

Effect: Custom DCA conditions (RSI, Bollinger, QFL, trailing DCA) now work.

---

## Step 4: Add ExecutionGuardService Before Opening a Deal

```php
$cooldown = $config['base_order']['cooldown_seconds'] ?? 7200;
$maxDeals = (int)($config['general']['max_active_deals'] ?? 1);

if (!$executionGuard->canBuy($userId, $symbol, $cooldown, $maxDeals, '', $botId)) {
    $rejectionCtx = $executionGuard->getLastRejectionContext();
    $auditLogger->log($botId, $userId, 'REJECTION', ...);
    continue;
}
```

Note: Cooldown in backtests must use simulated timestamps, not `trade_logs` queries. Add an override method.

---

## Step 5: Use OrderManager for Order Size Calculation

Add a new method in `OrderManager`:
```php
public function calculateBaseOrderSize(float $totalDealBudget, int $maxDcaSteps, float $volumeScale): float
```
Both `bot_daemon.php` and `BacktestEngine` call the same method.

---

## Step 6: Add Audit Logging

Every action is recorded to `audit_logs`:
- `BACKTEST_BUY` / `BACKTEST_SELL` via `logTrade()`
- `RULE_EVAL` via `logRuleEval()`
- `REJECTION`, `DCA_EVAL`, `ERROR` via `log()`
- `STATE_CHANGE` for IDLE/TRAILING/ACTIVE transitions

---

## Step 7: Update backtest_run.php

Inject dependencies:
```php
$conn = Database::getConnection();
$auditLogger = new AuditLogger($conn);
$executionGuard = new ExecutionGuardService($conn);
$engine = new BacktestEngine($ingester, $auditLogger, $executionGuard, $conn);
$result = $engine->run($config);
```

---

## Step 8: Handle Edge Cases

| Edge Case | Handling |
|-----------|--------|
| Engine requires `$runtimeState` | Send an empty array for signal fields |
| DcaConditionEngine requires webhook/AI | Send empty strings |
| ExecutionGuard cooldown queries `trade_logs` | Add `setSimulatedCooldown()` method to bypass the DB |
| Backtest `$userId` | From session |
| Backtest `$botId` | From config (sent by the UI) |

---

## Impact Summary

| What | Before | After |
|-----|---------|---------|
| **Base order conditions** | Manual `evaluateStartConditions()` | `BaseOrderConditionEngine::evaluate()` |
| **DCA conditions** | Price-drop math only | `DcaConditionEngine::evaluate()` + custom conditions + trailing DCA |
| **Execution guard** | None | `ExecutionGuardService::canBuy()` |
| **Order size calculation** | Custom math | Shared method `OrderManager::calculateBaseOrderSize()` |
| **Audit logging** | None | `AuditLogger` — every action is recorded |
| **Rule evaluation tracing** | None | Every rule is recorded with full indicator values |
