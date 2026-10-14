<?php

namespace App\Support;

use App\Models\ReliefInventory;
use App\Models\ShelterTransfer;

/**
 * Two standard operational reports shared by Barangay and City Admin.
 * Scoping is applied to the SQL query, never to a collection after loading it.
 * No connection with the separately routed CSWDO IDP form.
 */
final class OperationalReportDatasets
{
    public const STOCK_STATES = ['out', 'low', 'adequate'];
    public const TRANSFER_STATES = [
        ShelterTransfer::PENDING, ShelterTransfer::APPROVED,
        ShelterTransfer::IN_TRANSIT, ShelterTransfer::COMPLETED,
        ShelterTransfer::REFUSED, ShelterTransfer::CANCELLED,
    ];

    public static function inventory(?int $centerId, ?string $stockState, bool $cityWideColumns): array
    {
        // Monetary relief is accounted for as currency, not quantities of goods.
        $query = ReliefInventory::with(['reliefGood', 'evacuationCenter.barangay'])
            ->whereHas('reliefGood', fn ($q) => $q->countable())
            ->when($centerId !== null, fn ($q) => $q->where('evacuation_center_id', $centerId))
            ->orderBy('evacuation_center_id')
            ->orderBy('relief_good_id');

        // Out of stock takes precedence over the <= reorder low-stock rule.
        $query->when($stockState === 'out', fn ($q) => $q->where('quantity_on_hand', 0))
            ->when($stockState === 'low', fn ($q) => $q->where('quantity_on_hand', '>', 0)
                ->whereColumn('quantity_on_hand', '<=', 'reorder_level'))
            ->when($stockState === 'adequate', fn ($q) => $q->where('quantity_on_hand', '>', 0)
                ->whereColumn('quantity_on_hand', '>', 'reorder_level'));

        $headings = $cityWideColumns
            ? ['Shelter', 'Barangay', 'Item', 'Category', 'Unit', 'On Hand', 'Reorder Level', 'Stock Status', 'Last Updated']
            : ['Item', 'Category', 'Unit', 'On Hand', 'Reorder Level', 'Stock Status', 'Last Updated'];

        return [$headings, $query->get()->map(fn ($row) => self::inventoryRow($row, $cityWideColumns))->all(),
            'Relief Inventory Status'];
    }

    public static function inventoryState(int $quantity, int $reorder): string
    {
        if ($quantity === 0) return 'Out of stock';
        return $quantity <= $reorder ? 'Low stock' : 'Adequate';
    }

    public static function inventoryRow(ReliefInventory $inventory, bool $cityWideColumns): array
    {
        $good = $inventory->reliefGood;
        $row = [
            $good?->name ?? 'Unknown item',
            $good?->category ?? '-',
            $good?->unit ?? '-',
            (int) $inventory->quantity_on_hand,
            (int) $inventory->reorder_level,
            self::inventoryState((int) $inventory->quantity_on_hand, (int) $inventory->reorder_level),
            $inventory->last_updated_at?->format('M d, Y h:i A') ?? '-',
        ];
        if ($cityWideColumns) {
            array_unshift($row, $inventory->evacuationCenter?->barangay?->name ?? '-');
            array_unshift($row, $inventory->evacuationCenter?->name ?? '-');
        }
        return $row;
    }

    public static function transfers(?int $centerId, ?string $status, ?string $from, ?string $to): array
    {
        $query = ShelterTransfer::with(['household.headMember', 'fromCenter', 'toCenter'])
            // A Barangay operator sees only transfers that involve their shelter,
            // in either direction. City Admin's optional shelter works the same.
            ->when($centerId !== null, fn ($q) => $q->where(fn ($q) => $q
                ->where('from_center_id', $centerId)
                ->orWhere('to_center_id', $centerId)))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($from, fn ($q) => $q->whereDate('requested_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('requested_at', '<=', $to))
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        $headings = ['Requested', 'Household ID', 'Household Head', 'Origin', 'Destination', 'Status',
            'Expected', 'Received', 'Departed', 'Arrived'];

        return [$headings, $query->get()->map(fn ($transfer) => self::transferRow($transfer))->all(),
            'Shelter Transfer History'];
    }

    public static function transferRow(ShelterTransfer $transfer): array
    {
        return [
            $transfer->requested_at?->format('M d, Y h:i A') ?? '-',
            $transfer->household?->household_code ?? '-',
            $transfer->household?->headMember?->full_name ?? '-',
            $transfer->fromCenter?->name ?? '-',
            $transfer->toCenter?->name ?? '-',
            $transfer->statusLabel(),
            (int) $transfer->members_expected,
            $transfer->members_received === null ? '-' : (int) $transfer->members_received,
            $transfer->departed_at?->format('M d, Y h:i A') ?? '-',
            $transfer->received_at?->format('M d, Y h:i A') ?? '-',
        ];
    }
}
