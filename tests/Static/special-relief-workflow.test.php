<?php
/**
 * Source-only guards for household-specific items and the City Admin layout.
 * Integration tests still require Laravel, MySQL and an authenticated shelter.
 * Run: php tests/Static/special-relief-workflow.test.php
 */
$root = dirname(__DIR__, 2);
$read = static fn (string $file): string => file_get_contents($root . '/' . $file);
$staff = $read('app/Http/Controllers/Barangay/ReliefController.php');
$city = $read('app/Http/Controllers/CityAdmin/ReliefController.php');
$staffView = $read('resources/views/barangay/relief/index.blade.php');
$cityView = $read('resources/views/cityadmin/relief/index.blade.php');
$migration = $read('database/migrations/2026_10_14_000002_track_special_relief_fulfillment.php');
$model = $read('app/Models/SpecialReliefRequest.php');
$movement = $read('app/Models/SpecialReliefMovement.php');
$routes = $read('routes/web.php');
$specialBlock = substr($staff, strpos($staff, 'private function recordSpecialMovement'), strpos($staff, 'public function requestSpecial') - strpos($staff, 'private function recordSpecialMovement'));
$checks = [
    'separate special item receipt and household distribution routes' =>
        str_contains($routes, "name('relief.special.arrival')") && str_contains($routes, "name('relief.special.distribute')"),
    'approved special goods are kept outside inventory ledger' =>
        !str_contains($specialBlock, 'applyReliefReceipt') && !str_contains($specialBlock, 'ReliefTransaction::') && !str_contains($specialBlock, 'ReliefInventory::'),
    'request, received and distributed quantities persist' =>
        str_contains($migration, "'received_quantity'") && str_contains($migration, "'distributed_quantity'") &&
        str_contains($model, 'getAvailableToDistributeAttribute'),
    'per-action movement history is auditable and idempotent' =>
        str_contains($migration, "Schema::create('special_relief_movements'") &&
        str_contains($migration, "->unique()") && str_contains($movement, "'recorded_by'") &&
        str_contains($specialBlock, "where('submission_token',") && str_contains($specialBlock, 'lockForUpdate()'),
    'request belongs to assigned shelter' =>
        str_contains($specialBlock, '(int) $specialRequest->evacuation_center_id === (int) $center->id'),
    'distribution requires the named household checked in at same shelter' =>
        str_contains($specialBlock, 'Household::whereKey($line->household_id)') &&
        str_contains($specialBlock, "\$household->status === 'checked_in'"),
    'no reception of more than approved and no distribution before receipt' =>
        str_contains($specialBlock, '$quantity > $line->remaining_to_receive') &&
        str_contains($specialBlock, '$quantity > $line->available_to_distribute'),
    'only full family handover marks request fulfilled' =>
        str_contains($specialBlock, "\$line->status = 'fulfilled'"),
    'approved special requests remain visible to assigned Camp Manager' =>
        str_contains($staff, "whereIn('status', ['pending', 'approved'])"),
    'Camp Manager has both confirmation forms' =>
        str_contains($staffView, "route('barangay.relief.special.arrival', \$s)") &&
        str_contains($staffView, "route('barangay.relief.special.distribute', \$s)"),
    'City Admin has separate special fulfillment tracking' =>
        str_contains($city, '$specialFulfillments') && str_contains($cityView, 'Special Item Fulfillment'),
    'approval queues are stacked at all breakpoints, with semantic td cells' =>
        str_contains($cityView, '<section class="mt-4 flex flex-col gap-4">') &&
        !str_contains($cityView, 'lg:grid-cols-2') &&
        !str_contains($cityView, '<td class="flex flex-col items-stretch gap-2"'),
];
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . "\n";
    if (!$ok) $fail++;
}
exit($fail > 0 ? 1 : 0);
