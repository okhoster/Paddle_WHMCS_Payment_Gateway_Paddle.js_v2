<?php
/**
 * Paddle Billing Payment Gateway Webhook Callback Handler
 *
 * Receives and processes asynchronous webhook notifications from Paddle Billing.
 * Validates HMAC-SHA256 signatures, enforces idempotency, validates amounts,
 * synchronizes taxes, and records payments in WHMCS.
 *
 * @category   PaymentGateway
 * @package    WHMCS
 * @author     OKHOSTER
 * @copyright  2026 OKHOSTER
 * @license    GPL-3.0 license
 * @link       https://okhoster.com/
 */

// Capture raw request body immediately before any buffers or middleware
$rawBody = file_get_contents('php://input');

// Require WHMCS core files
if (file_exists(__DIR__ . '/../../../init.php')) {
    require_once __DIR__ . '/../../../init.php';
}
if (file_exists(__DIR__ . '/../../../includes/gatewayfunctions.php')) {
    require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
}
if (file_exists(__DIR__ . '/../../../includes/invoicefunctions.php')) {
    require_once __DIR__ . '/../../../includes/invoicefunctions.php';
}
if (file_exists(__DIR__ . '/../../../includes/orderfunctions.php')) {
    require_once __DIR__ . '/../../../includes/orderfunctions.php';
}
if (file_exists(__DIR__ . '/../../../includes/modulefunctions.php')) {
    require_once __DIR__ . '/../../../includes/modulefunctions.php';
}
if (file_exists(__DIR__ . '/../../../includes/registrarfunctions.php')) {
    require_once __DIR__ . '/../../../includes/registrarfunctions.php';
}

// Require Module Libraries
require_once __DIR__ . '/../paddle/lib/Compatibility.php';
require_once __DIR__ . '/../paddle/lib/Security.php';
require_once __DIR__ . '/../paddle/lib/Logger.php';
require_once __DIR__ . '/../paddle/lib/Database.php';
require_once __DIR__ . '/../paddle/lib/CurrencyService.php';
require_once __DIR__ . '/../paddle/lib/ApiClient.php';
require_once __DIR__ . '/../paddle/lib/CustomerService.php';
require_once __DIR__ . '/../paddle/lib/SubscriptionService.php';
require_once __DIR__ . '/../paddle/lib/WebhookVerifier.php';
require_once __DIR__ . '/../paddle/lib/WebhookHandler.php';

use WHMCS\Module\Gateway\Paddle\Security;
use WHMCS\Module\Gateway\Paddle\Logger;
use WHMCS\Module\Gateway\Paddle\ApiClient;
use WHMCS\Module\Gateway\Paddle\WebhookVerifier;
use WHMCS\Module\Gateway\Paddle\WebhookHandler;
use WHMCS\Module\Gateway\Paddle\Compatibility;

header('Content-Type: application/json; charset=utf-8');

// 1. Enforce HTTP POST method
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// 2. Validate request body presence and size
if ($rawBody === false || strlen($rawBody) === 0) {
    Logger::logTransaction('Paddle Webhook Error', ['error' => 'Empty raw request body received.'], 'Error');
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

if (strlen($rawBody) > Security::MAX_WEBHOOK_PAYLOAD_BYTES) {
    Logger::logTransaction('Paddle Webhook Error', ['error' => 'Payload size exceeded 1MB limit.'], 'Payload Too Large');
    http_response_code(413);
    echo json_encode(['error' => 'Payload too large']);
    exit;
}

// 3. Retrieve Paddle-Signature HTTP Header across multiple server environments
$signatureHeader = $_SERVER['HTTP_PADDLE_SIGNATURE'] 
    ?? $_SERVER['REDIRECT_HTTP_PADDLE_SIGNATURE'] 
    ?? $_SERVER['PADDLE_SIGNATURE'] 
    ?? $_SERVER['HTTP_SIGNATURE'] 
    ?? null;

if (empty($signatureHeader) && function_exists('getallheaders')) {
    $allHeaders = getallheaders();
    if (is_array($allHeaders)) {
        foreach ($allHeaders as $hKey => $hVal) {
            if (strcasecmp($hKey, 'Paddle-Signature') === 0) {
                $signatureHeader = (string)$hVal;
                break;
            }
        }
    }
}

if (empty($signatureHeader)) {
    Logger::logTransaction(
        'Paddle Webhook Header Error',
        [
            'error' => 'Missing Paddle-Signature header',
            'server_keys' => array_keys($_SERVER),
        ],
        'Missing Header'
    );
    http_response_code(400);
    echo json_encode(['error' => 'Missing Paddle-Signature header']);
    exit;
}

// 4. Retrieve gateway configuration safely
$gatewayParams = Compatibility::getGatewayConfig('paddle');
$configuredEnv = Compatibility::normalizeEnvironment($gatewayParams['environment'] ?? 'sandbox');
$sandSecret = trim((string)($gatewayParams['sandboxWebhookSecret'] ?? ''));
$liveSecret = trim((string)($gatewayParams['liveWebhookSecret'] ?? ''));

if (empty($sandSecret) && empty($liveSecret)) {
    Logger::logTransaction(
        'Paddle Webhook Config Error',
        ['error' => 'Neither Sandbox nor Live webhook secret key is configured in WHMCS gateway settings.'],
        'Config Error'
    );
    http_response_code(500);
    echo json_encode(['error' => 'Webhook secret is not configured in WHMCS']);
    exit;
}

// 5. Verify HMAC-SHA256 signature with multi-candidate testing & cross-environment fallback
$candidates = [];
if (!empty($sandSecret)) {
    $cleanSand = trim($sandSecret, " \t\n\r\0\x0B\"'");
    $candidates[] = ['env' => 'sandbox', 'secret' => $cleanSand];
    $candidates[] = ['env' => 'sandbox', 'secret' => html_entity_decode($cleanSand, ENT_QUOTES | ENT_HTML5)];
    if (function_exists('decrypt')) {
        try {
            $dec = decrypt($sandSecret);
            if (!empty($dec) && is_string($dec) && $dec !== $sandSecret) {
                $candidates[] = ['env' => 'sandbox', 'secret' => trim($dec, " \t\n\r\0\x0B\"'")];
            }
        } catch (\Throwable) {}
    }
}

if (!empty($liveSecret)) {
    $cleanLive = trim($liveSecret, " \t\n\r\0\x0B\"'");
    $candidates[] = ['env' => 'live', 'secret' => $cleanLive];
    $candidates[] = ['env' => 'live', 'secret' => html_entity_decode($cleanLive, ENT_QUOTES | ENT_HTML5)];
    if (function_exists('decrypt')) {
        try {
            $dec = decrypt($liveSecret);
            if (!empty($dec) && is_string($dec) && $dec !== $liveSecret) {
                $candidates[] = ['env' => 'live', 'secret' => trim($dec, " \t\n\r\0\x0B\"'")];
            }
        } catch (\Throwable) {}
    }
}

$verifiedEnv = null;
$matchingSecret = null;
foreach ($candidates as $cand) {
    if (!empty($cand['secret']) && WebhookVerifier::verify($rawBody, $signatureHeader, $cand['secret'], 86400)) {
        $verifiedEnv = $cand['env'];
        $matchingSecret = $cand['secret'];
        break;
    }
}

if ($verifiedEnv !== null && $verifiedEnv !== $configuredEnv) {
    Logger::logTransaction(
        'Paddle Webhook Cross-Environment Match',
        [
            'configured_environment' => $configuredEnv,
            'verified_webhook_environment' => $verifiedEnv,
            'notice' => "Webhook was verified using the {$verifiedEnv} secret key.",
        ],
        'Notice'
    );
}

if ($verifiedEnv === null) {
    $isSandKeyAnApiKey = str_starts_with($sandSecret, 'pdl_sdbx_') || str_starts_with($sandSecret, 'pdl_sand_');
    $isLiveKeyAnApiKey = str_starts_with($liveSecret, 'pdl_live_');

    $parsedHdr = WebhookVerifier::parseSignatureHeader($signatureHeader);
    $tsDiff = ($parsedHdr['timestamp'] !== null) ? abs(time() - $parsedHdr['timestamp']) : null;

    $diagnostics = [
        'notice' => 'Webhook signature does not match configured secret key(s).',
        'signature_header' => $signatureHeader,
        'timestamp_difference_seconds' => $tsDiff,
        'configured_environment' => $configuredEnv,
        'sandbox_secret_prefix' => !empty($sandSecret) ? substr($sandSecret, 0, 11) . '...' : 'EMPTY',
        'sandbox_secret_length' => strlen($sandSecret),
        'sandbox_key_status' => $isSandKeyAnApiKey ? 'ERROR: You entered an API Key (pdl_sdbx_...), NOT a Webhook Secret!' : (str_starts_with($sandSecret, 'pdl_ntfset_') ? 'Format OK (pdl_ntfset_...)' : 'Invalid format: must start with pdl_ntfset_'),
        'live_secret_prefix' => !empty($liveSecret) ? substr($liveSecret, 0, 11) . '...' : 'EMPTY',
        'live_secret_length' => strlen($liveSecret),
        'live_key_status' => $isLiveKeyAnApiKey ? 'ERROR: You entered an API Key (pdl_live_...), NOT a Webhook Secret!' : (str_starts_with($liveSecret, 'pdl_ntfset_') ? 'Format OK (pdl_ntfset_...)' : 'Invalid format: must start with pdl_ntfset_'),
    ];

    if ($isSandKeyAnApiKey || $isLiveKeyAnApiKey || (!str_starts_with($sandSecret, 'pdl_ntfset_') && !str_starts_with($liveSecret, 'pdl_ntfset_'))) {
        $diagnostics['CRITICAL_HOW_TO_FIX'] = 'Paddle Webhook Secret keys ALWAYS start with "pdl_ntfset_". To find it: Log in to Paddle Dashboard -> Developer Tools -> Notifications -> Notification settings -> click your Webhook endpoint -> copy Secret key.';
    }

    Logger::logTransaction('Paddle Webhook Signature Verification Failed', $diagnostics, 'Invalid Signature');
    http_response_code(401);
    echo json_encode([
        'error' => 'Invalid webhook signature',
        'details' => $diagnostics,
    ]);
    exit;
}

// 6. Safely decode JSON payload only AFTER signature has been verified
try {
    $payload = Security::safeJsonDecode($rawBody);
} catch (\Throwable $e) {
    Logger::logTransaction('Paddle Webhook Malformed JSON', ['error' => $e->getMessage()], 'Error');
    http_response_code(400);
    echo json_encode(['error' => 'Malformed JSON payload: ' . $e->getMessage()]);
    exit;
}

// 7. Dispatch to WebhookHandler
try {
    $apiKey = ($verifiedEnv === 'live') ? ($gatewayParams['liveApiKey'] ?? '') : ($gatewayParams['sandboxApiKey'] ?? '');
    $sellerId = ($verifiedEnv === 'live') ? ($gatewayParams['liveSellerId'] ?? '') : ($gatewayParams['sandboxSellerId'] ?? '');
    $apiLogLevel = $gatewayParams['apiLogging'] ?? 'errors';
    $webhookLogLevel = $gatewayParams['webhookLogging'] ?? 'normal';

    $apiClient = null;
    if (!empty($apiKey)) {
        try {
            $apiClient = new ApiClient($verifiedEnv, $apiKey, $apiLogLevel, $sellerId);
        } catch (\Throwable $e) {
            Logger::logTransaction('Paddle ApiClient Notice', ['notice' => 'ApiClient initialization notice: ' . $e->getMessage()], 'Notice');
        }
    }

    $handler = new WebhookHandler($apiClient, $webhookLogLevel);
    $result = $handler->handle($payload, $rawBody);

    http_response_code($result['http_code'] ?? 200);
    echo json_encode($result);
    exit;
} catch (\Throwable $e) {
    Logger::logTransaction('Paddle Webhook Fatal Handler Error', ['error' => $e->getMessage()], 'Error');
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error processing webhook']);
    exit;
}
