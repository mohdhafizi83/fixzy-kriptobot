# PROJECT BRIEF — AI Copilot (opt-in) untuk Fixzy Kriptobot

> Dokumen handoff untuk profile `kriptobot`. Baca habis sebelum mula.
> Disusun 2026-10-19 oleh profile `advisor` selepas audit kod live.
> Status: FASA 0 SELESAI — config section + validator + wiring daemon + toggle UI.

## 1. Visi

Tambah ciri **"AI Copilot"** sebagai pilihan (opt-in, default OFF) pada bot sedia ada:
AI menganalisa market, mengenal pasti regime, melaras parameter strategy, dan
menyediakan escalation semasa peristiwa luar biasa — tetapi keputusan trade akhir
tetapi keluar daripada rule engine deterministik yang sudah wujud.

Bukan projek baru. Bukan rewrite. Bukan Python. Semua dalam PHP, dalam repo ini.

## 2. Fakta teras yang sudah di-audit (jangan tulis semula benda ni)

- Teras trading ~3,300 baris: `bin/bot_daemon.php` (1813), `src/Trading/*`
  (DcaConditionEngine, BaseOrderConditionEngine, OrderManager, ExchangeService,
  DCACalculator, TrailingEngine, PartialSellEngine, SmartRecoveryManager, MarketScannerService).
- Rules sedia ada di `src/Trading/Rules/`: RSI, Bollinger, QFL, PriceDrop, Cooldown,
  MinimumProfitGuard, TakeProfit, AiMarket, Sentiment, TradingView, ExternalSignal,
  StartAsap + LogicalAnd/Or + `ExecutionGuardService` (blacklist, cooldown, max deals).
- `src/Intelligence/AiAnalyzer.php` (190b) — dah panggil mana-mana endpoint
  OpenAI-compatible (Guzzle). Konfigurasi `.env`: `AI_API_KEY`, `AI_BASE_URL`, `AI_MODEL`.
  Untuk local LLM: `AI_BASE_URL=http://aiserverstation:8080/v1`.
  Throw error kalau key kosong — kekalkan safeguard ini.
- `src/Agent/` — `AgentOrchestrator` (400b, chat-driven), `Approval/ApprovalManager`
  (356b: createDecision/approve/reject/modify/applyDecision), `AgentSettingsService`,
  StrategyRecommender (if/else — calon upgrade), tools MarketAnalysis/Backtesting.
- Schema (`database/schema.sql`): `bots.configuration` = TEXT JSON (tiada migrasi
  diperlukan untuk section baru), `bots.is_agent_managed`, table `agent_decisions`
  (proposed/original/approved_config, requiring_approval, status), `agent_settings`
  (agent_enabled DEFAULT 0, autonomy_mode DEFAULT 'approval_required',
  max_capital_per_bot, max_total_capital, allowed_actions).
- Backtest wujud: `src/Backtest/BacktestEngine.php` + `BinanceDataIngester.php`.
- Daemon kini berjalan sebagai SYSTEM service `kriptobot-daemon.service` (testnet).
  Jangan ganggu service lama semasa pembangunan; guna bot testnet berasingan.
- Binance spot fee ~0.1% taker (0.075% dgn BNB). Round-trip ~0.15–0.2%.

## 3. Kekangan keras (non-negotiable)

1. **PHP sahaja.** Tiada Python, tiada bahasa baru, tiada dependency berat baru.
2. **Default OFF & backward compatible.** Bot/config lama mesti berjalan 100%
   sama seperti hari ini. Tiada migrasi DB — semua option baru dalam JSON
   `bots.configuration` dengan default selamat.
3. **Web UI komponen utama** (ikut AGENTS.md). Semua toggle boleh dikonfigur
   melalui UI; CLI/TUI hanya power-user.
4. **AI optional, guardrails TIDAK.** Risk layer (seksyen 6) sentiasa ON untuk
   semua bot, nilai default munasabah, tanpa mengira AI hidup/mati.
5. **Spot sahaja.** Tiada futures/margin/leverage.
6. **Testnet dahulu.** Real money hanya selepas gerbang fasa 4 diluluskan manusia.
7. **AI tidak di hot path.** Loop 1-minit tidak membuat sebarang LLM call.
   LLM hanya pada candle timeframe keputusan dan semasa escalation.
8. Fail-safe = HOLD. Jika output AI tidak sah / tamat tempoh / LLM mati:
   kekalkan parameter sebelumnya, JANGAN trade baru, JANGAN panic-sell.

## 4. Arsitektur: 3 lapisan + tangga kepercayaan

```
L1  AI loop perlahan  (setiap candle TF keputusan, cth 1h)
      input : data pack cirian (bawah)
      output: regime, bias, params, confidence  → divalidasi → di-cache
L2  Rule loop laju    (setiap 1 minit, daemon sedia ada)
      rule sedia ada fire deterministik; parameter dibaca dari output L1
L3  Escalation peristiwa (event-driven)
      flash move / drawdown had / DCA steps habis → pause bot → tanya AI
      → jawapan lulus risk layer → tindakan ATAU minta approval manusia
```

Tangga kepercayaan per bot (`configuration.ai_copilot.trust_level`):

| Tahap | Kelakuan |
|---|---|
| `off` (default) | Macam hari ini. Tiada LLM call langsung. |
| `shadow` | L1 berjalan, keputusan DICTAT sahaja (log + halaman perbandingan "AI akan buat X, bot buat Y"). Tiada tindakan. |
| `advisor` | L1 melaras parameter; L2 eksekut. Escalation L3 masih off. |
| `active` | Penuh: L1+L2+L3. Mod `approval_required` (dari `agent_settings`) boleh digabung: tindakan L3 difailkan ke `agent_decisions`, manusia luluskan. |

### Data pack untuk L1 (JSON ~1–2KB, cirian — BUKAN candle mentah)

- Cirian multi-TF (5m/1h/4h/1d, N candle terakhir): RSI(14), Bollinger position,
  EMA(20/50) relationship, ATR(14), vol realized 30h, jarak high/low 30h.
- Posisi terbuka: entry, avg price, PnL%, DCA step guna, jarak ke TP/SL.
- Akaun: baki free, % exposure.
- 10 trade terakhir + hasil ringkas.
- Keputusan L1 sebelumnya (konsistensi regime) + umur cache.
- Fear & Greed index (alternative.me, free).

### Skema output L1 (wajib validasi; gagal → fail-safe HOLD)

```json
{
  "regime": "trending_up | trending_down | sideways | choppy",
  "bias": "bullish | neutral | bearish",
  "params": {
    "dca_drop_pct": 2.0, "max_dca_steps": 5,
    "tp_pct": 3.0, "trail_pct": 1.5, "entry_aggressiveness": "normal"
  },
  "confidence": 0.0,
  "reason": "string pendek"
}
```

Validator: enum check, julat nombor (cth `dca_drop_pct` 0.5–10, `max_dca_steps` 0–8),
confidence < ambang → kekalkan parameter sebelumnya.

## 5. Escalation peristiwa (L3) — pencetus minimum

- Flash move: harga bergerak > X% dalam Y minit (default X=3, Y=10, boleh laras).
- Drawdown bot melepasi had (default -5% daripada allocated capital).
- DCA steps habis tetapi harga terus jatuh: soalan fokus "hold / cut / tunggu".
- Signal L1 tamat tempoh (LLM mati) → bot freeze entry baru, hold posisi, alert.

Setiap escalation: log penuh (snapshot + soalan + jawapan + tindakan) ke
`agent_decisions` supaya audit trail sama rata dengan approval flow.

## 6. Risk layer keras (sentiasa ON, AI tak boleh langkau)

- Kill switch: rugi bersih harian keseluruhan > had (default -2% modal) → stop
  entry baru 24j + alert Telegram.
- Max exposure per coin + keseluruhan (default ikut `agent_settings`).
- Max langkah DCA (sudah ada `dca.max_steps` — wajib ada had, default 5, 0/tiada
  had DITOLAK untuk mod AI).
- Cooldown selepas cut loss (default 4j).
- Ambang confidence minimum untuk sebarang perubahan parameter (default 0.6).
- Sanity check order: harga dalam x% dari mid, saiz dalam had, pair dalam senarai bot.

## 7. Fasa kerja (setiap fasa ada acceptance test; jangan langgar fasa)

**Fasa 0 — Asas (anggaran ±350b):** section `ai_copilot` dalam config JSON +
validator + wiring `agent_settings` ke daemon + toggle UI (bot edit page: suis +
dropdown trust level; halaman Advanced untuk parameter). Default `off`.
Terima: bot lama kelakuan tak berubah (regression test); UI boleh toggle;
tiada migrasi DB.

**Fasa 1 — Guardrails (±200b):** seksyen 6 dilaksanakan penuh untuk SEMUA bot
(termasuk yang AI off). Unit test setiap had. Terima: kill switch dicetuskan
secara manual dalam testnet → entry baru berhenti, alert keluar.

**Fasa 2 — Shadow mode (±400b):** prompt builder + data pack + panggilan L1
per candle TF keputusan + cache + log keputusan + halaman laporan perbandingan
(AI cadang vs bot buat vs hasil). Terima: 72 jam testnet shadow tanpa error;
keputusan L1 tercatat lengkap; LLM mati → daemon tak terjejas.

**Fasa 3 — Advisor mode (±300b):** injection parameter L1 ke rule engine
(rule tidak diubah; sumber parameter sahaja bertukar) + confidence gate +
backtest-gate: parameter baru hanya dipakai jika lulus backtest ringkas 30h.
Terima: A/B testnet bot sama (AI-tuned vs statik) 7 hari; laporan metrik.

**Fasa 4 — Active + escalation (±400b):** pencetus L3 + integration
`ApprovalManager` (tindakan L3 → `agent_decisions` → approval manusia) +
mod `approval_required`. Terima: setiap escalation ada audit trail penuh;
reject flow berfungsi; flash move disimulasikan di testnet ditangani.

**Gerbang real money (manusia, bukan kod):** selepas Fasa 4, keputusan manual
berdasarkan metrik hidup. Sasaran jujur: 0.05–0.15%/hari, diukur BULANAN
(return, max drawdown, profit factor) — BUKAN sasaran "$X per day".

## 8. Di luar skop (jangan buat)

Rewrite Python; futures/leverage; exchange lain; LLM per candle 1-minit;
web scraping berita/sentiment baru; redesign UI; auto-scale capital; fine-tuning model.

## 9. Rujukan fail utama

`bin/bot_daemon.php` · `src/Trading/` · `src/Trading/Rules/` ·
`src/Intelligence/AiAnalyzer.php` · `src/Agent/` (Orchestrator, Approval,
AgentSettingsService) · `src/Backtest/` · `database/schema.sql` · `AGENTS.md` ·
`.env.example` (AI_*) · `docs/ARCHITECTURE.md`

## 10. Metrik kejayaan (lapor bulanan)

- Return bersih selepas fee (%/bulan), max drawdown, profit factor, win rate.
- Bilangan keputusan AI vs berapa lulus validator; shadow-accuracy (AI betul vs bot betul).
- Kos: 0 (local LLM) — kalau guna API berbayar, kira $/keputusan sebelum hidupkan.
