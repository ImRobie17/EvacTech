<?php

namespace App\Support;

/**
 * Shared capabilities for the STANDARD report generators only.
 * The separately routed, finalized CSWDO IDP form is deliberately excluded.
 */
final class ReportDefinitions
{
    private const REPORTS = [
        'household_registry' => ['description' => 'Households registered in the selected period. Family size is the whole household.', 'date_basis' => 'Household registration date', 'demographics' => 'advanced'],
        'attendance' => ['description' => 'Households with check-ins in the selected period. Current presence is not a historical attendance snapshot.', 'date_basis' => 'Household check-in date', 'demographics' => 'advanced'],
        'relief' => ['description' => 'Goods distributed during the selected period. Recipient filters match households, not individual relief quantities.', 'date_basis' => 'Distribution transaction date', 'demographics' => 'advanced'],
        'relief_inventory' => ['description' => 'Current tracked quantities of physical relief goods, per shelter and item. Stock levels use each item’s reorder threshold; monetary items are excluded.', 'date_basis' => null, 'demographics' => 'none'],
        'shelter_transfers' => ['description' => 'Shelter-to-shelter transfers requested in the selected period, including those arriving at or departing from the selected shelter. The household-head name and transfer status reflect current records, not a request-time snapshot.', 'date_basis' => 'Transfer request date', 'demographics' => 'none'],
        'relief_received' => ['description' => 'Stock receipts and incoming allocations in the selected period; no household demographics apply.', 'date_basis' => 'Receipt transaction date', 'demographics' => 'none'],
        'vulnerable' => ['description' => 'Vulnerable members registered in the selected period. Presence and category tags reflect current records.', 'date_basis' => 'Member registration date', 'demographics' => 'direct'],
        'demographics' => ['description' => 'Members registered in the selected period. Presence and vulnerability tags reflect current records.', 'date_basis' => 'Member registration date', 'demographics' => 'direct'],
        'occupancy' => ['description' => 'Current shelter occupancy and capacity. This is not a historical report.', 'date_basis' => null, 'demographics' => 'none'],
        'shelter_ranking' => ['description' => 'Current city-wide ranking by occupancy percentage. This is not a historical report.', 'date_basis' => null, 'demographics' => 'none', 'center' => false],
        'shelter_demographic_summary' => ['description' => 'Current registered-member breakdown by vulnerable category. Classification counts may overlap and are not unique-person totals.', 'date_basis' => null, 'demographics' => 'direct'],
        'user_accounts' => ['description' => 'Current user accounts and their most recently recorded login, not a historical account snapshot.', 'date_basis' => null, 'demographics' => 'none'],
        'audit_summary' => ['description' => 'Recorded audit entries created in the selected period (up to the current export limit).', 'date_basis' => 'Audit entry creation date', 'demographics' => 'none'],
        'system_activity' => ['description' => 'System events recorded during the selected period.', 'date_basis' => 'System event creation date', 'demographics' => 'none'],
        'login_activity' => ['description' => 'Accounts whose LAST recorded login falls in the selected period, not a full history of login events.', 'date_basis' => 'Last recorded login date', 'demographics' => 'none'],
    ];

    public static function forTypes(array $types): array
    {
        return array_intersect_key(self::REPORTS, array_flip($types));
    }

    public static function forType(string $type): array
    {
        return self::REPORTS[$type] ?? ['date_basis' => null, 'demographics' => 'none', 'center' => false];
    }

    public static function supportsDates(string $type): bool
    {
        return self::forType($type)['date_basis'] !== null;
    }

    public static function supportsDemographics(string $type): bool
    {
        return self::forType($type)['demographics'] !== 'none';
    }

    public static function supportsCenter(string $type): bool
    {
        return self::forType($type)['center'] ?? true;
    }

    public static function context(string $type, ?string $from, ?string $to): string
    {
        $basis = self::forType($type)['date_basis'];
        if ($basis === null) {
            return 'Current snapshot (as of generation)';
        }
        return $basis . ': ' . ($from ?? 'Beginning of records') . ' to ' . ($to ?? 'Present');
    }


    /** Printed in PDF and Excel so exported records retain their limitations. */
    public static function operationalNote(string $type): ?string
    {
        return match ($type) {
            'relief_inventory' => 'Current tracked physical goods only; monetary items and items without inventory records are excluded. Quantities in different units must not be summed.',
            'shelter_transfers' => 'Dates filter transfer requests, not departures or arrivals. A selected shelter includes inbound and outbound transfers. Household-head names and statuses reflect current records.',
            default => null,
        };
    }

    /** Extra filters are never applied to any other report type. */
    public static function operationalFilterLabels(string $type, array $data): array
    {
        if ($type === 'relief_inventory' && ! empty($data['stock_state'])) {
            return ['Stock level: ' . match ($data['stock_state']) {
                'out' => 'Out of stock', 'low' => 'Low stock', 'adequate' => 'Adequate',
                default => 'All',
            }];
        }
        if ($type === 'shelter_transfers' && ! empty($data['transfer_status'])) {
            return ['Transfer status: ' . ucfirst(str_replace('_', ' ', $data['transfer_status']))];
        }
        return [];
    }

    public static function operationalFilterSlug(string $type, array $data): string
    {
        $value = $type === 'relief_inventory' ? ($data['stock_state'] ?? null)
            : ($type === 'shelter_transfers' ? ($data['transfer_status'] ?? null) : null);
        return $value ? '_' . $value : '';
    }

    public static function isSensitive(string $type): bool
    {
        return in_array($type, ['household_registry', 'attendance', 'relief', 'vulnerable', 'demographics', 'shelter_transfers', 'user_accounts', 'audit_summary', 'system_activity', 'login_activity'], true);
    }
}
