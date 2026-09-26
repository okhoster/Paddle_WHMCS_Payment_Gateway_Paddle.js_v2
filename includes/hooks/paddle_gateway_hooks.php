<?php
/**
 * Paddle Billing Payment Gateway WHMCS Hooks
 *
 * Handles Client Area subscription info display, Customer Portal links,
 * and automatic Paddle subscription cancellation on service termination.
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

// Require Module Libraries
require_once __DIR__ . '/../../modules/gateways/paddle/lib/Compatibility.php';
require_once __DIR__ . '/../../modules/gateways/paddle/lib/Security.php';
require_once __DIR__ . '/../../modules/gateways/paddle/lib/Logger.php';
require_once __DIR__ . '/../../modules/gateways/paddle/lib/Database.php';
require_once __DIR__ . '/../../modules/gateways/paddle/lib/CurrencyService.php';
require_once __DIR__ . '/../../modules/gateways/paddle/lib/ApiClient.php';
require_once __DIR__ . '/../../modules/gateways/paddle/lib/CustomerService.php';
require_once __DIR__ . '/../../modules/gateways/paddle/lib/SubscriptionService.php';
require_once __DIR__ . '/../../modules/gateways/paddle/lib/WebhookHandler.php';

if (file_exists(__DIR__ . '/../../includes/orderfunctions.php')) {
    require_once __DIR__ . '/../../includes/orderfunctions.php';
}
if (file_exists(__DIR__ . '/../../includes/modulefunctions.php')) {
    require_once __DIR__ . '/../../includes/modulefunctions.php';
}
if (file_exists(__DIR__ . '/../../includes/registrarfunctions.php')) {
    require_once __DIR__ . '/../../includes/registrarfunctions.php';
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\Paddle\Database;
use WHMCS\Module\Gateway\Paddle\ApiClient;
use WHMCS\Module\Gateway\Paddle\SubscriptionService;
use WHMCS\Module\Gateway\Paddle\WebhookHandler;
use WHMCS\Module\Gateway\Paddle\Logger;
use WHMCS\Module\Gateway\Paddle\Compatibility;

/**
 * Hook: ClientAreaPageProductDetails
 * Handles Customer Portal instant redirect action and assigns variables for Lagom 2 & Digit themes
 */
add_hook('ClientAreaPageProductDetails', 1, function (array $vars) {
    if (!Compatibility::isGatewayActive('paddle')) {
        return [];
    }

    $serviceId = (int)($vars['serviceid'] ?? ($vars['id'] ?? ($_GET['id'] ?? 0)));
    if ($serviceId <= 0) {
        return [];
    }

    // Intercept instant customer portal action redirect
    if (!empty($_GET['paddle_action']) && $_GET['paddle_action'] === 'customer_portal') {
        $loggedInUid = (int)($_SESSION['uid'] ?? 0);
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        $isAdmin = Compatibility::isAdminAuthenticated();

        if (!$service || ($service->userid != $loggedInUid && !$isAdmin)) {
            header('Location: clientarea.php?action=services');
            exit;
        }

        $gatewayParams = Compatibility::getGatewayConfig('paddle');
        $env = Compatibility::normalizeEnvironment($gatewayParams['environment'] ?? 'sandbox');
        $apiKey = ($env === 'live') ? ($gatewayParams['liveApiKey'] ?? '') : ($gatewayParams['sandboxApiKey'] ?? '');
        $sellerId = ($env === 'live') ? ($gatewayParams['liveSellerId'] ?? '') : ($gatewayParams['sandboxSellerId'] ?? '');

        if (empty($apiKey)) {
            $_SESSION['paddle_portal_error'] = 'Paddle API key is not configured in WHMCS gateway settings.';
            header('Location: clientarea.php?action=productdetails&id=' . $serviceId);
            exit;
        }

        try {
            $apiClient = new ApiClient($env, $apiKey, 'errors', $sellerId);
            $subService = new SubscriptionService($apiClient);
            $portalUrl = $subService->getCustomerPortalUrl($serviceId);

            if (!empty($portalUrl)) {
                header('Location: ' . $portalUrl);
                exit;
            } else {
                $_SESSION['paddle_portal_error'] = 'Could not generate an active Customer Portal session for this service.';
            }
        } catch (\Throwable $e) {
            $_SESSION['paddle_portal_error'] = 'Paddle Customer Portal error: ' . $e->getMessage();
        }

        header('Location: clientarea.php?action=productdetails&id=' . $serviceId);
        exit;
    }

    $portalActionUrl = "clientarea.php?action=productdetails&id={$serviceId}&paddle_action=customer_portal";
    return [
        'paddlePortalActionUrl' => $portalActionUrl,
    ];
});

/**
 * Hook: ClientAreaProductDetailsOutput
 * Display Paddle Subscription details & Customer Portal button on client service page (Lagom 2 & Digit theme compatible)
 */
add_hook('ClientAreaProductDetailsOutput', 1, function (array $vars) {
    if (!Compatibility::isGatewayActive('paddle')) {
        return '';
    }

    $serviceId = (int)($vars['serviceid'] ?? ($vars['id'] ?? ($_GET['id'] ?? 0)));
    if ($serviceId <= 0) {
        return '';
    }

    Database::initSchema();

    $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
    if (!$service) {
        return '';
    }

    // Check if subscription exists for this service in mod_paddle_subscriptions
    $sub = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
        ->where('service_id', $serviceId)
        ->first();

    if (!$sub && !empty($service->subscriptionid)) {
        $sub = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
            ->where('paddle_subscription_id', $service->subscriptionid)
            ->first();
    }

    // Check if this service is associated with Paddle
    $isPaddleService = ($service->paymentmethod === 'paddle') || !empty($sub) || !empty($service->subscriptionid);
    if (!$isPaddleService) {
        return '';
    }

    $gatewayParams = Compatibility::getGatewayConfig('paddle');
    $isPortalEnabled = in_array(
        strtolower((string)($gatewayParams['customerPortal'] ?? 'yes')),
        ['on', 'yes', '1', 'true'],
        true
    );

    if (!$isPortalEnabled) {
        return '';
    }

    $portalActionUrl = "clientarea.php?action=productdetails&id={$serviceId}&paddle_action=customer_portal";

    ob_start();
    $subscriptionId = $sub ? $sub->paddle_subscription_id : ($service->subscriptionid ?: null);
    $customerId = $sub ? $sub->paddle_customer_id : null;
    $status = $sub ? $sub->status : strtolower((string)$service->domainstatus);
    $interval = $sub ? $sub->billing_interval : strtolower((string)$service->billingcycle);
    $nextBilledAt = $sub ? $sub->next_billed_at : $service->nextduedate;
    include __DIR__ . '/../../modules/gateways/paddle/templates/client_service.tpl.php';
    return ob_get_clean();
});

/**
 * Helper to add Paddle Customer Portal link to WHMCS Sidebars (Lagom 2 & Digit theme compatible)
 */
function paddle_inject_sidebar_portal_action($sidebar): void
{
    if (!Compatibility::isGatewayActive('paddle')) {
        return;
    }

    if (!is_object($sidebar)) {
        return;
    }

    $serviceId = (int)($_GET['id'] ?? ($_GET['serviceid'] ?? 0));
    if ($serviceId <= 0) {
        return;
    }

    $gatewayParams = Compatibility::getGatewayConfig('paddle');
    $isPortalEnabled = in_array(
        strtolower((string)($gatewayParams['customerPortal'] ?? 'yes')),
        ['on', 'yes', '1', 'true'],
        true
    );

    if (!$isPortalEnabled) {
        return;
    }

    // In Lagom 2 and custom themes, look across multiple panel locations
    $panel = $sidebar->getChild('Service Details Actions');
    if (!$panel) {
        $panel = $sidebar->getChild('Actions');
    }
    if (!$panel) {
        $panel = $sidebar->getChild('Service Details Overview');
    }
    if (!$panel) {
        $panel = $sidebar->addChild('Paddle Billing Actions', [
            'label' => 'Subscription Management',
            'order' => 15,
            'icon' => 'fas fa-credit-card',
        ]);
    }

    if ($panel && !$panel->getChild('Paddle Customer Portal')) {
        $portalActionUrl = "clientarea.php?action=productdetails&id={$serviceId}&paddle_action=customer_portal";
        $panel->addChild('Paddle Customer Portal', [
            'label' => 'Manage in Customer Portal',
            'uri' => $portalActionUrl,
            'order' => 100,
            'icon' => 'fas fa-external-link-alt',
            'attributes' => [
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'class' => 'text-primary font-weight-bold',
            ],
        ]);
    }
}

/**
 * Hook: ClientAreaPrimarySidebar
 * Injects Customer Portal action into Lagom 2 Primary Sidebar / Left Nav
 */
add_hook('ClientAreaPrimarySidebar', 1, function ($primarySidebar) {
    paddle_inject_sidebar_portal_action($primarySidebar);
});

/**
 * Hook: ClientAreaSecondarySidebar
 * Injects Customer Portal action into standard WHMCS & Digit theme Secondary Sidebar
 */
add_hook('ClientAreaSecondarySidebar', 1, function ($secondarySidebar) {
    paddle_inject_sidebar_portal_action($secondarySidebar);
});

/**
 * Hook: InvoicePaid
 * Runs automatic provisioning, service activation, and sends welcome email as soon as an invoice is marked Paid
 */
add_hook('InvoicePaid', 1, function (array $vars) {
    $invoiceId = (int)($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    // Check if invoice belongs to paddle
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice || $invoice->paymentmethod !== 'paddle') {
        return;
    }

    $gatewayConfig = Compatibility::getGatewayConfig('paddle');
    $autoProvision = in_array(
        strtolower((string)($gatewayConfig['autoProvision'] ?? 'yes')),
        ['on', 'yes', '1', 'true'],
        true
    );

    if (!$autoProvision) {
        return;
    }

    $env = Compatibility::normalizeEnvironment($gatewayConfig['environment'] ?? 'sandbox');
    $apiKey = ($env === 'live') ? ($gatewayConfig['liveApiKey'] ?? '') : ($gatewayConfig['sandboxApiKey'] ?? '');
    $sellerId = ($env === 'live') ? ($gatewayConfig['liveSellerId'] ?? '') : ($gatewayConfig['sandboxSellerId'] ?? '');

    $apiClient = null;
    if (!empty($apiKey)) {
        try {
            $apiClient = new ApiClient($env, $apiKey, 'errors', $sellerId);
        } catch (\Throwable) {}
    }

    try {
        $handler = new WebhookHandler($apiClient, 'normal');
        $handler->triggerAutomaticProvisioning($invoiceId, 'hook.invoice_paid');
    } catch (\Throwable $e) {
        Logger::logTransaction('InvoicePaid Hook Provisioning Notice', ['error' => $e->getMessage(), 'invoice_id' => $invoiceId], 'Notice');
    }
});

/**
 * Hook: ServiceDelete & AfterModuleTerminate
 * Automatically cancel Paddle subscription when WHMCS service is terminated
 */
$cancelSubscriptionCallback = function (array $vars) {
    if (!Compatibility::isGatewayActive('paddle')) {
        return;
    }

    $serviceId = (int)($vars['serviceid'] ?? ($vars['params']['serviceid'] ?? 0));
    if ($serviceId <= 0) {
        return;
    }

    $gatewayParams = Compatibility::getGatewayConfig('paddle');
    $cancelOnTermination = (!empty($gatewayParams['cancelOnTermination']) && $gatewayParams['cancelOnTermination'] === 'on');

    if (!$cancelOnTermination) {
        return;
    }

    Database::initSchema();
    $sub = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
        ->where('service_id', $serviceId)
        ->where('status', '!=', 'canceled')
        ->first();

    if (!$sub || empty($sub->paddle_subscription_id)) {
        return;
    }

    $env = (strtolower($gatewayParams['environment'] ?? 'sandbox') === 'live') ? 'live' : 'sandbox';
    $apiKey = ($env === 'live') ? ($gatewayParams['liveApiKey'] ?? '') : ($gatewayParams['sandboxApiKey'] ?? '');
    $cancellationMode = $gatewayParams['cancellationMode'] ?? 'next_billing_period';

    if (empty($apiKey)) {
        return;
    }

    try {
        $apiClient = new ApiClient($env, $apiKey, 'errors');
        $subService = new SubscriptionService($apiClient);
        $subService->cancelSubscription($sub->paddle_subscription_id, $cancellationMode);

        Logger::logTransaction(
            'Paddle Subscription Canceled on Service Termination',
            [
                'service_id' => $serviceId,
                'subscription_id' => $sub->paddle_subscription_id,
                'timing' => $cancellationMode,
            ],
            'Success'
        );
    } catch (\Throwable $e) {
        Logger::logTransaction(
            'Paddle Subscription Cancellation on Termination Failed',
            [
                'service_id' => $serviceId,
                'subscription_id' => $sub->paddle_subscription_id,
                'error' => $e->getMessage(),
            ],
            'Error'
        );
    }
};

add_hook('AfterModuleTerminate', 1, $cancelSubscriptionCallback);
add_hook('ServiceDelete', 1, $cancelSubscriptionCallback);
