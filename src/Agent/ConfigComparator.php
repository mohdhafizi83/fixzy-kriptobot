<?php

namespace Fixzy\Kriptobot\Agent;

class ConfigComparator
{
    /**
     * Compare two bot configs and return a human-readable diff.
     */
    public function compare(array $original, array $proposed): array
    {
        $changes = [];

        $paths = [
            'general.name'                         => 'Bot Name',
            'general.pair_strategy'                => 'Pair Strategy',
            'general.custom_pairs'                 => 'Trading Pairs',
            'general.capital'                      => 'Capital',
            'general.max_active_deals'             => 'Max Active Deals',
            'base_order.order_type'                => 'Order Type',
            'base_order.cooldown_seconds'          => 'Cooldown (seconds)',
            'base_order.trailing_enabled'          => 'Trailing Buy Enabled',
            'base_order.trailing_deviation'        => 'Trailing Buy Deviation',
            'dca.max_steps'                        => 'Max DCA Steps',
            'dca.price_drop_trigger'              => 'Price Drop Trigger (%)',
            'dca.volume_scale'                     => 'DCA Volume Scale',
            'dca.step_scale'                       => 'DCA Step Scale',
            'dca.trailing_enabled'                 => 'DCA Trailing Enabled',
            'dca.trailing_deviation'               => 'DCA Trailing Deviation',
            'risk_management.tp_type'              => 'TP Type',
            'risk_management.target_profit'        => 'Target Profit (%)',
            'risk_management.trailing_tp_enabled'  => 'Trailing TP Enabled',
            'risk_management.trailing_tp_deviation'=> 'Trailing TP Deviation',
            'risk_management.cut_loss_enabled'     => 'Cut Loss Enabled',
            'risk_management.cut_loss_percent'     => 'Cut Loss (%)',
            'risk_management.partial_sell_enabled' => 'Partial Sell Enabled',
            'risk_management.partial_targets'      => 'Partial Targets',
        ];

        foreach ($paths as $path => $label) {
            $old = $this->getNested($original, $path);
            $new = $this->getNested($proposed, $path);

            if ($old !== $new && $new !== null) {
                $changes[] = [
                    'field' => $label,
                    'path'  => $path,
                    'old'   => $old,
                    'new'   => $new,
                ];
            }
        }

        // Entry conditions comparison
        $oldConditions = $original['base_order']['conditions'] ?? [];
        $newConditions = $proposed['base_order']['conditions'] ?? [];

        if ($oldConditions !== $newConditions) {
            $changes[] = [
                'field' => 'Entry Conditions',
                'path'  => 'base_order.conditions',
                'old'   => $oldConditions,
                'new'   => $newConditions,
                'type'  => 'conditions',
            ];
        }

        return [
            'total_changes' => count($changes),
            'changes'       => $changes,
        ];
    }

    /**
     * Generate a markdown diff summary for notification.
     */
    public function toMarkdown(array $diff, string $lang = 'en'): string
    {
        if ($diff['total_changes'] === 0) {
            return $lang === 'ms' ? 'No changes.' : 'No changes.';
        }

        $lines = $lang === 'ms'
            ? ["*Proposed changes:*"]
            : ["*Proposed changes:*"];

        foreach ($diff['changes'] as $change) {
            $field = $change['field'];

            if (($change['type'] ?? '') === 'conditions') {
                $oldCount = count($change['old'] ?? []);
                $newCount = count($change['new'] ?? []);
                $lines[] = "• *{$field}*: {$oldCount} → {$newCount} conditions";
            } elseif (is_bool($change['new'])) {
                $oldStr = $change['old'] ? 'ON' : 'OFF';
                $newStr = $change['new'] ? 'ON' : 'OFF';
                $lines[] = "• *{$field}*: {$oldStr} → {$newStr}";
            } else {
                $lines[] = "• *{$field}*: `{$change['old']}` → `{$change['new']}`";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Generate an HTML table diff for dashboard display.
     */
    public function toHtmlTable(array $diff): string
    {
        if ($diff['total_changes'] === 0) {
            return '<p class="text-gray-500 text-sm">No configuration changes.</p>';
        }

        $rows = '';
        foreach ($diff['changes'] as $change) {
            $oldVal = $this->formatValue($change['old']);
            $newVal = $this->formatValue($change['new']);
            $rows .= "<tr>
                <td class='px-3 py-1 font-medium'>{$change['field']}</td>
                <td class='px-3 py-1 text-red-600 line-through'>{$oldVal}</td>
                <td class='px-3 py-1'>→</td>
                <td class='px-3 py-1 text-green-600 font-medium'>{$newVal}</td>
            </tr>";
        }

        return "<table class='w-full text-sm border-collapse'>{$rows}</table>";
    }

    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '✅ ON' : '❌ OFF';
        }
        if (is_array($value)) {
            return count($value) . ' item(s)';
        }
        if (is_null($value)) {
            return '—';
        }
        return htmlspecialchars((string)$value);
    }

    private function getNested(array $data, string $path)
    {
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }
}
