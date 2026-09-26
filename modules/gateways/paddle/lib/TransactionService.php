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
 * Transaction Management Service for One-Time and Recurring Checkout
 */
final class TransactionService
{
    private ApiClient $apiClient;
    private CustomerService $customerService;
    private ProductSyncService $productSyncService;

    public function __construct(ApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
        $this->customerService = new CustomerService($apiClient);
        $this->productSyncService = new ProductSyncService($apiClient);
    }

    /**
     * Create a Paddle Transaction for a given WHMCS invoice
     *
     * @param array $params WHMCS Gateway parameters
     * @return array [
     *     'transaction_id' => string,
     *     'customer_id' => string,
     *     'usd_amount' => float,
     *     'usd_cents' => string,
     *     'original_currency' => string,
     *     'original_amount' => float,
     *     'exchange_rate' => float
     * ]
     * @throws \RuntimeException On transaction creation failure
     */
    public function createInvoiceTransaction(array $params): array
    {
        Database::initSchema();

        $invoiceId = (int)($params['invoiceid'] ?? 0);
        $rawAmount = $params['amount'] ?? 0.00;
        $currency = $params['currency'] ?? 'USD';
        $clientDetails = $params['clientdetails'] ?? [];
        $clientId = (int)($clientDetails['userid'] ?? ($clientDetails['id'] ?? 0));

        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Invalid WHMCS Invoice ID.');
        }

        // 1. Perform safe decimal monetary conversion to USD
        $conversion = CurrencyService::convertToUsd($rawAmount, $currency);
        $usdAmount = $conversion['usd_amount'];
        $usdCents = $conversion['usd_cents'];
        $originalAmount = $conversion['original_amount'];
        $originalCurrency = $conversion['original_currency'];
        $exchangeRate = $conversion['exchange_rate'];

        // 2. Resolve or create Paddle Customer & Address
        $customerInfo = $this->customerService->getOrCreateCustomer($clientId, $clientDetails);
        $customerId = $customerInfo['customer_id'];
        $addressId = $customerInfo['address_id'];

        // 3. Determine if invoice contains a recurring item with a mapped or auto-created Paddle Price ID
        $mappedPriceId = $this->resolveMappedPriceId($invoiceId);
        $serviceId = $this->resolveServiceId($invoiceId);
        $autoSync = (($params['autoSyncProducts'] ?? 'on') !== 'off');

        // If not manually mapped in custom fields, automatically create/resolve Product & Price on Paddle
        if (empty($mappedPriceId) && $autoSync) {
            $pkgInfo = $this->resolvePackageAndCycle($invoiceId);
            if ($pkgInfo && $pkgInfo['package_id'] > 0) {
                try {
                    $mappedPriceId = $this->productSyncService->getOrCreatePaddlePrice(
                        $pkgInfo['package_id'],
                        $pkgInfo['billing_cycle'],
                        $usdAmount,
                        'USD'
                    );
                } catch (\Throwable $e) {
                    Logger::logTransaction('Auto-create Paddle Price Notice', ['error' => $e->getMessage()], 'Notice');
                }
            }
        }

        // 4. Construct items array
        $items = [];
        if (!empty($mappedPriceId)) {
            // Mapped or auto-created catalog price (triggers automatic Paddle subscription upon payment)
            $items[] = [
                'price_id' => $mappedPriceId,
                'quantity' => 1,
            ];
        } else {
            // Retrieve invoice line item descriptions from WHMCS for clear checkout and receipt display
            $rawItems = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->where('type', '!=', 'Tax')
                ->get();

            $itemNames = [];
            foreach ($rawItems as $item) {
                $desc = trim((string)$item->description);
                $lines = explode("\n", $desc);
                $firstLine = trim($lines[0]);
                if (!empty($firstLine)) {
                    $itemNames[] = $firstLine;
                }
            }

            $invoiceItemTitle = !empty($itemNames) ? implode(', ', $itemNames) : "Invoice #{$invoiceId}";
            if (mb_strlen($invoiceItemTitle) > 100) {
                $invoiceItemTitle = mb_substr($invoiceItemTitle, 0, 97) . '...';
            }

            $invoiceItemDescription = !empty($itemNames) ? implode("\n", $itemNames) : "Payment for Invoice #{$invoiceId}";
            if (mb_strlen($invoiceItemDescription) > 250) {
                $invoiceItemDescription = mb_substr($invoiceItemDescription, 0, 247) . '...';
            }

            $oneTimeProductId = trim((string)($params['oneTimeProductId'] ?? ''));

            if (empty($oneTimeProductId) && $autoSync) {
                try {
                    $oneTimeProductId = $this->productSyncService->getOrCreateFallbackProduct($invoiceItemTitle, $invoiceItemDescription);
                } catch (\Throwable $e) {
                    // Non-fatal
                }
            }

            $priceObject = [
                'name' => $invoiceItemTitle,
                'description' => $invoiceItemDescription,
                'unit_price' => [
                    'amount' => $usdCents,
                    'currency_code' => 'USD',
                ],
            ];

            if (!empty($oneTimeProductId)) {
                $priceObject['product_id'] = $oneTimeProductId;
            } else {
                $priceObject['product'] = [
                    'name' => $invoiceItemTitle,
                    'tax_category' => 'standard',
                ];
            }

            $items[] = [
                'price' => $priceObject,
                'quantity' => 1,
            ];
        }

        // 5. Construct custom_data with sanitized WHMCS references
        $customData = [
            'whmcs_invoice_id' => (string)$invoiceId,
            'whmcs_client_id' => (string)$clientId,
            'gateway' => 'paddle',

            'original_currency' => $originalCurrency,
            'original_amount' => (string)$originalAmount,
            'usd_amount' => (string)$usdAmount,
            'exchange_rate' => (string)$exchangeRate,
        ];

        if ($serviceId) {
            $customData['whmcs_service_id'] = (string)$serviceId;
        }

        // 6. Build transaction payload
        $payload = [
            'items' => $items,
            'customer_id' => $customerId,
            'currency_code' => 'USD',
            'custom_data' => $customData,
        ];

        if (!empty($addressId)) {
            $payload['address_id'] = $addressId;
        }

        // 7. Request transaction creation from Paddle API
        $txn = $this->apiClient->createTransaction($payload);
        $txnId = $txn['id'] ?? null;

        if (empty($txnId)) {
            throw new \RuntimeException('Paddle API did not return a valid Transaction ID.');
        }

        // 8. Store mapping in mod_paddle_transactions
        Capsule::table(Database::TABLE_TRANSACTIONS)->updateOrInsert(
            ['paddle_transaction_id' => $txnId],
            [
                'invoice_id' => $invoiceId,
                'client_id' => $clientId,
                'subscription_id' => $txn['subscription_id'] ?? null,
                'status' => strtolower((string)($txn['status'] ?? 'draft')),
                'whmcs_currency' => $originalCurrency,
                'whmcs_amount' => $originalAmount,
                'usd_amount' => $usdAmount,
                'exchange_rate' => $exchangeRate,
                'environment' => $this->apiClient->getEnvironment(),
                'paddle_created_at' => !empty($txn['created_at']) ? date('Y-m-d H:i:s', strtotime($txn['created_at'])) : Capsule::raw('CURRENT_TIMESTAMP'),
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]
        );

        return [
            'transaction_id' => $txnId,
            'customer_id' => $customerId,
            'usd_amount' => $usdAmount,
            'usd_cents' => $usdCents,
            'original_currency' => $originalCurrency,
            'original_amount' => $originalAmount,
            'exchange_rate' => $exchangeRate,
        ];
    }

    /**
     * Inspect invoice items to check if mapped to a Paddle recurring price
     *
     * @param int $invoiceId WHMCS Invoice ID
     * @return string|null Mapped Paddle Price ID if found
     */
    private function resolveMappedPriceId(int $invoiceId): ?string
    {
        if (!class_exists('\WHMCS\Database\Capsule')) {
            return null;
        }

        $items = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->get();

        foreach ($items as $item) {
            $serviceId = (int)$item->relid;
            if ($serviceId <= 0) {
                continue;
            }

            $hosting = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if (!$hosting) {
                continue;
            }

            $packageId = (int)$hosting->packageid;
            $billingCycle = strtolower(trim((string)$hosting->billingcycle));

            // Check custom field mapping on the product: paddle_price_{cycle} or paddle_price_id
            $customFields = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', $packageId)
                ->get();

            $cycleFieldName = 'paddle_price_' . str_replace(['-', ' '], '_', $billingCycle);

            foreach ($customFields as $field) {
                $fieldName = strtolower(trim((string)$field->fieldname));
                if ($fieldName === $cycleFieldName || $fieldName === 'paddle_price_id') {
                    // Check custom field values for this service
                    $fieldVal = Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $field->id)
                        ->where('relid', $serviceId)
                        ->value('value');

                    if (!empty($fieldVal) && str_starts_with(trim($fieldVal), 'pri_')) {
                        return trim($fieldVal);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Retrieve the first associated WHMCS Service ID for the invoice if any
     *
     * @param int $invoiceId WHMCS Invoice ID
     * @return int|null Service ID
     */
    private function resolveServiceId(int $invoiceId): ?int
    {
        if (!class_exists('\WHMCS\Database\Capsule')) {
            return null;
        }

        $serviceItem = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->first();

        return $serviceItem ? (int)$serviceItem->relid : null;
    }

    /**
     * Resolve WHMCS Package ID and Billing Cycle for an invoice
     *
     * @param int $invoiceId WHMCS Invoice ID
     * @return array|null ['package_id' => int, 'billing_cycle' => string, 'service_id' => int]
     */
    private function resolvePackageAndCycle(int $invoiceId): ?array
    {
        if (!class_exists('\WHMCS\Database\Capsule')) {
            return null;
        }

        $item = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->first();

        if (!$item || (int)$item->relid <= 0) {
            return null;
        }

        $serviceId = (int)$item->relid;
        $hosting = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$hosting) {
            return null;
        }

        return [
            'package_id' => (int)$hosting->packageid,
            'billing_cycle' => (string)$hosting->billingcycle,
            'service_id' => $serviceId,
        ];
    }
}

