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
use Illuminate\Database\Schema\Blueprint;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Database Migration and Persistence Management
 */
final class Database
{
    public const TABLE_EVENTS = 'mod_paddle_webhook_events';
    public const TABLE_CUSTOMERS = 'mod_paddle_customers';
    public const TABLE_SUBSCRIPTIONS = 'mod_paddle_subscriptions';
    public const TABLE_TRANSACTIONS = 'mod_paddle_transactions';
    public const TABLE_PRODUCTS = 'mod_paddle_products';
    public const TABLE_PRICES = 'mod_paddle_prices';

    /**
     * Initialize all required module database tables safely
     */
    public static function initSchema(): void
    {
        if (!class_exists('\WHMCS\Database\Capsule')) {
            return;
        }

        $schema = Capsule::schema();

        // 1. Webhook Events Table (Idempotency and Audit)
        if (!$schema->hasTable(self::TABLE_EVENTS)) {
            $schema->create(self::TABLE_EVENTS, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('event_id', 100)->unique();
                $table->string('event_type', 100)->index();
                $table->string('occurred_at', 50)->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->string('status', 50)->default('received')->index();
                $table->unsignedInteger('processing_attempts')->default(1);
                $table->timestamp('processed_at')->nullable();
                $table->char('payload_hash', 64)->nullable();
                $table->text('error_message')->nullable();
            });
        }

        // 2. Customers Mapping Table
        if (!$schema->hasTable(self::TABLE_CUSTOMERS)) {
            $schema->create(self::TABLE_CUSTOMERS, function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('client_id')->index();
                $table->string('paddle_customer_id', 100)->index();
                $table->string('paddle_address_id', 100)->nullable();
                $table->string('environment', 20)->default('sandbox');
                $table->timestamps();
                $table->unique(['client_id', 'environment'], 'client_env_unique');
            });
        }

        // 3. Subscriptions Mapping Table
        if (!$schema->hasTable(self::TABLE_SUBSCRIPTIONS)) {
            $schema->create(self::TABLE_SUBSCRIPTIONS, function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('service_id')->index();
                $table->unsignedInteger('client_id')->index();
                $table->unsignedInteger('invoice_id')->nullable()->index();
                $table->string('paddle_subscription_id', 100)->unique();
                $table->string('paddle_customer_id', 100)->index();
                $table->string('paddle_price_id', 100)->nullable();
                $table->string('status', 50)->default('active')->index();
                $table->string('billing_interval', 50)->nullable();
                $table->string('currency_code', 10)->default('USD');
                $table->string('environment', 20)->default('sandbox');
                $table->timestamp('next_billed_at')->nullable();
                $table->timestamps();
            });
        }

        // 4. Transactions Mapping Table
        if (!$schema->hasTable(self::TABLE_TRANSACTIONS)) {
            $schema->create(self::TABLE_TRANSACTIONS, function (Blueprint $table) {
                $table->increments('id');
                $table->string('paddle_transaction_id', 100)->unique();
                $table->unsignedInteger('invoice_id')->index();
                $table->unsignedInteger('client_id')->index();
                $table->string('subscription_id', 100)->nullable()->index();
                $table->string('status', 50)->default('draft')->index();
                $table->string('whmcs_currency', 10)->default('USD');
                $table->decimal('whmcs_amount', 16, 2)->default(0.00);
                $table->decimal('usd_amount', 16, 2)->default(0.00);
                $table->decimal('exchange_rate', 16, 6)->default(1.000000);
                $table->string('environment', 20)->default('sandbox');
                $table->timestamp('paddle_created_at')->nullable();
                $table->timestamps();
            });
        }

        // 5. Products Mapping Table (WHMCS Products -> Paddle Products)
        if (!$schema->hasTable(self::TABLE_PRODUCTS)) {
            $schema->create(self::TABLE_PRODUCTS, function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('package_id')->index();
                $table->string('paddle_product_id', 100)->index();
                $table->string('environment', 20)->default('sandbox');
                $table->timestamps();
                $table->unique(['package_id', 'environment'], 'pkg_env_unique');
            });
        }

        // 6. Prices Mapping Table (WHMCS Products + Cycle -> Paddle Prices)
        if (!$schema->hasTable(self::TABLE_PRICES)) {
            $schema->create(self::TABLE_PRICES, function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('package_id')->index();
                $table->string('billing_cycle', 50)->index();
                $table->string('paddle_price_id', 100)->index();
                $table->string('paddle_product_id', 100)->index();
                $table->decimal('amount_usd', 16, 2)->default(0.00);
                $table->string('environment', 20)->default('sandbox');
                $table->timestamps();
                $table->unique(['package_id', 'billing_cycle', 'environment'], 'pkg_cycle_env_unique');
            });
        }
    }


    /**
     * Atomically record an incoming webhook event.
     * Returns true if newly recorded, false if already exists (idempotent duplicate).
     *
     * @param string $eventId Paddle Event ID
     * @param string $eventType Paddle Event Type
     * @param string|null $occurredAt Timestamp from payload
     * @param string $rawPayload Raw body string
     * @return bool True if inserted, false if already present
     */
    public static function recordEventIfNew(string $eventId, string $eventType, ?string $occurredAt, string $rawPayload): bool
    {
        self::initSchema();

        $hash = hash('sha256', $rawPayload);

        try {
            // Attempt atomic insert
            Capsule::table(self::TABLE_EVENTS)->insert([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'received_at' => Capsule::raw('CURRENT_TIMESTAMP'),
                'status' => 'processing',
                'processing_attempts' => 1,
                'payload_hash' => $hash,
            ]);
            return true;
        } catch (\Throwable $e) {
            // Duplicate key error indicates already received
            $existing = Capsule::table(self::TABLE_EVENTS)->where('event_id', $eventId)->first();
            if ($existing) {
                // Increment attempt count
                Capsule::table(self::TABLE_EVENTS)
                    ->where('event_id', $eventId)
                    ->increment('processing_attempts');
                return false;
            }
            throw $e;
        }
    }

    /**
     * Update webhook event processing status
     *
     * @param string $eventId Paddle Event ID
     * @param string $status Final status ('processed', 'failed', 'ignored')
     * @param string|null $errorMessage Optional error description
     */
    public static function markEventStatus(string $eventId, string $status, ?string $errorMessage = null): void
    {
        Capsule::table(self::TABLE_EVENTS)
            ->where('event_id', $eventId)
            ->update([
                'status' => $status,
                'processed_at' => Capsule::raw('CURRENT_TIMESTAMP'),
                'error_message' => $errorMessage,
            ]);
    }

    /**
     * Check if an event was already processed successfully
     *
     * @param string $eventId Paddle Event ID
     * @return bool True if already processed
     */
    public static function isEventProcessed(string $eventId): bool
    {
        self::initSchema();

        $event = Capsule::table(self::TABLE_EVENTS)
            ->where('event_id', $eventId)
            ->first();

        return ($event && $event->status === 'processed');
    }
}
