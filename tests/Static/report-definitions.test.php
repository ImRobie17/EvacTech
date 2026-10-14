<?php

require __DIR__ . '/../../app/Support/ReportDefinitions.php';
require __DIR__ . '/../../app/Support/ReportMetrics.php';

use App\Support\ReportDefinitions as Report;
use App\Support\ReportMetrics as Metrics;

$check = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException('FAILED: ' . $message);
};

$check(Report::supportsDates('relief_received'), 'receipt uses dates');
$check(! Report::supportsDates('occupancy'), 'occupancy is live');
$check(! Report::supportsDates('user_accounts'), 'accounts are current');
$check(Report::supportsDates('login_activity'), 'last-login supports dates');
$check(! Report::supportsDemographics('relief_received'), 'receipt rejects demographics');
$check(Report::supportsDemographics('demographics'), 'members support demographics');
$check(Report::supportsDemographics('shelter_demographic_summary'), 'summary supports categories');
$check(! Report::supportsCenter('shelter_ranking'), 'ranking is city-wide');
$check(count(Report::forTypes(['household_registry', 'bogus'])) === 1, 'only known definitions included');
$metrics = Metrics::forRows('occupancy', ['Capacity', 'Occupancy'], [[100, 40], [50, 30]]);
$check($metrics['Current evacuees'] === '70', 'occupancy sum');
$check($metrics['Combined occupancy'] === '46.7%', 'weighted occupancy');
$relief = Metrics::forRows('relief_received', ['Item', 'Value (PHP)'], [['Rice', '1,200.50'], ['TOTAL VALUE', '1,200.50']]);
$check($relief['Total recorded value (PHP)'] === '1,200.50', 'receipt total');

echo "Report definitions / metrics: PASS\n";
