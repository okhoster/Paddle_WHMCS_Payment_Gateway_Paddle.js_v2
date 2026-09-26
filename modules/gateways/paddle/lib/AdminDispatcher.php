<?php
/**
 * Paddle Billing Payment Gateway for WHMCS
 *
 * @category   PaymentGateway
 * @package    WHMCS
 * @author     OKHOSTER
 * @copyright  2026 OKHOSTER
 * @license    GPL-3.0 license
 * @link       https://okhoster.com/
 */

namespace WHMCS\Module\Gateway\Paddle;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Admin Area Action Dispatcher and Diagnostics Controller
 */
final class AdminDispatcher
{
    /**
     * Handle admin AJAX/POST diagnostic actions safely
     *
     * @param array $params Gateway configuration parameters
     * @return array|null JSON-serializable array if action was handled
     */
    public static function handleAdminAction(array $params): ?array
    {
        if (!Compatibility::isAdminAuthenticated()) {
            return null;
        }

        $action = $_REQUEST['paddle_admin_action'] ?? null;
        if (empty($action)) {
            return null;
        }

        // Validate CSRF token
        $csrfToken = $_REQUEST['paddle_csrf_token'] ?? null;
        if (!Security::validateCsrfToken('paddle_admin', $csrfToken)) {
            return [
                'status' => 'error',
                'message' => 'Invalid or expired CSRF security token. Please refresh the page and try again.',
            ];
        }

        $requestedEnv = $_REQUEST['environment'] ?? null;
        $env = !empty($requestedEnv)
            ? Compatibility::normalizeEnvironment($requestedEnv)
            : Compatibility::normalizeEnvironment($params['environment'] ?? 'sandbox');

        // Check if API key was sent in request (from current form input on screen) or stored in config
        $postedApiKey = trim((string)($_REQUEST['apiKey'] ?? ''));
        if (empty($postedApiKey)) {
            $postedApiKey = ($env === 'live')
                ? trim((string)($_REQUEST['liveApiKey'] ?? ''))
                : trim((string)($_REQUEST['sandboxApiKey'] ?? ''));
        }

        // If a valid key was typed on screen, use it; otherwise use resolved params
        if (!empty($postedApiKey) && (str_starts_with($postedApiKey, 'pdl_sand_') || str_starts_with($postedApiKey, 'pdl_sdbx_') || str_starts_with($postedApiKey, 'pdl_live_'))) {
            $apiKey = $postedApiKey;
        } else {
            $apiKey = ($env === 'live') ? ($params['liveApiKey'] ?? '') : ($params['sandboxApiKey'] ?? '');
        }

        // Ensure key is decrypted if still encrypted
        if (function_exists('decrypt') && !str_starts_with($apiKey, 'pdl_')) {
            try {
                $dec = decrypt($apiKey);
                if (!empty($dec) && str_starts_with($dec, 'pdl_')) {
                    $apiKey = $dec;
                }
            } catch (\Throwable) {
                // Keep original
            }
        }

        // Check if Seller ID was sent in request or stored in config
        $postedSellerId = trim((string)($_REQUEST['sellerId'] ?? ''));
        if (empty($postedSellerId)) {
            $postedSellerId = ($env === 'live')
                ? trim((string)($_REQUEST['liveSellerId'] ?? ''))
                : trim((string)($_REQUEST['sandboxSellerId'] ?? ''));
        }
        $sellerId = !empty($postedSellerId) ? $postedSellerId : (($env === 'live') ? ($params['liveSellerId'] ?? '') : ($params['sandboxSellerId'] ?? ''));

        // Sanitize sellerId
        if (!empty($sellerId) && (!is_numeric($sellerId) || strlen($sellerId) > 20)) {
            $sellerId = '';
        }

        $isValidKey = false;
        if ($env === 'live') {
            $isValidKey = str_starts_with($apiKey, 'pdl_live_');
            $expectedPrefix = 'pdl_live_ (e.g. pdl_live_apikey_...)';
        } else {
            $isValidKey = str_starts_with($apiKey, 'pdl_sdbx_') || str_starts_with($apiKey, 'pdl_sand_');
            $expectedPrefix = 'pdl_sdbx_ or pdl_sand_ (e.g. pdl_sdbx_apikey_...)';
        }

        if (empty($apiKey)) {
            return [
                'status' => 'error',
                'message' => 'Paddle API Key is missing. Please enter your ' . strtoupper($env) . ' API Key (starts with ' . $expectedPrefix . ') and save settings.',
            ];
        }

        if (!$isValidKey) {
            return [
                'status' => 'error',
                'message' => 'Invalid API Key format for ' . strtoupper($env) . ' environment. Key must start with ' . $expectedPrefix . '. Please check your credentials in Paddle Dashboard under Developer Tools > Authentication.',
            ];
        }

        try {
            $apiClient = new ApiClient($env, $apiKey, 'errors', $sellerId);
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'API Client Initialization Error: ' . $e->getMessage(),
            ];
        }

        switch ($action) {
            case 'test_connection':
                return self::actionTestConnection($apiClient, $env, $sellerId);

            case 'validate_price':
                $priceId = trim((string)($_REQUEST['price_id'] ?? ''));
                return self::actionValidatePrice($apiClient, $priceId);

            case 'sync_products':
                return self::actionSyncProducts($apiClient);

            case 'get_diagnostics':
                return self::actionGetDiagnostics($params, $apiClient);

            default:
                return [
                    'status' => 'error',
                    'message' => 'Unknown admin action: ' . Security::escapeHtml($action),
                ];
        }
    }

    /**
     * Synchronize all WHMCS products and prices to Paddle
     */
    private static function actionSyncProducts(ApiClient $apiClient): array
    {
        try {
            $syncService = new ProductSyncService($apiClient);
            $stats = $syncService->syncAllWhmcsProducts();

            $errorCount = count($stats['errors'] ?? []);
            $msg = "Synchronized {$stats['products_synced']} products and {$stats['prices_synced']} prices with Paddle!";
            if ($errorCount > 0) {
                $msg .= " ({$errorCount} non-fatal item notices recorded in logs)";
            }

            return [
                'status' => 'success',
                'message' => $msg,
                'details' => $stats,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Product synchronization failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Test connection to Paddle API
     */
    private static function actionTestConnection(ApiClient $apiClient, string $env, string $sellerId = ''): array
    {
        $startTime = microtime(true);
        try {
            $res = $apiClient->testConnection();
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $msg = "Successfully connected to Paddle Billing " . strtoupper($env) . " API! (Response time: {$duration}ms)";
            if (!empty($sellerId)) {
                $msg .= " | Seller ID: " . Security::escapeHtml($sellerId);
            }

            return [
                'status' => 'success',
                'message' => $msg,
                'environment' => $env,
                'seller_id' => $sellerId,
                'response_time_ms' => $duration,
            ];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();

            if (str_contains($errorMsg, 'authentication_malformed') || str_contains($errorMsg, '401') || str_contains($errorMsg, '403')) {
                $expectedPrefix = ($env === 'live') ? 'pdl_live_' : 'pdl_sand_';
                $errorMsg .= '. Please verify that your API key is correct, active, and starts with "' . $expectedPrefix . '".';
            }

            return [
                'status' => 'error',
                'message' => 'Failed to connect to Paddle: ' . $errorMsg,
                'environment' => $env,
                'seller_id' => $sellerId,
            ];
        }
    }

    /**
     * Validate a specific Paddle Price ID (pri_...) or Product ID (pro_...)
     */
    private static function actionValidatePrice(ApiClient $apiClient, string $id): array
    {
        $id = trim($id);
        if (empty($id)) {
            return ['status' => 'error', 'message' => 'Please provide a Paddle Price ID (pri_...) or Product ID (pro_...) to validate.'];
        }

        // Validate Product ID
        if (str_starts_with($id, 'pro_')) {
            try {
                $product = $apiClient->getProduct($id);
                $name = $product['name'] ?? 'Untitled';
                $status = $product['status'] ?? 'active';
                $type = $product['tax_category'] ?? 'standard';

                return [
                    'status' => 'success',
                    'message' => "Paddle Product '{$name}' ({$id}) is VALID on Paddle! (Status: " . strtoupper($status) . ")",
                    'details' => [
                        'id' => $product['id'] ?? $id,
                        'name' => $name,
                        'status' => strtoupper($status),
                        'tax_category' => $type,
                        'description' => $product['description'] ?? 'None',
                    ],
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'error',
                    'message' => 'Product validation failed: ' . $e->getMessage(),
                ];
            }
        }

        // Validate Price ID
        try {
            $price = $apiClient->getPrice($id);

            $currency = $price['unit_price']['currency_code'] ?? 'USD';
            $amountCents = $price['unit_price']['amount'] ?? '0';
            $amount = CurrencyService::centsToDollars($amountCents);
            $status = $price['status'] ?? 'unknown';
            $isRecurring = !empty($price['billing_cycle']);
            $interval = $isRecurring ? ($price['billing_cycle']['frequency'] . ' ' . $price['billing_cycle']['interval']) : 'One-time';

            return [
                'status' => 'success',
                'message' => "Paddle Price {$id} is VALID and active on Paddle.",
                'details' => [
                    'id' => $price['id'],
                    'product_id' => $price['product_id'] ?? null,
                    'status' => strtoupper($status),
                    'currency' => $currency,
                    'amount' => '$' . number_format($amount, 2),
                    'billing_type' => $interval,
                    'description' => $price['description'] ?? '',
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Validation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch diagnostic overview for the Gateway configuration panel
     */
    private static function actionGetDiagnostics(array $params, ApiClient $apiClient): array
    {
        Database::initSchema();

        $env = (strtolower($params['environment'] ?? 'sandbox') === 'live') ? 'live' : 'sandbox';
        $clientToken = ($env === 'live') ? ($params['liveClientToken'] ?? '') : ($params['sandboxClientToken'] ?? '');
        $webhookSecret = ($env === 'live') ? ($params['liveWebhookSecret'] ?? '') : ($params['sandboxWebhookSecret'] ?? '');

        // Fetch last webhook event
        $lastEvent = Capsule::table(Database::TABLE_EVENTS)
            ->orderBy('id', 'desc')
            ->first();

        // Check compatibility
        [$isCompatible, $compatMessages] = Compatibility::checkEnvironmentCompatibility();

        return [
            'status' => 'success',
            'data' => [
                'module_version' => Compatibility::MODULE_VERSION,
                'whmcs_version' => Compatibility::getWhmcsVersion(),
                'php_version' => Compatibility::getPhpVersion(),
                'environment' => $env,
                'api_connected' => true,
                'client_token_configured' => !empty($clientToken),
                'client_token_masked' => Security::maskSecret($clientToken, 8, 4),
                'webhook_secret_configured' => !empty($webhookSecret),
                'webhook_endpoint_url' => Compatibility::getWebhookCallbackUrl(),
                'system_url' => Compatibility::getSystemUrl(),
                'last_webhook_event' => $lastEvent ? [
                    'event_id' => $lastEvent->event_id,
                    'event_type' => $lastEvent->event_type,
                    'status' => $lastEvent->status,
                    'received_at' => $lastEvent->received_at,
                ] : null,
                'environment_compatible' => $isCompatible,
                'compatibility_notices' => $compatMessages,
            ],
        ];
    }
}
