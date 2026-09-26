<?php
/**
 * Paddle Billing Payment Gateway for WHMCS
 *
 * Official WHMCS payment gateway module integrating the current Paddle Billing API.
 * Supports WHMCS 8.10.1 and 10.13.0 on PHP 8.1 and PHP 8.3.
 *
 * @category   PaymentGateway
 * @package    WHMCS
 * @author     OKHOSTER
 * @copyright  2026 OKHOSTER
 * @license    GPL-3.0 license
 * @link       https://okhoster.com/
 */

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

// Autoload module classes
require_once __DIR__ . '/paddle/lib/Compatibility.php';
require_once __DIR__ . '/paddle/lib/Security.php';
require_once __DIR__ . '/paddle/lib/Logger.php';
require_once __DIR__ . '/paddle/lib/Database.php';
require_once __DIR__ . '/paddle/lib/CurrencyService.php';
require_once __DIR__ . '/paddle/lib/ApiClient.php';
require_once __DIR__ . '/paddle/lib/ProductSyncService.php';
require_once __DIR__ . '/paddle/lib/CustomerService.php';
require_once __DIR__ . '/paddle/lib/SubscriptionService.php';
require_once __DIR__ . '/paddle/lib/TransactionService.php';
require_once __DIR__ . '/paddle/lib/WebhookVerifier.php';
require_once __DIR__ . '/paddle/lib/WebhookHandler.php';
require_once __DIR__ . '/paddle/lib/AdminDispatcher.php';

use WHMCS\Module\Gateway\Paddle\Compatibility;
use WHMCS\Module\Gateway\Paddle\Security;
use WHMCS\Module\Gateway\Paddle\Logger;
use WHMCS\Module\Gateway\Paddle\Database;
use WHMCS\Module\Gateway\Paddle\CurrencyService;
use WHMCS\Module\Gateway\Paddle\ApiClient;
use WHMCS\Module\Gateway\Paddle\ProductSyncService;
use WHMCS\Module\Gateway\Paddle\SubscriptionService;
use WHMCS\Module\Gateway\Paddle\TransactionService;
use WHMCS\Module\Gateway\Paddle\AdminDispatcher;


/**
 * Define Gateway Metadata
 *
 * @return array
 */
function paddle_MetaData(): array
{
    return [
        'DisplayName' => 'Paddle Billing',
        'APIVersion' => '1.1',
        'Description' => 'Accept credit cards, PayPal, and regional payment methods worldwide via Paddle Billing (Paddle.js v2 & Paddle REST API) with automated subscription lifecycle and tax handling.',
        'Category' => 'Payments',
        'Logo' => 'paddle.png',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Define Gateway Configuration Options
 *
 * @return array
 */
function paddle_config(): array
{
    // Initialize module tables safely
    try {
        Database::initSchema();
    } catch (\Throwable) {
        // Safe fallback during initial installation
    }

    // Safely retrieve current gateway parameters without triggering fatal "Not Activated" error
    $gatewayParams = Compatibility::getGatewayConfig('paddle');

    // Check for admin diagnostic AJAX requests
    if (!empty($_REQUEST['paddle_admin_action'])) {
        $ajaxResult = AdminDispatcher::handleAdminAction($gatewayParams);
        if ($ajaxResult !== null) {
            header('Content-Type: application/json');
            echo json_encode($ajaxResult);
            exit;
        }
    }

    // Render diagnostics template safely
    ob_start();
    $config = $gatewayParams;
    include __DIR__ . '/paddle/templates/admin_diagnostics.tpl.php';
    $diagnosticsHtml = ob_get_clean();

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Paddle Billing',
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Official Paddle Billing integration supporting modern Paddle Transactions, Subscriptions, Customer Portal, and multi-currency billing in USD.',
        ],
        'diagnostics' => [
            'FriendlyName' => 'Gateway Setup & Diagnostics',
            'Description' => $diagnosticsHtml,
        ],

        'environment' => [
            'FriendlyName' => 'Environment',
            'Type' => 'dropdown',
            'Options' => [
                'sandbox' => 'Sandbox (Test Mode)',
                'live' => 'Live (Production)',
            ],
            'Default' => 'sandbox',
            'Description' => 'Select Sandbox for testing or Live for production.',
        ],
        'sandboxSellerId' => [
            'FriendlyName' => 'Sandbox Seller ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'Paddle Sandbox Seller / Vendor ID (e.g. 12345). Found under Paddle Dashboard > Developer Tools > Authentication.',
        ],
        'sandboxApiKey' => [
            'FriendlyName' => 'Sandbox API Key',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Paddle Sandbox API Key (starts with pdl_sand_...)',
        ],
        'sandboxClientToken' => [
            'FriendlyName' => 'Sandbox Client-Side Token',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Paddle Sandbox Client-side Token for Paddle.js (starts with test_...)',
        ],
        'sandboxWebhookSecret' => [
            'FriendlyName' => 'Sandbox Webhook Secret',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Sandbox Notification Destination Secret Key (starts with pdl_ntfset_...)',
        ],
        'liveSellerId' => [
            'FriendlyName' => 'Live Seller ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'Paddle Live Seller / Vendor ID (e.g. 12345). Found under Paddle Dashboard > Developer Tools > Authentication.',
        ],
        'liveApiKey' => [
            'FriendlyName' => 'Live API Key',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Paddle Live API Key (starts with pdl_live_...)',
        ],
        'liveClientToken' => [
            'FriendlyName' => 'Live Client-Side Token',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Paddle Live Client-side Token for Paddle.js (starts with live_...)',
        ],
        'liveWebhookSecret' => [
            'FriendlyName' => 'Live Webhook Secret',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Live Notification Destination Secret Key (starts with pdl_ntfset_...)',
        ],
        'oneTimeProductId' => [
            'FriendlyName' => 'One-Time Product ID',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Optional Paddle Product ID (pro_...) used for dynamic one-time custom invoices. If left empty, a fallback product is created automatically.',
        ],
        'autoSyncProducts' => [
            'FriendlyName' => 'Automatic Product & Price Creation',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Automatically create matching Paddle Products and Prices when customers purchase products or invoices in WHMCS.',
        ],
        'syncPaddleTax' => [
            'FriendlyName' => 'Record Paddle Tax on Invoice',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Automatically record taxes calculated and collected by Paddle (VAT/GST/Sales Tax) onto the WHMCS invoice upon payment.',
        ],
        'autoProvision' => [
            'FriendlyName' => 'Automatic Product Provisioning',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Automatically accept pending orders and run module provisioning (CreateAccount) when an invoice is paid.',
        ],
        'checkoutMode' => [
            'FriendlyName' => 'Checkout Mode',
            'Type' => 'dropdown',
            'Options' => [
                'overlay' => 'Overlay Modal (Recommended)',
                'inline' => 'Inline Checkout',
            ],
            'Default' => 'overlay',
            'Description' => 'Paddle.js checkout display mode.',
        ],

        'currencyNotice' => [
            'FriendlyName' => 'Target Gateway Currency',
            'Type' => 'description',
            'Description' => '<span style="color:#059669; font-weight:bold;">Fixed to USD</span>. Invoices in non-USD currencies (EUR, GBP, PKR, INR, etc.) will automatically be converted to USD at checkout based on WHMCS configured exchange rates. Original invoice currency is preserved.',
        ],
        'cancelOnTermination' => [
            'FriendlyName' => 'Subscription Cancellation',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Cancel Paddle subscription when WHMCS service is terminated.',
        ],
        'cancellationMode' => [
            'FriendlyName' => 'Cancellation Timing',
            'Type' => 'dropdown',
            'Options' => [
                'next_billing_period' => 'End of Billing Period (Recommended)',
                'immediately' => 'Immediately',
            ],
            'Default' => 'next_billing_period',
            'Description' => 'When service termination cancels the subscription on Paddle.',
        ],
        'prorationMode' => [
            'FriendlyName' => 'Upgrade Proration Mode',
            'Type' => 'dropdown',
            'Options' => [
                'prorated_immediately' => 'Prorated Immediately',
                'prorated_next_billing_period' => 'Prorated Next Billing Period',
                'full_immediately' => 'Full Immediately',
                'full_next_billing_period' => 'Full Next Billing Period',
                'do_not_bill' => 'Do Not Bill',
            ],
            'Default' => 'prorated_immediately',
            'Description' => 'Proration behavior when recurring services are upgraded or downgraded.',
        ],
        'customerPortal' => [
            'FriendlyName' => 'Enable Customer Portal',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Display Customer Portal management link in Client Area service details.',
        ],
        'webhookLogging' => [
            'FriendlyName' => 'Webhook Logging Level',
            'Type' => 'dropdown',
            'Options' => [
                'off' => 'Off',
                'errors' => 'Errors Only',
                'normal' => 'Normal (Recommended)',
                'debug' => 'Debug (All Events)',
            ],
            'Default' => 'normal',
            'Description' => 'Level of detail recorded in WHMCS Gateway Log for webhooks.',
        ],
        'apiLogging' => [
            'FriendlyName' => 'API Logging Level',
            'Type' => 'dropdown',
            'Options' => [
                'off' => 'Off',
                'errors' => 'Errors Only (Recommended)',
                'debug' => 'Debug (All API Requests)',
            ],
            'Default' => 'errors',
            'Description' => 'Level of detail recorded for Paddle API requests.',
        ],
    ];
}

/**
 * Generate payment button and checkout integration for an invoice
 *
 * @param array $params WHMCS Gateway parameters
 * @return string HTML/JavaScript output for the invoice page
 */
function paddle_link(array $params): string
{
    $env = Compatibility::normalizeEnvironment($params['environment'] ?? 'sandbox');
    $apiKey = ($env === 'live') ? ($params['liveApiKey'] ?? '') : ($params['sandboxApiKey'] ?? '');
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
    $clientToken = ($env === 'live') ? ($params['liveClientToken'] ?? '') : ($params['sandboxClientToken'] ?? '');
    $sellerId = ($env === 'live') ? ($params['liveSellerId'] ?? '') : ($params['sandboxSellerId'] ?? '');
    $apiLogLevel = $params['apiLogging'] ?? 'errors';

    if (empty($apiKey) || empty($clientToken)) {
        return '<div class="alert alert-danger">Paddle Billing gateway is not properly configured. Please contact support.</div>';
    }

    try {
        $apiClient = new ApiClient($env, $apiKey, $apiLogLevel, $sellerId);
        $txnService = new TransactionService($apiClient);

        // Create transaction in Paddle
        $txnResult = $txnService->createInvoiceTransaction($params);

        $transactionId = $txnResult['transaction_id'];
        $usdAmount = $txnResult['usd_amount'];
        $originalAmount = $txnResult['original_amount'];
        $originalCurrency = $txnResult['original_currency'];
        $invoiceId = (int)$params['invoiceid'];
        $returnUrl = $params['returnurl'] ?? Compatibility::getSystemUrl() . 'viewinvoice.php?id=' . $invoiceId;
        $checkoutMode = $params['checkoutMode'] ?? 'overlay';

        // Render checkout template
        ob_start();
        $environment = $env;
        include __DIR__ . '/paddle/templates/checkout.tpl.php';
        return ob_get_clean();
    } catch (\Throwable $e) {
        Logger::logTransaction(
            'Paddle Checkout Initialization Error',
            ['error' => $e->getMessage(), 'invoice_id' => $params['invoiceid'] ?? 0],
            'Error'
        );

        return '<div class="alert alert-danger">' .
            'Unable to initialize payment with Paddle. Please try again or contact support.' .
            '</div>';
    }
}

/**
 * Handle Admin Refund Request from WHMCS Invoice
 *
 * @param array $params WHMCS Gateway parameters
 * @return array Standard WHMCS refund response
 */
function paddle_refund(array $params): array
{
    $env = (strtolower($params['environment'] ?? 'sandbox') === 'live') ? 'live' : 'sandbox';
    $apiKey = ($env === 'live') ? ($params['liveApiKey'] ?? '') : ($params['sandboxApiKey'] ?? '');
    $apiLogLevel = $params['apiLogging'] ?? 'errors';

    $transId = trim((string)($params['transid'] ?? ''));
    $refundAmount = (float)($params['amount'] ?? 0.00);
    $currency = $params['currency'] ?? 'USD';

    if (empty($transId)) {
        return ['status' => 'declined', 'rawdata' => 'No transaction ID supplied for refund.'];
    }

    try {
        $apiClient = new ApiClient($env, $apiKey, $apiLogLevel);

        // Convert refund amount to USD cents
        $conversion = CurrencyService::convertToUsd($refundAmount, $currency);
        $refundCents = $conversion['usd_cents'];

        $adjustmentPayload = [
            'action' => 'refund',
            'transaction_id' => $transId,
            'reason' => 'Refund processed via WHMCS Admin',
            'type' => 'full', // Paddle accepts 'full' or 'partial'
        ];

        $res = $apiClient->createAdjustment($adjustmentPayload);
        $adjustmentId = $res['id'] ?? $transId;

        Logger::logTransaction('Paddle Refund Initiated', ['trans_id' => $transId, 'adjustment_id' => $adjustmentId], 'Success');

        return [
            'status' => 'success',
            'transid' => $adjustmentId,
            'rawdata' => $res,
        ];
    } catch (\Throwable $e) {
        Logger::logTransaction('Paddle Refund Error', ['trans_id' => $transId, 'error' => $e->getMessage()], 'Error');
        return [
            'status' => 'declined',
            'rawdata' => $e->getMessage(),
        ];
    }
}

/**
 * Cancel recurring subscription on Paddle
 *
 * @param array $params WHMCS Gateway parameters
 * @return array ['status' => 'success'] or ['status' => 'error']
 */
function paddle_cancelSubscription(array $params): array
{
    $env = (strtolower($params['environment'] ?? 'sandbox') === 'live') ? 'live' : 'sandbox';
    $apiKey = ($env === 'live') ? ($params['liveApiKey'] ?? '') : ($params['sandboxApiKey'] ?? '');
    $apiLogLevel = $params['apiLogging'] ?? 'errors';

    $subscriptionId = trim((string)($params['subscriptionID'] ?? ''));
    $cancellationMode = $params['cancellationMode'] ?? 'next_billing_period';

    if (empty($subscriptionId)) {
        return ['status' => 'error', 'rawdata' => 'No Subscription ID provided'];
    }

    try {
        $apiClient = new ApiClient($env, $apiKey, $apiLogLevel);
        $subService = new SubscriptionService($apiClient);

        $res = $subService->cancelSubscription($subscriptionId, $cancellationMode);

        return [
            'status' => 'success',
            'rawdata' => $res,
        ];
    } catch (\Throwable $e) {
        Logger::logTransaction('Cancel Subscription Error', ['subscription_id' => $subscriptionId, 'error' => $e->getMessage()], 'Error');
        return [
            'status' => 'error',
            'rawdata' => $e->getMessage(),
        ];
    }
}
