# Paddle Billing Gateway Upgrade Guide

This guide describes how to upgrade the Paddle Billing Payment Gateway module in WHMCS.

---

## Upgrade Philosophy & Data Safety

- **Non-Destructive Schema Updates**: All database operations in `Database::initSchema()` use non-destructive checks (`hasTable`, `hasColumn`, `updateOrInsert`). Existing customer mappings, transaction logs, and subscription records are preserved across upgrades.
- **Backward & Forward Compatibility**: The module is engineered with zero deprecated APIs for WHMCS 8.10.1 and WHMCS 10.13.0 on PHP 8.1 through 8.3.

---

## Standard Upgrade Procedure

### Step 1: Backup Your WHMCS Installation
Always create a backup of your WHMCS database and files before applying updates:
```bash
# Example MySQL dump
mysqldump -u whmcs_user -p whmcs_database > whmcs_backup_$(date +%Y%m%d).sql
```

### Step 2: Replace Module Files
Extract or upload the updated module files over your existing installation:
```text
modules/gateways/paddle.php
modules/gateways/callback/paddle.php
modules/gateways/paddle/
includes/hooks/paddle_gateway_hooks.php
```

### Step 3: Trigger Schema Verification
Log into WHMCS Admin as a Full Administrator and navigate to:
**Configuration > System Settings > Payment Gateways > Paddle Billing**.

Opening this page triggers `Database::initSchema()`, verifying table structures and applying any incremental schema adjustments.

### Step 4: Verify API & Webhook Connectivity
In the Gateway Diagnostics panel at the top of the configuration page:
1. Click **Run Connection Test** to ensure API communication remains operational.
2. Confirm that the Webhook URL matches your configured Paddle Notification Destination.

---

## Version History

### Version 1.0.0 (Initial Production Release)
- Full Paddle Billing API v1 integration (no legacy Classic APIs).
- Paddle.js v2 transaction-based overlay checkout.
- Multi-currency WHMCS support with strict USD-only Paddle processing and BCMath decimal-safe conversion.
- Webhook HMAC-SHA256 signature verification with replay protection (300s window) and secret rotation support.
- Database-backed idempotent webhook processing.
- Subscription lifecycle synchronization (`active`, `trialing`, `past_due`, `paused`, `canceled`).
- Automatic subscription cancellation on WHMCS service termination.
- Paddle Customer Portal integration for client area self-service.
- Admin diagnostic dashboard with live API connection tests and Price ID validator.
- Automated test suite verifying signatures, security sanitizers, and currency math.
