<?php

namespace Fixzy\Kriptobot\Agent;

class ConfigGenerator
{
    /**
     * Build a complete bot configuration JSON from parameters.
     */
    public function buildConfig(array $params, string $name = 'AI Generated Bot'): array
    {
        $general = $params['general'] ?? [];
        $baseOrder = $params['base_order'] ?? [];
        $dca = $params['dca'] ?? [];
        $riskManagement = $params['risk_management'] ?? [];

        return [
            'general' => [
                'name'             => $general['name'] ?? $name,
                'exchange'         => $general['exchange'] ?? 'binance',
                'pair_strategy'    => $general['pair_strategy'] ?? 'single',
                'custom_pairs'     => $general['custom_pairs'] ?? '',
                'blacklist'        => $general['blacklist'] ?? '',
                'capital'          => $general['capital'] ?? 'AUTO',
                'max_active_deals' => $general['max_active_deals'] ?? 2,
                'min_required'     => $general['min_required'] ?? 325.50,
            ],
            'base_order' => [
                'order_type'         => $baseOrder['order_type'] ?? 'market',
                'cooldown_seconds'   => $baseOrder['cooldown_seconds'] ?? 7200,
                'trailing_enabled'   => $baseOrder['trailing_enabled'] ?? false,
                'trailing_deviation' => $baseOrder['trailing_deviation'] ?? 0.5,
                'conditions'         => $baseOrder['conditions'] ?? [],
            ],
            'dca' => [
                'max_steps'                => $dca['max_steps'] ?? 4,
                'price_drop_trigger'       => $dca['price_drop_trigger'] ?? 5,
                'step_scale'               => $dca['step_scale'] ?? 1.0,
                'volume_scale'             => $dca['volume_scale'] ?? 2,
                'placed_on_exchange'       => $dca['placed_on_exchange'] ?? 'no',
                'custom_conditions_enabled' => $dca['custom_conditions_enabled'] ?? false,
                'conditions'               => $dca['conditions'] ?? [],
                'trailing_enabled'         => $dca['trailing_enabled'] ?? false,
                'trailing_deviation'       => $dca['trailing_deviation'] ?? 0.4,
            ],
            'risk_management' => [
                'tp_type'              => $riskManagement['tp_type'] ?? 'average_price',
                'target_profit'        => $riskManagement['target_profit'] ?? 2,
                'trailing_tp_enabled'  => $riskManagement['trailing_tp_enabled'] ?? false,
                'trailing_tp_deviation' => $riskManagement['trailing_tp_deviation'] ?? 0.5,
                'cut_loss_enabled'     => $riskManagement['cut_loss_enabled'] ?? true,
                'cut_loss_percent'     => $riskManagement['cut_loss_percent'] ?? 20,
                'trailing_sl_enabled'  => $riskManagement['trailing_sl_enabled'] ?? false,
                'min_guard_enabled'    => $riskManagement['min_guard_enabled'] ?? false,
                'min_guard_percent'    => $riskManagement['min_guard_percent'] ?? 0.3,
                'min_guard_timeout'    => $riskManagement['min_guard_timeout'] ?? 48,
                'partial_sell_enabled' => $riskManagement['partial_sell_enabled'] ?? false,
                'partial_targets'      => $riskManagement['partial_targets'] ?? '',
                'sell_conditions'      => $riskManagement['sell_conditions'] ?? [],
            ],
        ];
    }

    /**
     * Generate a config from risk profile key and market data.
     */
    public function fromRiskProfile(string $profileKey, string $pair, array $options = []): array
    {
        $profiler = new RiskProfiler();
        $profile = $profiler->getProfile($profileKey);

        if (!$profile) {
            $profile = RiskProfiler::PROFILES['moderate'];
        }

        $entryRules = $options['entry_rules'] ?? $profile['entry_rules'];

        return $this->buildConfig([
            'general' => [
                'name'             => $options['name'] ?? $pair . ' - ' . ucfirst($profileKey),
                'pair_strategy'    => 'single',
                'custom_pairs'     => $pair,
                'capital'          => $options['capital'] ?? 'AUTO',
                'max_active_deals' => $options['max_active_deals'] ?? 2,
            ],
            'base_order' => [
                'order_type'         => 'market',
                'cooldown_seconds'   => $options['cooldown_seconds'] ?? 7200,
                'trailing_enabled'   => $options['trailing_buy_enabled'] ?? ($profileKey === 'conservative'),
                'trailing_deviation' => $options['trailing_buy_deviation'] ?? 0.5,
                'conditions'         => $entryRules,
            ],
            'dca' => [
                'max_steps'          => $profile['max_dca_steps'],
                'price_drop_trigger'  => $options['price_drop_trigger'] ?? 5,
                'step_scale'          => $profile['step_scale'],
                'volume_scale'        => $profile['volume_scale'],
                'trailing_enabled'    => $options['dca_trailing_enabled'] ?? true,
                'trailing_deviation'  => $options['dca_trailing_deviation'] ?? 0.4,
            ],
            'risk_management' => [
                'target_profit'        => $profile['target_profit'],
                'tp_type'              => 'average_price',
                'trailing_tp_enabled'  => $profile['trailing_tp'] > 0,
                'trailing_tp_deviation' => $profile['trailing_tp'],
                'cut_loss_enabled'     => $profile['cut_loss'] > 0,
                'cut_loss_percent'     => $profile['cut_loss'],
            ],
        ]);
    }

    /**
     * Compute full runtime state initial JSON.
     */
    public function initialRuntimeState(): array
    {
        return [
            'status'                 => 'IDLE',
            'current_holdings'       => 0,
            'average_entry_price'    => 0,
            'base_order_price'       => 0,
            'base_order_volume_usdt' => 0,
            'base_order_amount'      => 0,
            'dca_current_step'       => 0,
            'live_pnl_percent'       => 0,
            'total_deal_budget'      => 0,
            'trade_start_timestamp'  => null,
            'latest_tv_signal'       => '',
            'latest_external_signal' => '',
            'latest_ai_sentiment'    => '',
            'trailing_symbol'        => '',
            'trailing_low_watermark' => 0,
            'trailing_tp_watermark'  => 0,
            'trailing_sl_watermark'  => 0,
            'partial_sell_executed'  => [],
            'is_recovery_bot'        => false,
            'deficit_to_recover'     => 0,
            'recovered_so_far'       => 0,
        ];
    }
}
