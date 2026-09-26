<?php
/**
 * Paddle Billing Payment Gateway for WHMCS - Test Suite Runner
 *
 * Runs automated tests validating Webhook verification, Currency conversions,
 * Security sanitizers, SSRF protections, and API client rules.
 */

define('WHMCS', true);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Autoload module classes
require_once __DIR__ . '/../modules/gateways/paddle/lib/Compatibility.php';
require_once __DIR__ . '/../modules/gateways/paddle/lib/Security.php';
require_once __DIR__ . '/../modules/gateways/paddle/lib/Logger.php';
require_once __DIR__ . '/../modules/gateways/paddle/lib/Database.php';
require_once __DIR__ . '/../modules/gateways/paddle/lib/CurrencyService.php';
require_once __DIR__ . '/../modules/gateways/paddle/lib/ApiClient.php';
require_once __DIR__ . '/../modules/gateways/paddle/lib/WebhookVerifier.php';

use WHMCS\Module\Gateway\Paddle\Compatibility;
use WHMCS\Module\Gateway\Paddle\Security;
use WHMCS\Module\Gateway\Paddle\CurrencyService;
use WHMCS\Module\Gateway\Paddle\ApiClient;
use WHMCS\Module\Gateway\Paddle\PaddleApiException;
use WHMCS\Module\Gateway\Paddle\WebhookVerifier;

$passed = 0;
$failed = 0;
$testsRun = 0;

function runTest(string $name, callable $testFunc): void {
    global $passed, $failed, $testsRun;
    $testsRun++;
    try {
        $testFunc();
        echo "  [PASS] {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  [FAIL] {$name}: {$e->getMessage()}\n";
        echo "         at {$e->getFile()}:{$e->getLine()}\n";
        $failed++;
    }
}

function assertEquals(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $expStr = is_scalar($expected) ? var_export($expected, true) : json_encode($expected);
        $actStr = is_scalar($actual) ? var_export($actual, true) : json_encode($actual);
        throw new \Exception("Assertion failed: expected {$expStr}, got {$actStr}. " . $msg);
    }
}

function assertTrue(bool $condition, string $msg = ''): void {
    if (!$condition) {
        throw new \Exception("Assertion failed: expected true. " . $msg);
    }
}

function assertFalse(bool $condition, string $msg = ''): void {
    if ($condition) {
        throw new \Exception("Assertion failed: expected false. " . $msg);
    }
}

echo "========================================================\n";
echo " Paddle Billing Payment Gateway - Verification Test Suite\n";
echo " PHP Version: " . PHP_VERSION . "\n";
echo " Module Version: " . Compatibility::MODULE_VERSION . "\n";
echo "========================================================\n\n";

// =============================================================================
// SUITE 1: COMPATIBILITY & ENVIRONMENT
// =============================================================================
echo "Running Suite 1: Compatibility & Environment...\n";

runTest("PHP version is within 8.1 - 8.3 range", function() {
    assertTrue(PHP_VERSION_ID >= 80100, "PHP version must be >= 8.1");
    assertTrue(PHP_VERSION_ID < 80400, "PHP version must be < 8.4");
});

runTest("Required PHP extensions are loaded", function() {
    $required = ['curl', 'json', 'openssl', 'mbstring', 'bcmath'];
    foreach ($required as $ext) {
        assertTrue(extension_loaded($ext), "Extension {$ext} must be loaded");
    }
});

// =============================================================================
// SUITE 2: WEBHOOK SIGNATURE VERIFIER
// =============================================================================
echo "\nRunning Suite 2: Webhook Signature Verifier...\n";

$testSecret = 'pdl_ntfset_01h6t4v9kzb3j7r6m1w8d5c2e0_secret';
$testBody = json_encode([
    'event_id' => 'evt_01h8abc123',
    'event_type' => 'transaction.paid',
    'data' => [
        'id' => 'txn_01h8abc999',
        'details' => ['totals' => ['grand_total' => '1080']],
    ],
]);

runTest("Valid Paddle webhook signature passes verification", function() use ($testSecret, $testBody) {
    $ts = time();
    $hash = hash_hmac('sha256', $ts . ':' . $testBody, $testSecret);
    $header = "ts={$ts};h1={$hash}";

    assertTrue(WebhookVerifier::verify($testBody, $header, $testSecret));
});

runTest("Tampered webhook body is rejected", function() use ($testSecret, $testBody) {
    $ts = time();
    $hash = hash_hmac('sha256', $ts . ':' . $testBody, $testSecret);
    $header = "ts={$ts};h1={$hash}";

    $tamperedBody = $testBody . ' ';
    assertFalse(WebhookVerifier::verify($tamperedBody, $header, $testSecret));
});

runTest("Replay attack with expired timestamp (>300s) is rejected", function() use ($testSecret, $testBody) {
    $expiredTs = time() - 305; // 5 minutes and 5 seconds old
    $hash = hash_hmac('sha256', $expiredTs . ':' . $testBody, $testSecret);
    $header = "ts={$expiredTs};h1={$hash}";

    assertFalse(WebhookVerifier::verify($testBody, $header, $testSecret));
});

runTest("Future timestamp drift (>300s) is rejected", function() use ($testSecret, $testBody) {
    $futureTs = time() + 305;
    $hash = hash_hmac('sha256', $futureTs . ':' . $testBody, $testSecret);
    $header = "ts={$futureTs};h1={$hash}";

    assertFalse(WebhookVerifier::verify($testBody, $header, $testSecret));
});

runTest("Secret rotation with multiple h1 hashes passes if any matches", function() use ($testSecret, $testBody) {
    $ts = time();
    $oldHash = '0000000000000000000000000000000000000000000000000000000000000000';
    $validHash = hash_hmac('sha256', $ts . ':' . $testBody, $testSecret);
    $header = "ts={$ts};h1={$oldHash};h1={$validHash}";

    assertTrue(WebhookVerifier::verify($testBody, $header, $testSecret));
});

runTest("Incorrect webhook secret fails verification", function() use ($testBody) {
    $ts = time();
    $hash = hash_hmac('sha256', $ts . ':' . $testBody, 'wrong_secret');
    $header = "ts={$ts};h1={$hash}";

    assertFalse(WebhookVerifier::verify($testBody, $header, 'configured_secret'));
});

// =============================================================================
// SUITE 3: CURRENCY CONVERSION & MONETARY PRECISION
// =============================================================================
echo "\nRunning Suite 3: Currency Conversion & Monetary Precision...\n";

runTest("USD amount returns identical value and exact cents string", function() {
    $res = CurrencyService::convertToUsd(10.50, 'USD');
    assertEquals(10.50, $res['usd_amount']);
    assertEquals("1050", $res['usd_cents']);
    assertEquals('USD', $res['original_currency']);
    assertEquals(1.0, $res['exchange_rate']);
});

runTest("Small decimal cents conversion is exact without floating precision drift", function() {
    $res = CurrencyService::convertToUsd(0.99, 'USD');
    assertEquals(0.99, $res['usd_amount']);
    assertEquals("99", $res['usd_cents']);

    $res2 = CurrencyService::convertToUsd(100.01, 'USD');
    assertEquals(100.01, $res2['usd_amount']);
    assertEquals("10001", $res2['usd_cents']);
});

runTest("Zero or negative monetary amounts throw InvalidArgumentException", function() {
    $threwZero = false;
    try {
        CurrencyService::convertToUsd(0.00, 'USD');
    } catch (\InvalidArgumentException) {
        $threwZero = true;
    }
    assertTrue($threwZero, "0.00 amount must throw InvalidArgumentException");

    $threwNegative = false;
    try {
        CurrencyService::convertToUsd(-5.00, 'USD');
    } catch (\InvalidArgumentException) {
        $threwNegative = true;
    }
    assertTrue($threwNegative, "Negative amount must throw InvalidArgumentException");
});

runTest("Cents to dollars and dollars to cents conversions", function() {
    assertEquals(10.80, CurrencyService::centsToDollars('1080'));
    assertEquals(0.05, CurrencyService::centsToDollars('5'));
    assertEquals("1080", CurrencyService::dollarsToCents(10.80));
    assertEquals("5", CurrencyService::dollarsToCents(0.05));
});

// =============================================================================
// SUITE 4: SECURITY, SANITIZATION & SSRF
// =============================================================================
echo "\nRunning Suite 4: Security, Sanitization & SSRF...\n";

runTest("SSRF protection: only official Paddle API domains are allowed", function() {
    assertTrue(Security::isAllowedApiUrl('https://api.paddle.com/transactions'));
    assertTrue(Security::isAllowedApiUrl('https://sandbox-api.paddle.com/transactions'));

    // Reject non-HTTPS
    assertFalse(Security::isAllowedApiUrl('http://api.paddle.com/transactions'));
    // Reject arbitrary internal/external hosts
    assertFalse(Security::isAllowedApiUrl('https://169.254.169.254/latest/meta-data'));
    assertFalse(Security::isAllowedApiUrl('https://evil-attacker.com/steal-keys'));
    assertFalse(Security::isAllowedApiUrl('https://localhost/admin'));
});

runTest("Credential masking protects secret keys", function() {
    $masked = Security::maskSecret('pdl_live_1234567890abcdef123456', 8, 4);
    assertTrue(str_starts_with($masked, 'pdl_live'));
    assertTrue(str_ends_with($masked, '3456'));
    assertTrue(str_contains($masked, '****'));
    assertFalse(str_contains($masked, '1234567890abcdef'));
});

runTest("Sensitive data redactor cleans arrays and raw strings", function() {
    $payload = [
        'api_key' => 'pdl_live_abcdef123456',
        'webhook_secret' => 'pdl_ntfset_secret999',
        'auth_header' => 'Bearer secret_token_xyz',
        'invoice_id' => 1234,
        'customer' => [
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'email' => 'client@example.com',
        ],
    ];

    $redacted = Security::redactSensitiveData($payload);
    assertEquals(1234, $redacted['invoice_id']);
    assertEquals('client@example.com', $redacted['customer']['email']);
    assertFalse(str_contains(json_encode($redacted), '4111111111111111'));
    assertFalse(str_contains(json_encode($redacted), 'secret999'));
    assertFalse(str_contains(json_encode($redacted), 'secret_token_xyz'));
});

runTest("Safe JSON decode enforces depth limit and handles malformed strings", function() {
    $valid = Security::safeJsonDecode('{"test": "value"}');
    assertEquals('value', $valid['test']);

    $threwMalformed = false;
    try {
        Security::safeJsonDecode('{malformed: json');
    } catch (\RuntimeException) {
        $threwMalformed = true;
    }
    assertTrue($threwMalformed, "Malformed JSON must throw RuntimeException");
});

runTest("HTML output escaping prevents XSS injection", function() {
    $malicious = '<script>alert("XSS")</script>&"\'';
    $escaped = Security::escapeHtml($malicious);
    assertFalse(str_contains($escaped, '<script>'));
    assertTrue(str_contains($escaped, '&lt;script&gt;'));
});

// =============================================================================
// SUITE 5: API CLIENT INTEGRITY & ENVIRONMENT SEPARATION
// =============================================================================
echo "\nRunning Suite 5: API Client Integrity & Environment Rules...\n";

runTest("Sandbox ApiClient initializes correct base URL", function() {
    $client = new ApiClient('sandbox', 'pdl_sand_testkey123');
    assertEquals('https://sandbox-api.paddle.com', $client->getBaseUrl());
    assertEquals('sandbox', $client->getEnvironment());
});

runTest("Live ApiClient initializes correct base URL", function() {
    $client = new ApiClient('live', 'pdl_live_realkey123');
    assertEquals('https://api.paddle.com', $client->getBaseUrl());
    assertEquals('live', $client->getEnvironment());
});

runTest("Cross-environment credential check: Sandbox key on Live environment is rejected", function() {
    $threw = false;
    try {
        new ApiClient('live', 'pdl_sand_accidental_sandbox_key');
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    assertTrue($threw, "Sandbox key on Live environment must throw InvalidArgumentException");
});

runTest("Cross-environment credential check: Live key on Sandbox environment is rejected", function() {
    $threw = false;
    try {
        new ApiClient('sandbox', 'pdl_live_accidental_live_key');
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    assertTrue($threw, "Live key on Sandbox environment must throw InvalidArgumentException");
});

runTest("ApiClient stores and returns optional sellerId", function() {
    $client = new ApiClient('sandbox', 'pdl_sand_testkey123', 'errors', '12345');
    assertEquals('12345', $client->getSellerId());
});

runTest("paddle_config includes sandboxSellerId and liveSellerId fields", function() {
    require_once __DIR__ . '/../modules/gateways/paddle.php';
    $config = paddle_config();
    assertTrue(isset($config['sandboxSellerId']), "sandboxSellerId field must be present in paddle_config");
    assertTrue(isset($config['liveSellerId']), "liveSellerId field must be present in paddle_config");
    assertEquals('Sandbox Seller ID', $config['sandboxSellerId']['FriendlyName']);
    assertEquals('Live Seller ID', $config['liveSellerId']['FriendlyName']);
});

runTest("Compatibility::normalizeEnvironment handles variations of live and sandbox", function() {
    assertEquals('live', Compatibility::normalizeEnvironment('live'));
    assertEquals('live', Compatibility::normalizeEnvironment('Live'));
    assertEquals('live', Compatibility::normalizeEnvironment('Live (Production)'));
    assertEquals('live', Compatibility::normalizeEnvironment('production'));
    assertEquals('sandbox', Compatibility::normalizeEnvironment('sandbox'));
    assertEquals('sandbox', Compatibility::normalizeEnvironment('Sandbox (Test Mode)'));
    assertEquals('sandbox', Compatibility::normalizeEnvironment(null));
    assertEquals('sandbox', Compatibility::normalizeEnvironment(''));
});

runTest("ApiClient accepts both pdl_sand_ and pdl_sdbx_apikey_ prefixes for Sandbox", function() {
    $client1 = new ApiClient('sandbox', 'pdl_sand_standard123');
    assertEquals('sandbox', $client1->getEnvironment());

    $client2 = new ApiClient('sandbox', 'pdl_sdbx_apikey_01j9abcxyz_test');
    assertEquals('sandbox', $client2->getEnvironment());
});

runTest("ApiClient accepts pdl_live_apikey_ prefix for Live", function() {
    $client = new ApiClient('live', 'pdl_live_apikey_01j9abcxyz_live');
    assertEquals('live', $client->getEnvironment());
});

runTest("Cross-environment credential check: pdl_sdbx_ key on Live environment is rejected", function() {
    $threw = false;
    try {
        new ApiClient('live', 'pdl_sdbx_apikey_01j9test');
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    assertTrue($threw, "pdl_sdbx_ key on Live environment must throw InvalidArgumentException");
});

runTest("paddle_config includes syncPaddleTax option with default yes", function() {
    $config = paddle_config();
    assertTrue(isset($config['syncPaddleTax']), "syncPaddleTax must be present in paddle_config");
    assertEquals('yes', $config['syncPaddleTax']['Default']);
    assertEquals('yesno', $config['syncPaddleTax']['Type']);
});

runTest("paddle_config includes autoProvision option with default yes", function() {
    $config = paddle_config();
    assertTrue(isset($config['autoProvision']), "autoProvision must be present in paddle_config");
    assertEquals('yes', $config['autoProvision']['Default']);
    assertEquals('yesno', $config['autoProvision']['Type']);
});

runTest("CurrencyService::convertFromUsd accurately calculates native amounts", function() {
    $resUsd = CurrencyService::convertFromUsd(10.00, 'USD');
    assertEquals(10.00, $resUsd['target_amount']);
    assertEquals('USD', $resUsd['target_currency']);

    $resZero = CurrencyService::convertFromUsd(0.00, 'EUR');
    assertEquals(0.00, $resZero['target_amount']);
});

runTest("Compatibility::resolveCredentials self-heals shifted API keys and cleans seller IDs", function() {
    $raw = [
        'environment' => 'Live (Production)',
        'sandboxSellerId' => 'pdl_sdbx_apikey_shifted_key',
        'sandboxApiKey' => '',
        'liveSellerId' => '1ac56f9f08ece3d4606a6d955bae955f82c6ea24598fd7781f9e0e13b0a20014a7d7eb54e5ddf9c871818e92a17adeef',
        'liveApiKey' => 'pdl_live_apikey_valid_key_123',
    ];

    $resolved = Compatibility::resolveCredentials($raw);
    assertEquals('live', $resolved['environment']);
    assertEquals('pdl_sdbx_apikey_shifted_key', $resolved['sandboxApiKey']);
    assertEquals('', $resolved['sandboxSellerId']); // Shifted API key moved out of seller ID
    assertEquals('', $resolved['liveSellerId']);    // 96-char hex cipher removed from seller ID
    assertEquals('pdl_live_apikey_valid_key_123', $resolved['liveApiKey']);
});

runTest("ApiClient has updateProduct method with PATCH /products destination", function() {
    $client = new ApiClient('sandbox', 'pdl_sand_testkey123');
    $refMethod = new \ReflectionMethod($client, 'updateProduct');
    assertEquals(2, $refMethod->getNumberOfParameters());
    $params = $refMethod->getParameters();
    assertEquals('productId', $params[0]->getName());
    assertEquals('payload', $params[1]->getName());
});

runTest("ProductSyncService::getOrCreateFallbackProduct accepts name and description parameters", function() {
    require_once __DIR__ . '/../modules/gateways/paddle/lib/ProductSyncService.php';
    $refMethod = new \ReflectionMethod(\WHMCS\Module\Gateway\Paddle\ProductSyncService::class, 'getOrCreateFallbackProduct');
    assertEquals(2, $refMethod->getNumberOfParameters());
    $params = $refMethod->getParameters();
    assertEquals('name', $params[0]->getName());
    assertEquals('description', $params[1]->getName());
    assertTrue($params[0]->isDefaultValueAvailable(), "name parameter must be optional");
    assertTrue($params[1]->isDefaultValueAvailable(), "description parameter must be optional");
});

runTest("SubscriptionService::getCustomerPortalUrl supports flexible subscription and customer lookup parameters", function() {
    require_once __DIR__ . '/../modules/gateways/paddle/lib/SubscriptionService.php';
    $refMethod = new \ReflectionMethod(\WHMCS\Module\Gateway\Paddle\SubscriptionService::class, 'getCustomerPortalUrl');
    assertEquals(3, $refMethod->getNumberOfParameters());
    $params = $refMethod->getParameters();
    assertEquals('serviceId', $params[0]->getName());
    assertEquals('subscriptionId', $params[1]->getName());
    assertEquals('customerId', $params[2]->getName());
    assertTrue($params[1]->isDefaultValueAvailable(), "subscriptionId parameter must be optional");
    assertTrue($params[2]->isDefaultValueAvailable(), "customerId parameter must be optional");
});

// =============================================================================
// SUMMARY
// =============================================================================
echo "\n========================================================\n";
echo " Test Suite Execution Completed\n";
echo " Total Tests: {$testsRun}\n";
echo " Passed:      {$passed}\n";
echo " Failed:      {$failed}\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
