<?php

namespace App\Support;

/** Safe aggregations of values already present in standard report rows. */
final class ReportMetrics
{
    public static function forRows(string $type, array $headings, array $rows): array
    {
        $columnSum = static function (string $heading) use ($headings, $rows): int {
            $column = array_search($heading, $headings, true);
            if ($column === false) return 0;
            return array_sum(array_map(static fn ($row) => (int) str_replace(',', '', (string) ($row[$column] ?? 0)), $rows));
        };

        if (in_array($type, ['occupancy', 'shelter_ranking'], true)) {
            $capacity = $columnSum('Capacity');
            $current = $columnSum(in_array('Current Occupancy', $headings, true) ? 'Current Occupancy' : 'Occupancy');
            return [
                'Total capacity' => number_format($capacity),
                'Current evacuees' => number_format($current),
                'Combined occupancy' => $capacity > 0 ? round(100 * $current / $capacity, 1) . '%' : 'N/A',
            ];
        }
        if ($type === 'relief_inventory') {
            $stockColumn = array_search('Stock Status', $headings, true);
            $statuses = $stockColumn === false ? [] : array_map(
                static fn ($row) => (string) ($row[$stockColumn] ?? ''), $rows
            );
            return [
                'Tracked item lines listed' => number_format(count($rows)),
                'Out-of-stock lines listed' => number_format(count(array_filter($statuses, static fn ($s) => $s === 'Out of stock'))),
                'Low-stock lines listed' => number_format(count(array_filter($statuses, static fn ($s) => $s === 'Low stock'))),
            ];
        }
        if ($type === 'shelter_transfers') {
            $statusColumn = array_search('Status', $headings, true);
            $statuses = $statusColumn === false ? [] : array_map(
                static fn ($row) => (string) ($row[$statusColumn] ?? ''), $rows
            );
            return [
                'Transfers listed' => number_format(count($rows)),
                'In transit listed' => number_format(count(array_filter($statuses, static fn ($s) => $s === 'In transit'))),
                'Completed listed' => number_format(count(array_filter($statuses, static fn ($s) => $s === 'Completed'))),
            ];
        }
        if ($type === 'household_registry') {
            return ['People in listed households' => number_format($columnSum('Family Size'))];
        }
        if ($type === 'attendance') {
            return ['Currently present in listed households' => number_format($columnSum('Members Present'))];
        }
        if ($type === 'relief_received' && $rows) {
            $valueIndex = array_search('Value (PHP)', $headings, true);
            $last = end($rows);
            if ($valueIndex !== false && in_array('TOTAL VALUE', (array) $last, true)) {
                $value = (float) str_replace(',', '', (string) ($last[$valueIndex] ?? '0'));
                return ['Total recorded value (PHP)' => number_format($value, 2)];
            }
        }
        return [];
    }
}
