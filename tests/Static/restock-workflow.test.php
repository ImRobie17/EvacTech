<?php
/**
 * Source-only smoke checks for the restock lifecycle. Run:
 * php tests/Static/restock-workflow.test.php
 * Integration tests still require Laravel, migrations, and MySQL.
 */
$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => file_get_contents($root . '/' . $path);
$city = $read('app/Http/Controllers/CityAdmin/ReliefController.php');
$barangay = $read('app/Http/Controllers/Barangay/ReliefController.php');
$migration = $read('database/migrations/2026_10_14_000001_add_restock_delivery_tracking.php');
$cityRoutes = $read('routes/city.php');
$staffRoutes = $read('routes/web.php');
$model = $read('app/Models/ReliefAllocationRequest.php');
$cityView = $read('resources/views/cityadmin/relief/index.blade.php');
$staffView = $read('resources/views/barangay/relief/index.blade.php');
$staffJs = $read('resources/js/staff.js');
$checks = [
    'item group ID created once per submission' => str_contains($barangay, 'Str::uuid()') && str_contains($barangay, "'request_group_id' => \$group"),
    'custom name validated and optional description stored' => str_contains($barangay, 'items.$index.custom_good_name') && str_contains($barangay, "'custom_description' =>"),
    'approval no longer calls stock receipt writer' => ! str_contains(substr($city, strpos($city, 'public function approveRestock'), strpos($city, 'public function closeOutstandingDelivery') - strpos($city, 'public function approveRestock')), 'applyReliefReceipt('),
    'arrival credits stock via receipt writer' => str_contains($barangay, "'City warehouse (confirmed delivery)'") && str_contains($barangay, "'allocated_in'"),
    'arrival uses row lock and shelter authorization' => str_contains($barangay, 'lockForUpdate()') && str_contains($barangay, 'abort_unless((int) $reliefRequest->evacuation_center_id'),
    'partial delivery cannot exceed remaining' => str_contains($barangay, 'if ($quantity > $line->remaining_quantity)'),
    'migration preserves previously credited approvals' => str_contains($migration, "whereIn('status', ['approved', 'fulfilled'])") && str_contains($migration, "DB::raw('requested_quantity')"),
    'delivery transactions attributable to requests' => str_contains($migration, 'relief_allocation_request_id') && str_contains($model, 'latestReceipt()'),
    'approvals and outstanding closure individually routed' => str_contains($cityRoutes, "name('relief.restock.review')") && str_contains($cityRoutes, "name('relief.restock.close')"),
    'assigned staff arrival route exists' => str_contains($staffRoutes, "name('relief.restock.arrival')"),
    'custom catalogue mapping available to City Admin' => str_contains($cityView, 'data-catalog-resolve') && str_contains($city, 'catalog_choice'),
    'multiple item client UI and arrival confirmation wired' => str_contains($staffView, 'restockItemTemplate') && str_contains($staffView, 'Confirm Stock Arrival') && str_contains($staffJs, 'initRestockRequest()'),
    'city details comment is free of embedded PHP directives' => ! str_contains($read('resources/views/cityadmin/shelters/show.blade.php'), '@php ... @endphp'),
];
$failures = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . "\n";
    if (! $ok) $failures++;
}
exit($failures ? 1 : 0);
