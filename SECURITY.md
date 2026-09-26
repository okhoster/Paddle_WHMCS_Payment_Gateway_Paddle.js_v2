# Paddle Billing Gateway Security Architecture & Audit Report

This document details the security principles, cryptographic verification procedures, and vulnerability defenses implemented in the WHMCS Paddle Billing Payment Gateway.

---

## Core Security Principles

```text
┌─────────────────┐         ┌────────────────────────┐         ┌─────────────────────────┐
│ Browser / User  │         │ WHMCS Authoritative    │         │ Paddle Billing Platform │
│ (Untrusted)     │         │ Server-Side Core       │         │ (Authoritative Payment) │
└────────┬────────┘         └───────────┬────────────┘         └────────────┬────────────┘
         │                              │                                   │
         │  1. View Invoice             │                                   │
         ├─────────────────────────────>│                                   │
         │                              │  2. POST /transactions (USD)      │
         │                              ├──────────────────────────────────>│
         │                              │<──────────────────────────────────┤
         │  3. Return Transaction ID    │     Returns txn_...               │
         │<─────────────────────────────┤                                   │
         │                              │                                   │
         │  4. Open Paddle.js Checkout  │                                   │
         │     (Using client token)     │                                   │
         ├──────────────────────────────┼──────────────────────────────────>│
         │                              │                                   │
         │  5. Customer Pays            │                                   │
         ├──────────────────────────────┼──────────────────────────────────>│
         │                              │                                   │
         │  6. Return URL (Untrusted)   │                                   │
         ├─────────────────────────────>│ [Status: "Verifying..."]          │
         │   (NO PAYMENT RECORDED HERE) │                                   │
         │                              │  7. Webhook: transaction.paid     │
         │                              │<──────────────────────────────────┤
         │                              │  8. HMAC-SHA256 Verified          │
         │                              │  9. Replay & Idempotency Checked  │
         │                              │  10. Amount & Currency Validated  │
         │                              │  11. Invoice Marked Paid in WHMCS │
         │                              │                                   │
```

### Principle 1: The Browser is Untrusted
The browser is never trusted to report payment success, amounts, currency, or subscription IDs.
- The browser return URL merely displays an informational "Payment is being verified" state and refreshes the invoice.
- Payment is **only** marked paid after receiving a cryptographically verified webhook event from Paddle's servers.

### Principle 2: WHMCS Authoritative Invoice State
WHMCS calculates the invoice balance in the client's currency. The gateway calculates the required USD equivalent using WHMCS's internal exchange rates.

### Principle 3: Paddle Authoritative Transaction State
Paddle verifies whether funds were collected. The webhook provides authoritative confirmation.

---

## Cryptographic Webhook Verification

Webhooks are verified using HMAC-SHA256 according to Paddle's modern Billing specification:

1. **Raw Body Integrity**: The unparsed, raw HTTP request body string (`file_get_contents('php://input')`) is preserved verbatim.
2. **Signature Header Parsing**: The `Paddle-Signature` header is parsed for:
   - `ts`: Unix timestamp of the event transmission.
   - `h1`: One or more hexadecimal HMAC-SHA256 digests.
3. **Replay Attack Defense**:
   $$\Delta t = |\text{time}() - \text{ts}|$$
   If $\Delta t > 300\text{ seconds}$ (5 minutes), the webhook is immediately rejected with HTTP 401.
4. **Digest Calculation**:
   $$\text{payload} = \text{ts} + \text{":"} + \text{rawBody}$$
   $$\text{computedHash} = \text{hash\_hmac}('sha256', \text{payload}, \text{secret})$$
5. **Constant-Time Comparison**:
   The computed hash is compared to the `h1` candidate hashes using PHP's `hash_equals()` to prevent timing attacks.
6. **Secret Rotation Support**:
   If Paddle sends multiple `h1` hashes (during endpoint secret rotation), the verification succeeds if any candidate hash matches.

---

## Idempotency & Race Condition Prevention

Network latency or retry mechanisms can cause identical webhook deliveries to arrive simultaneously.
To prevent duplicate invoices or double-crediting:

1. **Unique Database Constraint**:
   The table `mod_paddle_webhook_events` enforces a `UNIQUE` index on `event_id`:
   ```sql
   UNIQUE KEY (event_id)
   ```
2. **Atomic Ingestion**:
   When a webhook arrives, `Database::recordEventIfNew()` performs an atomic `INSERT`. If the `event_id` already exists:
   - If the event is marked `processed`: A success response (HTTP 200) is returned immediately with no duplicate payment application.
   - If the event is currently `processing`: The secondary request safely terminates with HTTP 200.
3. **Transaction Check**:
   WHMCS's `checkCbTransID($transId)` verifies that the Paddle Transaction ID has not already been credited.

---

## Monetary Precision & Anti-Tampering

1. **USD Conversion Isolation**:
   - Paddle processes strictly in USD.
   - For foreign currencies (EUR, GBP, PKR, INR, etc.), conversion to USD is executed server-side via `CurrencyService::convertToUsd()` using WHMCS configured rates (`tblcurrencies`).
   - BCMath high-precision arithmetic (`bcdiv`, `bcmul`) is utilized, rounding to 2 decimal places with `PHP_ROUND_HALF_UP`.
2. **Amount Validation on Webhook**:
   When `transaction.paid` arrives:
   - The authoritative `grand_total` (in cents) is retrieved from Paddle.
   - Converted to dollars: `CurrencyService::centsToDollars($cents)`.
   - Compared against the invoice's expected USD amount.
   - If an underpayment exceeding \$0.05 is detected, payment is **not** applied, and an alert is logged for administrator review.
3. **Zero & Negative Amount Guards**:
   Any amount $\le 0.00$ triggers an immediate `InvalidArgumentException`.

---

## SSRF (Server-Side Request Forgery) Defense

The module disallows dynamic or user-controlled destination URLs:
- API requests are strictly restricted to the hardcoded allowlist:
  - `https://api.paddle.com`
  - `https://sandbox-api.paddle.com`
- All outgoing API URLs are verified using `Security::isAllowedApiUrl()`. Requests targeting `http://`, internal metadata endpoints (`169.254.169.254`), or localhost are blocked.

---

## Credential Protection & Redaction

1. **Database Storage**:
   Sensitive fields (`sandboxApiKey`, `sandboxWebhookSecret`, `liveApiKey`, `liveWebhookSecret`) are defined with `Type => 'password'` in `paddle_config()`, ensuring WHMCS encrypts them in the database using the installation's AES-256 key (`$cc_encryption_hash`).
2. **Frontend Isolation**:
   The browser only ever receives the public `clientToken` and `transactionId`. API keys and webhook secrets are never rendered into HTML, JavaScript, or attributes.
3. **Log Sanitization**:
   All data passed to `Logger::logTransaction()`, `Logger::logApi()`, and `Logger::logWebhook()` is scrubbed via `Security::redactSensitiveData()`.
   - Keys matching `secret`, `apikey`, `token`, `password`, `auth`, `cvv`, `card` are replaced with masked strings.
   - Bearer authorization headers and raw Paddle keys are automatically redacted via regex before being written to disk or database.

---

## Least-Privilege Paddle API Permissions

When creating your Paddle API Key in the Paddle Dashboard, configure only the necessary permissions:

| Permission | Scope | Necessity | Purpose |
| :--- | :--- | :--- | :--- |
| **Transactions** | Read & Write | **Required** | Create checkouts and verify payment status |
| **Subscriptions** | Read & Write | **Required** | Manage recurring subscriptions, cancel, and upgrade |
| **Customers** | Read & Write | **Required** | Map WHMCS clients and addresses to Paddle |
| **Prices** | Read | **Required** | Validate catalog prices and billing intervals |
| **Products** | Read | Optional | Catalog inspections and price lookups |
| **Adjustments** | Read & Write | Optional | Required only if Admin refunds are enabled |
| **Customer Portal** | Write | Optional | Required for client area Customer Portal links |
| **Payouts** | None | *Forbidden* | Do not grant payout permissions to gateway |
| **Team / Users** | None | *Forbidden* | Do not grant administrative permissions |
