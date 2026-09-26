<?php
/**
 * Paddle Billing Payment Gateway for WHMCS - Product & Price Sync Tests
 */

define('WHMCS', true);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../modules/gateways/paddle/lib/ProductSyncService.php';

use WHMCS\Module\Gateway\Paddle\ProductSyncService;

$passed = 0;
$failed = 0;

function runSyncTest(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $fn();
        echo "  [PASS] {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  [FAIL] {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEq(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \Exception("Assertion failed: expected " . json_encode($expected) . ", got " . json_encode($actual) . ". " . $msg);
    }
}

echo "========================================================\n";
echo " Product & Price Sync Service Verification\n";
echo "========================================================\n\n";

runSyncTest("Billing cycle normalization handles all WHMCS cycle variations", function() {
    assertEq('monthly', ProductSyncService::normalizeBillingCycle('monthly'));
    assertEq('monthly', ProductSyncService::normalizeBillingCycle('Monthly'));
    assertEq('monthly', ProductSyncService::normalizeBillingCycle('month'));
    assertEq('quarterly', ProductSyncService::normalizeBillingCycle('Quarterly'));
    assertEq('semiannually', ProductSyncService::normalizeBillingCycle('Semi-Annually'));
    assertEq('semiannually', ProductSyncService::normalizeBillingCycle('semiannual'));
    assertEq('annually', ProductSyncService::normalizeBillingCycle('Annually'));
    assertEq('annually', ProductSyncService::normalizeBillingCycle('Yearly'));
    assertEq('annually', ProductSyncService::normalizeBillingCycle('annual'));
    assertEq('biennially', ProductSyncService::normalizeBillingCycle('Biennially'));
    assertEq('biennially', ProductSyncService::normalizeBillingCycle('twoyear'));
    assertEq('triennially', ProductSyncService::normalizeBillingCycle('Triennially'));
    assertEq('onetime', ProductSyncService::normalizeBillingCycle('One Time'));
    assertEq('onetime', ProductSyncService::normalizeBillingCycle('custom_onetime'));
});

runSyncTest("Reflection test: mapCycleToPaddleBillingCycle returns accurate Paddle recurrence intervals", function() {
    $refMethod = new \ReflectionMethod(ProductSyncService::class, 'mapCycleToPaddleBillingCycle');
    $refMethod->setAccessible(true);

    assertEq(['interval' => 'month', 'frequency' => 1], $refMethod->invoke(null, 'monthly'));
    assertEq(['interval' => 'month', 'frequency' => 3], $refMethod->invoke(null, 'quarterly'));
    assertEq(['interval' => 'month', 'frequency' => 6], $refMethod->invoke(null, 'semiannually'));
    assertEq(['interval' => 'year', 'frequency' => 1], $refMethod->invoke(null, 'annually'));
    assertEq(['interval' => 'year', 'frequency' => 2], $refMethod->invoke(null, 'biennially'));
    assertEq(['interval' => 'year', 'frequency' => 3], $refMethod->invoke(null, 'triennially'));
    assertEq(null, $refMethod->invoke(null, 'onetime'));
});

runSyncTest("Reflection test: mapCycleToPricingColumn maps to WHMCS tblpricing columns", function() {
    $refMethod = new \ReflectionMethod(ProductSyncService::class, 'mapCycleToPricingColumn');
    $refMethod->setAccessible(true);

    assertEq('monthly', $refMethod->invoke(null, 'monthly'));
    assertEq('quarterly', $refMethod->invoke(null, 'quarterly'));
    assertEq('semiannually', $refMethod->invoke(null, 'semiannually'));
    assertEq('annually', $refMethod->invoke(null, 'annually'));
    assertEq('biennially', $refMethod->invoke(null, 'biennially'));
    assertEq('triennially', $refMethod->invoke(null, 'triennially'));
});

echo "\nCompleted: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) exit(1);
exit(0);
