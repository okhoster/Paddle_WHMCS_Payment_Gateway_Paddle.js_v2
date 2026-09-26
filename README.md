# Paddle Billing Payment Gateway for WHMCS

[![PHP 8.1 - 8.3](https://img.shields.io/badge/PHP-8.1%20--%208.3-blue.svg)](https://www.php.net/)
[![WHMCS Compatibility](https://img.shields.io/badge/WHMCS-8.10.1%20%7C%2010.13.0-green.svg)](https://www.whmcs.com/)
[![Paddle Billing](https://img.shields.io/badge/Paddle-Billing%20API-orange.svg)](https://developer.paddle.com/)
[![License:GPL-3.0](https://img.shields.io/badge/License-gpl3.0%20license-purple.svg)](https://www.gnu.org/licenses/gpl-3.0.en.html)

Production-ready, highly secure **Paddle Billing** (Paddle's current platform) payment gateway module for **WHMCS**.

> [!IMPORTANT]
> This module is built strictly for **Paddle Billing** using the modern Paddle REST API, Paddle.js v2, Paddle Transactions, and Subscriptions. It **does not** use legacy Paddle Classic APIs, deprecated public-key signatures, or legacy invoice endpoints.

---

## Key Features

- **Current Paddle Billing Architecture**:
  - Direct integration with `https://api.paddle.com` and `https://sandbox-api.paddle.com`.
  - Client-side checkout via **Paddle.js v2** utilizing public **Client-side Tokens**.
  - Server-side API keys and webhook secrets are strictly kept on the server and never exposed to the browser.
- **Strict USD Gateway Processing with Transparent Multi-Currency Conversion**:
  - Paddle processes transactions exclusively in **USD**.
  - WHMCS retains full support for multi-currency client accounts (EUR, GBP, PKR, INR, CAD, AUD, etc.).
  - Automatic conversion to USD using WHMCS's internal exchange rate configuration and database precision (`\WHMCS\Billing\Currency` / `tblcurrencies`).
  - Original invoice currency and balance in WHMCS remain completely preserved without accounting distortion.
- **Transaction-Based Checkout**:
  - Server-side transaction creation (`POST /transactions`) prior to checkout.
  - Overlay modal checkout (and inline checkout support).
  - Browser return URL is treated as untrusted; payments are only verified and credited via cryptographically signed webhooks.
- **Automatic Product & Subscription Creation**:
  - Automatically creates matching Paddle Products (`pro_...`) and Prices (`pri_...`) on Paddle when customers purchase WHMCS products or invoices.
  - Automatically maps WHMCS billing cycles (Monthly, Quarterly, Semi-Annually, Annually, Biennially, Triennially) to Paddle recurrence intervals.
  - Generates Paddle subscriptions automatically on checkout and binds them to `tblhosting.subscriptionid`.
  - One-click **"Sync All Products to Paddle"** tool in WHMCS Admin Diagnostics for bulk catalog creation.
- **Recurring Subscriptions & Lifecycle Synchronization**:
  - Automatic synchronization of Paddle Subscriptions created upon checkout.
  - Supports `active`, `trialing`, `past_due`, `paused`, `resumed`, and `canceled` lifecycle states.
  - Configurable automatic subscription cancellation when a WHMCS service is terminated or deleted (supports end of billing period or immediate termination).
  - Product upgrades/downgrades supported with full desired item lists and configurable proration modes (`prorated_immediately`, `full_immediately`, `do_not_bill`, etc.).

- **Self-Service Customer Portal**:
  - Secure integration with Paddle's Customer Portal sessions (`POST /customer-portal-sessions`).
  - Clients can manage subscriptions and update payment methods directly from their WHMCS service details.
- **Enterprise Webhook Security**:
  - HMAC-SHA256 signature verification over raw request body using `hash_equals()`.
  - Clock-drift tolerance window (300 seconds) preventing replay attacks.
  - Support for multiple `h1` signatures accommodating Paddle webhook secret rotation.
  - Idempotent event processing backed by database unique constraints on `event_id` in `mod_paddle_webhook_events`.
- **SSRF & Data Protection**:
  - Strict host allowlist on API calls preventing Server-Side Request Forgery.
  - Automatic sanitization and masking of API keys, tokens, CVVs, and credit card numbers across all logs and views.
  - BCMath decimal-safe monetary calculations.

---

## Directory Structure

```text
/
├── modules/
│   └── gateways/
│       ├── paddle.php                         # Primary WHMCS gateway definition
│       ├── callback/
│       │   └── paddle.php                     # Secure Webhook endpoint
│       └── paddle/
│           ├── lib/
│           │   ├── ApiClient.php              # Paddle Billing REST client
│           │   ├── WebhookVerifier.php        # HMAC-SHA256 signature verifier
│           │   ├── WebhookHandler.php         # Event processor & payment logic
│           │   ├── TransactionService.php     # Transaction creation & Price mapping
│           │   ├── SubscriptionService.php    # Subscription lifecycle & upgrades
│           │   ├── CustomerService.php        # Customer & address management
│           │   ├── CurrencyService.php        # Multi-currency USD conversion
│           │   ├── Logger.php                 # Safe logging & secret redaction
│           │   ├── Security.php               # SSRF, CSRF, JSON, & XSS guards
│           │   ├── Database.php               # Schema initialization & migrations
│           │   ├── Compatibility.php          # WHMCS & PHP version abstraction
│           │   └── AdminDispatcher.php        # Admin AJAX diagnostics handler
│           └── templates/
│               ├── checkout.tpl.php           # Paddle.js v2 checkout loader
│               ├── client_service.tpl.php     # Client area subscription view
│               └── admin_diagnostics.tpl.php  # Admin gateway configuration panel
├── includes/
│   └── hooks/
│       └── paddle_gateway_hooks.php           # Service termination & portal hooks
├── tests/
│   ├── run_tests.php                          # Automated verification test suite
│   └── CurrencyConversionMatrixTest.php       # Currency math test matrix
├── README.md
├── INSTALLATION.md
├── SECURITY.md
├── TROUBLESHOOTING.md
├── PADDLE_SETUP.md
└── UPGRADE.md
```

---

## System Requirements

| Component | Supported Versions | Notes |
| :--- | :--- | :--- |
| **PHP** | 8.1, 8.2, 8.3 | Strictly no PHP 8.4+ syntax used |
| **WHMCS** | 8.10.1, 10.13.0+ | Compatible with current & upcoming releases |
| **PHP Extensions** | `curl`, `json`, `openssl`, `mbstring`, `bcmath`, `pdo` | Required for cryptographic & monetary precision |
| **Paddle Platform** | Paddle Billing | Current API version (`v1`), Paddle.js `v2` |

---

## Quick Start

1. Copy `modules/` and `includes/` to your WHMCS root directory.
2. In the WHMCS Admin, navigate to **Configuration > System Settings > Payment Gateways**.
3. Under the **All Payment Gateways** tab, click **Paddle Billing** to activate it.
4. Follow [INSTALLATION.md](https://github.com/okhoster/Paddle_WHMCS_Payment_Gateway_Paddle.js_v2/blob/main/INSTALLATION.md) and [PADDLE_SETUP.md](https://github.com/okhoster/Paddle_WHMCS_Payment_Gateway_Paddle.js_v2/blob/main/PADDLE_SETUP.md) for step-by-step credential and webhook setup.
