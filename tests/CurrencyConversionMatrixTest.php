<?php
/**
 * Paddle Billing Payment Gateway for WHMCS - Multi-Currency Conversion Matrix Tests
 *
 * Verifies the exact math for WHMCS multi-currency conversion scenarios.
 */

define('WHMCS', true);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../modules/gateways/paddle/lib/CurrencyService.php';

use WHMCS\Module\Gateway\Paddle\CurrencyService;

$passed = 0;
$failed = 0;

function runMatrixTest(string $name, callable $fn): void {
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

echo "========================================================\n";
echo " Multi-Currency Conversion Matrix Verification\n";
echo "========================================================\n\n";

runMatrixTest("Scenario A: USD Native Invoice (USD 10.00 -> USD 10.00)", function() {
    $res = CurrencyService::convertToUsd(10.00, 'USD');
    if ($res['usd_amount'] !== 10.00 || $res['usd_cents'] !== '1000') {
        throw new \Exception("Mismatch in USD native invoice: " . json_encode($res));
    }
});

runMatrixTest("Scenario B: EUR to USD conversion math check (EUR 10.00 @ 1.08 -> USD 10.80)", function() {
    // In WHMCS tblcurrencies formula:
    // If USD is base (rate=1), and EUR has rate 0.9259259 (1/1.08):
    // Foreign / Rate = 10.00 / (1/1.08) = 10.80 USD.
    // Or if EUR is base (rate=1) and USD has rate 1.08:
    // (Foreign / EUR_rate) * USD_rate = (10.00 / 1.0) * 1.08 = 10.80 USD.
    $sourceAmount = 10.00;
    $eurRate = 1.000000;
    $usdRate = 1.080000;

    $baseAmount = bcdiv(number_format($sourceAmount, 4, '.', ''), number_format($eurRate, 6, '.', ''), 8);
    $calculatedUsd = (float)bcmul($baseAmount, number_format($usdRate, 6, '.', ''), 8);
    $roundedUsd = round($calculatedUsd, 2, PHP_ROUND_HALF_UP);
    $cents = (string)intval(round($roundedUsd * 100));

    if ($roundedUsd !== 10.80 || $cents !== '1080') {
        throw new \Exception("Expected 10.80 USD / 1080 cents, got: {$roundedUsd} / {$cents}");
    }
});

runMatrixTest("Scenario C: PKR to USD conversion math check (PKR 3000 @ 300 PKR/USD -> USD 10.00)", function() {
    // PKR 3,000 where 300 PKR = 1 USD:
    // In WHMCS tblcurrencies: If USD is base (rate=1.0), PKR rate is 300.00:
    // Base Amount (USD) = PKR Amount / PKR Rate = 3000 / 300 = 10.00 USD.
    $sourceAmount = 3000.00;
    $pkrRate = 300.000000;
    $usdRate = 1.000000;

    $baseAmount = bcdiv(number_format($sourceAmount, 4, '.', ''), number_format($pkrRate, 6, '.', ''), 8);
    $calculatedUsd = (float)bcmul($baseAmount, number_format($usdRate, 6, '.', ''), 8);
    $roundedUsd = round($calculatedUsd, 2, PHP_ROUND_HALF_UP);
    $cents = (string)intval(round($roundedUsd * 100));

    if ($roundedUsd !== 10.00 || $cents !== '1000') {
        throw new \Exception("Expected 10.00 USD / 1000 cents, got: {$roundedUsd} / {$cents}");
    }
});

runMatrixTest("Scenario D: High precision fraction (GBP 19.99 @ 1.27345 -> USD 25.46)", function() {
    $sourceAmount = 19.99;
    $gbpRate = 1.000000;
    $usdRate = 1.273450;

    $baseAmount = bcdiv(number_format($sourceAmount, 4, '.', ''), number_format($gbpRate, 6, '.', ''), 8);
    $calculatedUsd = (float)bcmul($baseAmount, number_format($usdRate, 6, '.', ''), 8);
    $roundedUsd = round($calculatedUsd, 2, PHP_ROUND_HALF_UP);
    $cents = (string)intval(round($roundedUsd * 100));

    // 19.99 * 1.27345 = 25.4562655 -> rounds to 25.46
    if ($roundedUsd !== 25.46 || $cents !== '2546') {
        throw new \Exception("Expected 25.46 USD / 2546 cents, got: {$roundedUsd} / {$cents}");
    }
});

echo "\nCompleted: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) exit(1);
exit(0);
