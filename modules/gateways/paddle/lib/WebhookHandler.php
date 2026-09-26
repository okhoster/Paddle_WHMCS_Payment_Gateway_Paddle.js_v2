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
 * Webhook Event Processing and WHMCS Payment Application Service
 */
final class WebhookHandler
{
    private ?ApiClient $apiClient;
    private ?SubscriptionService $subscriptionService;
    private string $logLevel;

    public function __construct(?ApiClient $apiClient = null, string $logLevel = 'normal')
    {
        $this->apiClient = $apiClient;
        $this->subscriptionService = $apiClient ? new SubscriptionService($apiClient) : null;
        $this->logLevel = $logLevel;
    }

    /**
     * Process an incoming verified webhook event
     *
     * @param array $payload Decoded event JSON payload
     * @param string $rawBody Raw unmodified HTTP body string
     * @return array ['success' => bool, 'message' => string, 'http_code' => int]
     */
    public function handle(array $payload, string $rawBody): array
    {
        $eventId = (string)($payload['event_id'] ?? '');
        $eventType = (string)($payload['event_type'] ?? '');
        $occurredAt = isset($payload['occurred_at']) ? (string)$payload['occurred_at'] : null;
        $data = $payload['data'] ?? [];

        if (empty($eventId) || empty($eventType)) {
            return [
                'success' => false,
                'message' => 'Missing event_id or event_type in webhook payload',
                'http_code' => 400,
            ];
        }

        // 1. Enforce Webhook Idempotency
        $isNew = Database::recordEventIfNew($eventId, $eventType, $occurredAt, $rawBody);
        if (!$isNew) {
            if (Database::isEventProcessed($eventId)) {
                Logger::logWebhook($eventId, $eventType, 'Ignored', 'Duplicate event already processed', [], $this->logLevel);
                return [
                    'success' => true,
                    'message' => 'Event already processed (idempotent duplicate)',
                    'http_code' => 200,
                ];
            }

            Logger::logWebhook($eventId, $eventType, 'Ignored', 'Concurrent or repeated delivery in progress', [], $this->logLevel);
            return [
                'success' => true,
                'message' => 'Event is currently being processed',
                'http_code' => 200,
            ];
        }

        // 2. Dispatch event to specialized handler
        try {
            switch ($eventType) {
                case 'transaction.paid':
                case 'transaction.completed':
                    $this->handleTransactionPaid($data, $eventId, $eventType);
                    break;

                case 'transaction.payment_failed':
                    $this->handleTransactionFailed($data, $eventId);
                    break;

                case 'transaction.canceled':
                    $this->handleTransactionCanceled($data, $eventId);
                    break;

                case 'subscription.created':
                case 'subscription.updated':
                case 'subscription.activated':
                case 'subscription.trialing':
                case 'subscription.paused':
                case 'subscription.resumed':
                    $this->handleSubscriptionLifecycle($data, $eventId, $eventType);
                    break;

                case 'subscription.canceled':
                    $this->handleSubscriptionCanceled($data, $eventId);
                    break;

                case 'adjustment.created':
                case 'adjustment.updated':
                    $this->handleAdjustment($data, $eventId, $eventType);
                    break;

                case 'customer.created':
                case 'customer.updated':
                    $this->handleCustomerLifecycle($data, $eventId);
                    break;

                default:
                    Logger::logWebhook($eventId, $eventType, 'Ignored', 'Unhandled event type recorded', [], $this->logLevel);
                    break;
            }

            Database::markEventStatus($eventId, 'processed');
            return [
                'success' => true,
                'message' => 'Event processed successfully',
                'http_code' => 200,
            ];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            Database::markEventStatus($eventId, 'failed', $errorMsg);
            Logger::logWebhook($eventId, $eventType, 'Error', 'Exception during webhook handling: ' . $errorMsg, ['exception' => (string)$e], $this->logLevel);

            return [
                'success' => false,
                'message' => 'Error processing webhook: ' . $errorMsg,
                'http_code' => 500,
            ];
        }
    }

    /**
     * Handle transaction.paid and transaction.completed events
     */
    private function handleTransactionPaid(array $data, string $eventId, string $eventType): void
    {
        $transId = (string)($data['id'] ?? '');
        if (empty($transId)) {
            throw new \RuntimeException('Missing transaction ID in transaction.paid event');
        }

        // 1. Identify associated WHMCS Invoice ID
        $invoiceId = 0;
        if (!empty($data['custom_data']['whmcs_invoice_id'])) {
            $invoiceId = (int)$data['custom_data']['whmcs_invoice_id'];
        }

        if (!$invoiceId) {
            $storedTxn = Capsule::table(Database::TABLE_TRANSACTIONS)
                ->where('paddle_transaction_id', $transId)
                ->first();
            if ($storedTxn) {
                $invoiceId = (int)$storedTxn->invoice_id;
            }
        }

        // If transaction is from a recurring subscription renewal, find unpaid renewal invoice
        $subscriptionId = $data['subscription_id'] ?? null;
        if (!$invoiceId && !empty($subscriptionId)) {
            $subRecord = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
                ->where('paddle_subscription_id', $subscriptionId)
                ->first();

            if ($subRecord && $subRecord->service_id) {
                // Find latest unpaid invoice for this service
                $serviceItem = Capsule::table('tblinvoiceitems')
                    ->join('tblinvoices', 'tblinvoiceitems.invoiceid', '=', 'tblinvoices.id')
                    ->where('tblinvoiceitems.relid', $subRecord->service_id)
                    ->where('tblinvoiceitems.type', 'Hosting')
                    ->where('tblinvoices.status', 'Unpaid')
                    ->select('tblinvoices.id')
                    ->orderBy('tblinvoices.id', 'desc')
                    ->first();

                if ($serviceItem) {
                    $invoiceId = (int)$serviceItem->id;
                }
            }
        }

        if ($invoiceId <= 0) {
            Logger::logWebhook($eventId, $eventType, 'Warning', 'No matching WHMCS invoice found for transaction ' . $transId, $data, $this->logLevel);
            return;
        }

        // 2. Fetch authoritative invoice record from WHMCS
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            Logger::logWebhook($eventId, $eventType, 'Warning', "Invoice #{$invoiceId} not found in WHMCS database for transaction {$transId}.", $data, $this->logLevel);
            return;
        }

        if (strtolower($invoice->status) === 'paid') {
            Logger::logWebhook($eventId, $eventType, 'Notice', "Invoice #{$invoiceId} is already marked as Paid.", ['trans_id' => $transId], $this->logLevel);
            return;
        }

        // Ensure invoice payment method is assigned to paddle
        if ($invoice->paymentmethod !== 'paddle') {
            Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['paymentmethod' => 'paddle']);
        }

        // 3. Prevent duplicate payment application (safe non-terminating check)
        $alreadyApplied = Capsule::table('tblaccounts')
            ->where('transid', $transId)
            ->where('gateway', 'paddle')
            ->exists();
        if ($alreadyApplied) {
            Logger::logWebhook($eventId, $eventType, 'Notice', "Transaction {$transId} already applied in tblaccounts for Invoice #{$invoiceId}.", ['trans_id' => $transId], $this->logLevel);
            return;
        }

        // 4. Strict Amount and Currency Validation
        $invoiceCurrency = Capsule::table('tblclients')
            ->join('tblcurrencies', 'tblclients.currency', '=', 'tblcurrencies.id')
            ->where('tblclients.id', $invoice->userid)
            ->value('tblcurrencies.code') ?: 'USD';

        $paddleGrandTotalCents = $data['details']['totals']['grand_total'] ?? ($data['details']['totals']['total'] ?? '0');
        $paidUsd = CurrencyService::centsToDollars($paddleGrandTotalCents);
        $paddleTaxCents = (int)($data['details']['totals']['tax'] ?? 0);
        $paddleTaxUsd = CurrencyService::centsToDollars($paddleTaxCents);
        $paddleSubtotalCents = (int)($data['details']['totals']['subtotal'] ?? 0);
        $paddleSubtotalUsd = CurrencyService::centsToDollars($paddleSubtotalCents);

        // Synchronize Paddle Approved Tax to WHMCS Invoice if enabled
        $gatewayConfig = Compatibility::getGatewayConfig('paddle');
        $syncPaddleTax = in_array(strtolower((string)($gatewayConfig['syncPaddleTax'] ?? 'yes')), ['on', 'yes', '1', 'true'], true);

        if ($syncPaddleTax && $paddleTaxUsd > 0.0) {
            $taxRatePercent = 0.0;
            if (!empty($data['details']['tax_rates_used']) && is_array($data['details']['tax_rates_used'])) {
                $firstRate = $data['details']['tax_rates_used'][0] ?? null;
                if ($firstRate) {
                    $rawRate = is_array($firstRate['tax_rate'] ?? null)
                        ? ($firstRate['tax_rate']['rate'] ?? 0)
                        : ($firstRate['tax_rate'] ?? 0);
                    $taxRatePercent = round(((float)$rawRate) * 100, 2);
                }
            }
            if ($taxRatePercent <= 0.0 && $paddleSubtotalUsd > 0.0) {
                $taxRatePercent = round(($paddleTaxUsd / $paddleSubtotalUsd) * 100, 2);
            }

            // Convert tax amount to invoice native currency
            $paddleTaxNative = ($invoiceCurrency === 'USD')
                ? $paddleTaxUsd
                : CurrencyService::convertFromUsd($paddleTaxUsd, $invoiceCurrency)['target_amount'];

            $newSubtotal = (float)$invoice->subtotal;
            $newTax2 = (float)$invoice->tax2;
            $newTotal = round($newSubtotal + $paddleTaxNative + $newTax2, 2);

            Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
                'tax' => $paddleTaxNative,
                'taxrate' => $taxRatePercent,
                'total' => $newTotal,
            ]);

            try {
                Capsule::table('tblinvoiceitems')
                    ->where('invoiceid', $invoiceId)
                    ->where('type', '!=', 'Tax')
                    ->update(['taxed' => 1]);
            } catch (\Throwable) {
                // Table schema fallback
            }

            Logger::logWebhook(
                $eventId,
                $eventType,
                'Tax Synced',
                "Applied Paddle Approved Tax to Invoice #{$invoiceId}: {$paddleTaxNative} {$invoiceCurrency} ({$taxRatePercent}%)",
                [
                    'paddle_tax_usd' => $paddleTaxUsd,
                    'tax_rate_percent' => $taxRatePercent,
                    'paddle_tax_native' => $paddleTaxNative,
                    'new_invoice_total' => $newTotal,
                ],
                $this->logLevel
            );

            // Refresh cached invoice values
            $invoice->tax = $paddleTaxNative;
            $invoice->taxrate = $taxRatePercent;
            $invoice->total = $newTotal;
        }

        $invoiceBalance = (float)$invoice->subtotal + (float)$invoice->tax + (float)$invoice->tax2 - (float)Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
        if ($invoiceBalance <= 0.0) {
            $invoiceBalance = (float)$invoice->total;
        }

        // Calculate expected USD conversion for the invoice
        $expectedConversion = CurrencyService::convertToUsd($invoiceBalance, $invoiceCurrency);
        $expectedUsd = $expectedConversion['usd_amount'];

        // Check for monetary discrepancies (allow small 5-cent threshold for minor exchange rounding)
        $diff = abs($paidUsd - $expectedUsd);
        if ($diff > 0.05 && $paidUsd < $expectedUsd) {
            Logger::logWebhook(
                $eventId,
                $eventType,
                'Amount Mismatch',
                "Underpayment detected on Invoice #{$invoiceId}. Expected USD: {$expectedUsd}, Paid USD: {$paidUsd}",
                ['expected_usd' => $expectedUsd, 'paid_usd' => $paidUsd, 'details' => $data['details'] ?? []],
                $this->logLevel
            );
            throw new \RuntimeException("Amount mismatch on Invoice #{$invoiceId}: Expected USD \${$expectedUsd}, received USD \${$paidUsd}. Payment held for review.");
        }

        // 5. Apply payment in WHMCS
        // Crucial: In WHMCS, addInvoicePayment() expects the amount in the INVOICE's native currency!
        $paymentAmount = round($invoiceBalance, 2);
        $gatewayFee = 0.00;
        if (!empty($data['details']['totals']['fee'])) {
            $gatewayFee = CurrencyService::centsToDollars($data['details']['totals']['fee']);
        }

        if (function_exists('addInvoicePayment')) {
            \addInvoicePayment(
                $invoiceId,
                $transId,
                $paymentAmount,
                $gatewayFee,
                'paddle'
            );
        }

        // Log payment transaction in WHMCS gateway log
        $auditLog = [
            'invoice_id' => $invoiceId,
            'transaction_id' => $transId,
            'paid_usd' => $paidUsd,
            'applied_currency' => $invoiceCurrency,
            'applied_amount' => $paymentAmount,
            'fee_usd' => $gatewayFee,
            'event_id' => $eventId,
        ];
        Logger::logTransaction("Payment Applied: Invoice #{$invoiceId}", $auditLog, 'Success');

        // 6. Update local transaction mapping
        Capsule::table(Database::TABLE_TRANSACTIONS)->updateOrInsert(
            ['paddle_transaction_id' => $transId],
            [
                'invoice_id' => $invoiceId,
                'client_id' => (int)$invoice->userid,
                'subscription_id' => $subscriptionId,
                'status' => 'paid',
                'whmcs_currency' => $invoiceCurrency,
                'whmcs_amount' => $paymentAmount,
                'usd_amount' => $paidUsd,
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]
        );

        // 7. Synchronize subscription if associated
        if (!empty($subscriptionId) && $this->subscriptionService && $this->apiClient) {
            try {
                $subData = $this->apiClient->getSubscription($subscriptionId);
                $this->subscriptionService->syncSubscription($subData, null, $invoiceId);
            } catch (\Throwable $e) {
                Logger::logWebhook($eventId, $eventType, 'Notice', 'Non-fatal subscription sync notice: ' . $e->getMessage(), [], $this->logLevel);
            }
        }

        // 8. Automatic Product Provisioning & Order Acceptance
        $autoProvision = in_array(
            strtolower((string)($gatewayConfig['autoProvision'] ?? 'yes')),
            ['on', 'yes', '1', 'true'],
            true
        );

        if ($autoProvision) {
            $this->triggerAutomaticProvisioning($invoiceId, $eventId);
        }
    }

    /**
     * Handle transaction.payment_failed
     */
    private function handleTransactionFailed(array $data, string $eventId): void
    {
        $transId = (string)($data['id'] ?? '');
        Capsule::table(Database::TABLE_TRANSACTIONS)
            ->where('paddle_transaction_id', $transId)
            ->update([
                'status' => 'payment_failed',
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]);

        Logger::logWebhook($eventId, 'transaction.payment_failed', 'Logged', "Payment failed for transaction {$transId}", $data, $this->logLevel);
    }

    /**
     * Handle transaction.canceled
     */
    private function handleTransactionCanceled(array $data, string $eventId): void
    {
        $transId = (string)($data['id'] ?? '');
        Capsule::table(Database::TABLE_TRANSACTIONS)
            ->where('paddle_transaction_id', $transId)
            ->update([
                'status' => 'canceled',
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]);

        Logger::logWebhook($eventId, 'transaction.canceled', 'Logged', "Transaction canceled: {$transId}", $data, $this->logLevel);
    }

    /**
     * Handle subscription creation, updates, and status transitions
     */
    private function handleSubscriptionLifecycle(array $data, string $eventId, string $eventType): void
    {
        if ($this->subscriptionService) {
            $this->subscriptionService->syncSubscription($data);
        }
        Logger::logWebhook($eventId, $eventType, 'Processed', "Subscription lifecycle event processed for " . ($data['id'] ?? ''), $data, $this->logLevel);
    }

    /**
     * Handle subscription.canceled
     */
    private function handleSubscriptionCanceled(array $data, string $eventId): void
    {
        $subId = (string)($data['id'] ?? '');
        Capsule::table(Database::TABLE_SUBSCRIPTIONS)
            ->where('paddle_subscription_id', $subId)
            ->update([
                'status' => 'canceled',
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]);

        Logger::logWebhook($eventId, 'subscription.canceled', 'Processed', "Subscription marked canceled: {$subId}", $data, $this->logLevel);
    }

    /**
     * Handle adjustments (refunds, credits, chargebacks)
     */
    private function handleAdjustment(array $data, string $eventId, string $eventType): void
    {
        $adjustmentId = (string)($data['id'] ?? '');
        $transId = (string)($data['transaction_id'] ?? '');
        $action = strtolower((string)($data['action'] ?? ''));
        $status = strtolower((string)($data['status'] ?? ''));

        if ($action === 'refund' && ($status === 'approved' || $status === 'completed')) {
            $storedTxn = Capsule::table(Database::TABLE_TRANSACTIONS)
                ->where('paddle_transaction_id', $transId)
                ->first();

            if ($storedTxn && $storedTxn->invoice_id) {
                $refundTotalCents = $data['totals']['total'] ?? '0';
                $refundUsd = CurrencyService::centsToDollars($refundTotalCents);

                Logger::logTransaction(
                    "Refund Approved: Invoice #{$storedTxn->invoice_id}",
                    [
                        'adjustment_id' => $adjustmentId,
                        'transaction_id' => $transId,
                        'refund_usd' => $refundUsd,
                        'status' => $status,
                    ],
                    'Success'
                );
            }
        } elseif ($action === 'chargeback') {
            Logger::logWebhook($eventId, $eventType, 'Alert', "Chargeback dispute recorded for transaction {$transId}", $data, $this->logLevel);
        }

        Database::markEventStatus($eventId, 'processed');
    }

    /**
     * Handle customer.created and customer.updated
     */
    private function handleCustomerLifecycle(array $data, string $eventId): void
    {
        $customerId = (string)($data['id'] ?? '');
        $whmcsClientId = (int)($data['custom_data']['whmcs_client_id'] ?? 0);

        if ($customerId && $whmcsClientId) {
            $env = $this->apiClient ? $this->apiClient->getEnvironment() : 'live';
            Capsule::table(Database::TABLE_CUSTOMERS)->updateOrInsert(
                [
                    'client_id' => $whmcsClientId,
                    'environment' => $env,
                ],
                [
                    'paddle_customer_id' => $customerId,
                    'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
                ]
            );
        }

        Database::markEventStatus($eventId, 'processed');
    }

    /**
     * Automatically accept pending orders, trigger product provisioning, activate services, and send attached welcome emails upon payment
     *
     * @param int $invoiceId WHMCS Invoice ID
     * @param string $eventId Paddle Webhook Event ID or Hook Identifier
     */
    public function triggerAutomaticProvisioning(int $invoiceId, string $eventId = 'system.provision'): void
    {
        try {
            // Find active admin user for localAPI execution context
            $adminUser = '';
            if (class_exists('\WHMCS\Database\Capsule')) {
                try {
                    $adminUser = Capsule::table('tbladmins')
                        ->where('disabled', 0)
                        ->orderBy('id', 'asc')
                        ->value('username');
                } catch (\Throwable) {
                    $adminUser = null;
                }
                if (empty($adminUser)) {
                    try {
                        $adminUser = Capsule::table('tbladmins')
                            ->orderBy('id', 'asc')
                            ->value('username');
                    } catch (\Throwable) {
                        $adminUser = null;
                    }
                }
            }
            if (empty($adminUser)) {
                $adminUser = 'admin';
            }

            // 1. Accept any pending orders associated with this invoice
            $pendingOrders = Capsule::table('tblorders')
                ->where('invoiceid', $invoiceId)
                ->where('status', 'Pending')
                ->get();

            $orderIds = Capsule::table('tblorders')->where('invoiceid', $invoiceId)->pluck('id')->toArray();

            foreach ($pendingOrders as $order) {
                if (function_exists('localAPI')) {
                    try {
                        $orderResult = \localAPI('AcceptOrder', [
                            'orderid' => (int)$order->id,
                            'autosetup' => true,
                            'sendemail' => true,
                        ], $adminUser);

                        $status = ($orderResult['result'] ?? '') === 'success' ? 'Success' : 'Notice';
                        Logger::logWebhook(
                            $eventId,
                            'auto_provision.order',
                            $status,
                            "AcceptOrder executed for Order #{$order->id}: " . ($orderResult['message'] ?? ($orderResult['result'] ?? 'completed')),
                            $orderResult,
                            $this->logLevel
                        );
                    } catch (\Throwable $e) {
                        Logger::logWebhook(
                            $eventId,
                            'auto_provision.order',
                            'Notice',
                            "AcceptOrder notice on Order #{$order->id}: " . $e->getMessage(),
                            [],
                            $this->logLevel
                        );
                    }
                }
            }

            // 2. Identify all services attached to this invoice
            $serviceRelIds = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->whereIn('type', ['Hosting', 'Service', 'Item'])
                ->pluck('relid')
                ->toArray();

            if (!empty($orderIds)) {
                $orderServiceIds = Capsule::table('tblhosting')
                    ->whereIn('orderid', $orderIds)
                    ->pluck('id')
                    ->toArray();
                $serviceRelIds = array_merge($serviceRelIds, $orderServiceIds);
            }
            $serviceRelIds = array_unique(array_filter(array_map('intval', $serviceRelIds)));

            foreach ($serviceRelIds as $serviceId) {
                if ($serviceId <= 0) {
                    continue;
                }

                $service = Capsule::table('tblhosting')
                    ->leftJoin('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                    ->where('tblhosting.id', $serviceId)
                    ->select(
                        'tblhosting.id',
                        'tblhosting.userid',
                        'tblhosting.domainstatus',
                        'tblproducts.servertype',
                        'tblproducts.welcomeemail',
                        'tblproducts.name as product_name'
                    )
                    ->first();

                if ($service && in_array(strtolower((string)$service->domainstatus), ['pending', 'pending setup', ''], true)) {
                    $provisioned = false;

                    // A. If product has a server/provisioning module attached (e.g. cpanel, plesk, directadmin)
                    if (!empty($service->servertype)) {
                        // Priority 1: Direct WHMCS core ServerCreateProduct execution
                        if (function_exists('ServerCreateProduct')) {
                            try {
                                $modResult = \ServerCreateProduct((int)$service->id);
                                if ($modResult === 'success') {
                                    $provisioned = true;
                                    Logger::logWebhook(
                                        $eventId,
                                        'auto_provision.server_create',
                                        'Success',
                                        "ServerCreateProduct succeeded for Service #{$service->id} ({$service->product_name} - {$service->servertype})",
                                        ['result' => $modResult],
                                        $this->logLevel
                                    );
                                } else {
                                    Logger::logWebhook(
                                        $eventId,
                                        'auto_provision.server_create',
                                        'Notice',
                                        "ServerCreateProduct notice for Service #{$service->id}: " . (is_string($modResult) ? $modResult : json_encode($modResult)),
                                        ['result' => $modResult],
                                        $this->logLevel
                                    );
                                }
                            } catch (\Throwable $e) {
                                Logger::logWebhook(
                                    $eventId,
                                    'auto_provision.server_create',
                                    'Notice',
                                    "ServerCreateProduct exception for Service #{$service->id}: " . $e->getMessage(),
                                    [],
                                    $this->logLevel
                                );
                            }
                        }

                        // Priority 2: localAPI ModuleCreate fallback
                        if (!$provisioned && function_exists('localAPI')) {
                            try {
                                $createResult = \localAPI('ModuleCreate', [
                                    'serviceid' => (int)$service->id,
                                ], $adminUser);

                                if (($createResult['result'] ?? '') === 'success') {
                                    $provisioned = true;
                                    Logger::logWebhook(
                                        $eventId,
                                        'auto_provision.module_create',
                                        'Success',
                                        "ModuleCreate succeeded for Service #{$service->id} ({$service->product_name} - {$service->servertype})",
                                        $createResult,
                                        $this->logLevel
                                    );
                                }
                            } catch (\Throwable $e) {
                                // Log notice
                            }
                        }
                    }

                    // B. Set Service Status to Active upon successful payment
                    Capsule::table('tblhosting')
                        ->where('id', $service->id)
                        ->update(['domainstatus' => 'Active']);

                    Logger::logWebhook(
                        $eventId,
                        'auto_provision.service',
                        'Success',
                        "Service #{$service->id} status updated to Active.",
                        ['service_id' => $service->id],
                        $this->logLevel
                    );

                    // C. Send attached product email (Welcome Email)
                    $welcomeEmailId = (int)($service->welcomeemail ?? 0);
                    $templateName = '';
                    if ($welcomeEmailId > 0) {
                        $templateName = Capsule::table('tblemailtemplates')->where('id', $welcomeEmailId)->value('name') ?: '';
                    }
                    if (empty($templateName) && !empty($service->welcomeemail) && is_string($service->welcomeemail)) {
                        $templateName = $service->welcomeemail;
                    }
                    if (empty($templateName)) {
                        // Default fallback based on product type
                        $templateName = Capsule::table('tblemailtemplates')
                            ->where('type', 'product')
                            ->where('name', 'like', '%Welcome%')
                            ->orderBy('id', 'asc')
                            ->value('name') ?: 'Hosting Account Welcome Email';
                    }

                    if (!empty($templateName)) {
                        $emailSent = false;
                        if (function_exists('sendMessage')) {
                            try {
                                $msgRes = \sendMessage($templateName, (int)$service->id);
                                $emailSent = ($msgRes !== false);
                            } catch (\Throwable $e) {
                                Logger::logWebhook($eventId, 'auto_provision.email', 'Notice', "sendMessage error: " . $e->getMessage(), [], $this->logLevel);
                            }
                        }
                        if (!$emailSent && function_exists('localAPI')) {
                            try {
                                \localAPI('SendEmail', [
                                    'messagename' => $templateName,
                                    'id' => (int)$service->id,
                                    'customtype' => 'product',
                                ], $adminUser);
                                $emailSent = true;
                            } catch (\Throwable $e) {
                                Logger::logWebhook($eventId, 'auto_provision.email', 'Notice', "localAPI SendEmail error: " . $e->getMessage(), [], $this->logLevel);
                            }
                        }

                        if ($emailSent) {
                            Logger::logWebhook(
                                $eventId,
                                'auto_provision.email',
                                'Success',
                                "Product welcome email '{$templateName}' sent to customer for Service #{$service->id}",
                                ['template' => $templateName, 'service_id' => $service->id],
                                $this->logLevel
                            );
                        }
                    }
                }
            }

            // 3. Process any domain registrations or transfers on this invoice
            $domainRelIds = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->whereIn('type', ['DomainRegister', 'DomainTransfer'])
                ->pluck('relid')
                ->toArray();

            if (!empty($orderIds)) {
                $orderDomIds = Capsule::table('tbldomains')->whereIn('orderid', $orderIds)->pluck('id')->toArray();
                $domainRelIds = array_merge($domainRelIds, $orderDomIds);
            }
            $domainRelIds = array_unique(array_filter(array_map('intval', $domainRelIds)));

            foreach ($domainRelIds as $domainId) {
                if ($domainId <= 0) {
                    continue;
                }

                $domain = Capsule::table('tbldomains')->where('id', $domainId)->first();
                if ($domain && in_array(strtolower((string)$domain->status), ['pending', 'pending registration', 'pending transfer', ''], true)) {
                    $domType = strtolower((string)$domain->type);
                    $cmd = ($domType === 'transfer') ? 'DomTransfer' : 'DomRegister';

                    if (!empty($domain->registrar)) {
                        $registered = false;
                        if (function_exists('localAPI')) {
                            try {
                                $domResult = \localAPI($cmd, ['domainid' => (int)$domain->id], $adminUser);
                                if (($domResult['result'] ?? '') === 'success') {
                                    $registered = true;
                                    Logger::logWebhook(
                                        $eventId,
                                        'auto_provision.domain',
                                        'Success',
                                        "Domain {$cmd} succeeded for Domain #{$domain->id} ({$domain->domain})",
                                        $domResult,
                                        $this->logLevel
                                    );
                                }
                            } catch (\Throwable $e) {}
                        }

                        if (!$registered) {
                            $regFn = ($cmd === 'DomTransfer') ? 'RegTransferDomain' : 'RegRegisterDomain';
                            if (function_exists($regFn)) {
                                try {
                                    $fnRes = $regFn((int)$domain->id);
                                    if ($fnRes === 'success') {
                                        Capsule::table('tbldomains')->where('id', $domain->id)->update(['status' => 'Active']);
                                    }
                                } catch (\Throwable $e) {}
                            }
                        }
                    } else {
                        // Simple domain with no registrar module -> mark Active
                        Capsule::table('tbldomains')->where('id', $domain->id)->update(['status' => 'Active']);
                    }
                }
            }

            // 4. Update order status to Active if all items are active
            if (!empty($orderIds)) {
                foreach ($orderIds as $ordId) {
                    $hasPending = Capsule::table('tblhosting')
                        ->where('orderid', $ordId)
                        ->where('domainstatus', 'Pending')
                        ->exists()
                        || Capsule::table('tbldomains')
                            ->where('orderid', $ordId)
                            ->where('status', 'Pending')
                            ->exists();

                    if (!$hasPending) {
                        Capsule::table('tblorders')
                            ->where('id', $ordId)
                            ->where('status', 'Pending')
                            ->update(['status' => 'Active']);
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::logWebhook(
                $eventId,
                'auto_provision.general',
                'Notice',
                'Automatic provisioning encountered a non-fatal notice: ' . $e->getMessage(),
                ['exception' => (string)$e],
                $this->logLevel
            );
        }
    }
}
