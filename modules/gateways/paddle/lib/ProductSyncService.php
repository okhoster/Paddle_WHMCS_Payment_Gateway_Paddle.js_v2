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
 * Service to automatically create and synchronize WHMCS Products and Prices on Paddle
 */
final class ProductSyncService
{
    private ApiClient $apiClient;

    public function __construct(ApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * Get or automatically create a Paddle Product matching a WHMCS Product/Package ID
     *
     * @param int $packageId WHMCS Product ID (tblproducts.id)
     * @return string Paddle Product ID (pro_...)
     * @throws \RuntimeException If product cannot be created
     */
    public function getOrCreatePaddleProduct(int $packageId): string
    {
        Database::initSchema();
        $env = $this->apiClient->getEnvironment();

        // 1. Check existing local mapping
        $mapping = Capsule::table(Database::TABLE_PRODUCTS)
            ->where('package_id', $packageId)
            ->where('environment', $env)
            ->first();

        if ($mapping && !empty($mapping->paddle_product_id)) {
            return $mapping->paddle_product_id;
        }

        // 2. Fetch WHMCS product details
        $pkg = Capsule::table('tblproducts')->where('id', $packageId)->first();
        $productName = $pkg ? trim((string)$pkg->name) : 'WHMCS Service #' . $packageId;
        $description = $pkg ? trim(strip_tags((string)$pkg->description)) : 'WHMCS Product #' . $packageId;
        if (empty($description)) {
            $description = $productName;
        }
        $description = substr($description, 0, 250);

        // 3. Create Product on Paddle Billing
        $payload = [
            'name' => $productName,
            'tax_category' => 'standard',
            'description' => $description,
            'custom_data' => [
                'whmcs_product_id' => (string)$packageId,
            ],
        ];

        $productData = $this->apiClient->createProduct($payload);
        $paddleProductId = $productData['id'] ?? null;

        if (empty($paddleProductId)) {
            throw new \RuntimeException("Paddle API did not return a valid Product ID for WHMCS package #{$packageId}");
        }

        // 4. Save in mod_paddle_products
        Capsule::table(Database::TABLE_PRODUCTS)->updateOrInsert(
            [
                'package_id' => $packageId,
                'environment' => $env,
            ],
            [
                'paddle_product_id' => $paddleProductId,
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]
        );

        Logger::logTransaction(
            'Paddle Product Auto-Created',
            ['package_id' => $packageId, 'paddle_product_id' => $paddleProductId, 'name' => $productName],
            'Success'
        );

        return $paddleProductId;
    }

    /**
     * Get or automatically create a Paddle Price matching a WHMCS Product and Billing Cycle
     *
     * @param int $packageId WHMCS Product ID
     * @param string $billingCycle 'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially', 'onetime'
     * @param float|null $amountOverride Explicit amount (converted to USD) if known
     * @param string $currencyCode Currency code of amount override if applicable
     * @return string Paddle Price ID (pri_...)
     * @throws \RuntimeException If price cannot be created
     */
    public function getOrCreatePaddlePrice(
        int $packageId,
        string $billingCycle,
        ?float $amountOverride = null,
        string $currencyCode = 'USD'
    ): string {
        Database::initSchema();
        $env = $this->apiClient->getEnvironment();
        $normalizedCycle = self::normalizeBillingCycle($billingCycle);

        // 1. Check existing local mapping
        $mapping = Capsule::table(Database::TABLE_PRICES)
            ->where('package_id', $packageId)
            ->where('billing_cycle', $normalizedCycle)
            ->where('environment', $env)
            ->first();

        if ($mapping && !empty($mapping->paddle_price_id)) {
            return $mapping->paddle_price_id;
        }

        // 2. Ensure parent Paddle Product exists
        $paddleProductId = $this->getOrCreatePaddleProduct($packageId);

        // 3. Determine USD price amount
        $usdAmount = 0.00;
        if ($amountOverride !== null && $amountOverride > 0) {
            $conversion = CurrencyService::convertToUsd($amountOverride, $currencyCode);
            $usdAmount = $conversion['usd_amount'];
            $usdCents = $conversion['usd_cents'];
        } else {
            // Retrieve pricing from WHMCS tblpricing
            $pricing = Capsule::table('tblpricing')
                ->where('type', 'product')
                ->where('relid', $packageId)
                ->first();

            if (!$pricing) {
                throw new \RuntimeException("No pricing found in WHMCS for package #{$packageId}");
            }

            $cycleColumn = self::mapCycleToPricingColumn($normalizedCycle);
            $rawPrice = (float)($pricing->$cycleColumn ?? -1);

            if ($rawPrice < 0) {
                // If specific cycle not set, check monthly or first available price
                $rawPrice = max(0.01, (float)($pricing->monthly ?? 1.00));
            }

            $conversion = CurrencyService::convertToUsd($rawPrice, (int)$pricing->currency);
            $usdAmount = $conversion['usd_amount'];
            $usdCents = $conversion['usd_cents'];
        }

        // 4. Map WHMCS cycle to Paddle Billing Cycle object
        $paddleCycle = self::mapCycleToPaddleBillingCycle($normalizedCycle);

        // 5. Build Price creation payload
        $pkg = Capsule::table('tblproducts')->where('id', $packageId)->first();
        $productName = $pkg ? trim((string)$pkg->name) : 'Product #' . $packageId;
        $description = $productName . ' (' . ucfirst($normalizedCycle) . ')';

        $pricePayload = [
            'product_id' => $paddleProductId,
            'description' => $description,
            'unit_price' => [
                'amount' => $usdCents,
                'currency_code' => 'USD',
            ],
            'custom_data' => [
                'whmcs_product_id' => (string)$packageId,
                'whmcs_billing_cycle' => $normalizedCycle,
            ],
        ];

        if ($paddleCycle !== null) {
            $pricePayload['billing_cycle'] = $paddleCycle;
        }

        // 6. Call Paddle API to create Price
        $priceData = $this->apiClient->createPrice($pricePayload);
        $paddlePriceId = $priceData['id'] ?? null;

        if (empty($paddlePriceId)) {
            throw new \RuntimeException("Paddle API did not return a valid Price ID for WHMCS package #{$packageId} ({$normalizedCycle})");
        }

        // 7. Save mapping in mod_paddle_prices
        Capsule::table(Database::TABLE_PRICES)->updateOrInsert(
            [
                'package_id' => $packageId,
                'billing_cycle' => $normalizedCycle,
                'environment' => $env,
            ],
            [
                'paddle_price_id' => $paddlePriceId,
                'paddle_product_id' => $paddleProductId,
                'amount_usd' => $usdAmount,
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]
        );

        Logger::logTransaction(
            'Paddle Price Auto-Created',
            [
                'package_id' => $packageId,
                'billing_cycle' => $normalizedCycle,
                'paddle_price_id' => $paddlePriceId,
                'amount_usd' => $usdAmount,
            ],
            'Success'
        );

        return $paddlePriceId;
    }

    /**
     * Get or create a fallback generic Product for one-time custom invoices & ad-hoc fees
     * @param string|null $name Optional product title/name derived from invoice items
     * @param string|null $description Optional product description
     * @return string Paddle Product ID (pro_...)
     */
    public function getOrCreateFallbackProduct(?string $name = null, ?string $description = null): string
    {
        Database::initSchema();
        $env = $this->apiClient->getEnvironment();

        // Package ID 0 is reserved for fallback/custom invoices
        $fallbackId = 0;
        $mapping = Capsule::table(Database::TABLE_PRODUCTS)
            ->where('package_id', $fallbackId)
            ->where('environment', $env)
            ->first();

        $productName = !empty($name) ? $name : 'WHMCS Service';
        $productDesc = !empty($description) ? $description : $productName;

        if ($mapping && !empty($mapping->paddle_product_id)) {
            // Dynamically update the product name on Paddle to match current invoice items
            if (!empty($name)) {
                try {
                    $this->apiClient->updateProduct($mapping->paddle_product_id, [
                        'name' => mb_substr($productName, 0, 100),
                        'description' => mb_substr($productDesc, 0, 250),
                    ]);
                } catch (\Throwable) {
                    // Non-fatal if update fails
                }
            }
            return $mapping->paddle_product_id;
        }

        $payload = [
            'name' => mb_substr($productName, 0, 100),
            'tax_category' => 'standard',
            'type' => 'custom',
            'description' => mb_substr($productDesc, 0, 250),
            'custom_data' => [
                'whmcs_product_id' => '0',
                'type' => 'fallback_one_time',
            ],
        ];

        $productData = $this->apiClient->createProduct($payload);
        $paddleProductId = $productData['id'] ?? null;

        if (empty($paddleProductId)) {
            throw new \RuntimeException('Failed to create fallback Paddle Product');
        }

        Capsule::table(Database::TABLE_PRODUCTS)->updateOrInsert(
            ['package_id' => $fallbackId, 'environment' => $env],
            ['paddle_product_id' => $paddleProductId, 'updated_at' => Capsule::raw('CURRENT_TIMESTAMP')]
        );

        return $paddleProductId;
    }

    /**
     * Scan all active WHMCS products and synchronize them and their pricing to Paddle
     *
     * @return array Summary of sync operations
     */
    public function syncAllWhmcsProducts(): array
    {
        Database::initSchema();

        $products = Capsule::table('tblproducts')->where('retired', 0)->get();
        $syncedProducts = 0;
        $syncedPrices = 0;
        $errors = [];

        $cycles = ['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially', 'onetime'];

        foreach ($products as $pkg) {
            $pkgId = (int)$pkg->id;
            try {
                // Ensure Product exists on Paddle
                $this->getOrCreatePaddleProduct($pkgId);
                $syncedProducts++;

                // Retrieve pricing row
                $pricing = Capsule::table('tblpricing')
                    ->where('type', 'product')
                    ->where('relid', $pkgId)
                    ->first();

                if ($pricing) {
                    foreach ($cycles as $cycle) {
                        $col = self::mapCycleToPricingColumn($cycle);
                        $priceVal = (float)($pricing->$col ?? -1);
                        if ($priceVal >= 0) {
                            $this->getOrCreatePaddlePrice($pkgId, $cycle, $priceVal, 'USD');
                            $syncedPrices++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "Product #{$pkgId} ({$pkg->name}): " . $e->getMessage();
            }
        }

        return [
            'products_synced' => $syncedProducts,
            'prices_synced' => $syncedPrices,
            'errors' => $errors,
        ];
    }

    /**
     * Normalize WHMCS billing cycle string
     */
    public static function normalizeBillingCycle(string $cycle): string
    {
        $c = strtolower(trim(str_replace([' ', '-', '_'], '', $cycle)));

        return match ($c) {
            'month', 'monthly' => 'monthly',
            'quarter', 'quarterly' => 'quarterly',
            'semiannual', 'semiannually' => 'semiannually',
            'annual', 'annually', 'year', 'yearly' => 'annually',
            'biennial', 'biennially', 'twoyear', 'twoyearly' => 'biennially',
            'triennial', 'triennially', 'threeyear', 'threeyearly' => 'triennially',
            default => 'onetime',
        };
    }

    /**
     * Map cycle to WHMCS tblpricing column name
     */
    private static function mapCycleToPricingColumn(string $normalizedCycle): string
    {
        return match ($normalizedCycle) {
            'monthly' => 'monthly',
            'quarterly' => 'quarterly',
            'semiannually' => 'semiannually',
            'annually' => 'annually',
            'biennially' => 'biennially',
            'triennially' => 'triennially',
            default => 'monthly',
        };
    }

    /**
     * Map normalized WHMCS cycle to Paddle Billing Cycle object
     */
    private static function mapCycleToPaddleBillingCycle(string $normalizedCycle): ?array
    {
        return match ($normalizedCycle) {
            'monthly' => ['interval' => 'month', 'frequency' => 1],
            'quarterly' => ['interval' => 'month', 'frequency' => 3],
            'semiannually' => ['interval' => 'month', 'frequency' => 6],
            'annually' => ['interval' => 'year', 'frequency' => 1],
            'biennially' => ['interval' => 'year', 'frequency' => 2],
            'triennially' => ['interval' => 'year', 'frequency' => 3],
            default => null, // One-time charge has no billing cycle
        };
    }
}
