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

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

use WHMCS\Module\Gateway\Paddle\Security;

/**
 * Variables expected:
 * @var string $environment 'sandbox' or 'live'
 * @var string $clientToken Paddle Client-side Token
 * @var string $transactionId Paddle Transaction ID (txn_...)
 * @var float  $usdAmount Converted amount in USD
 * @var float  $originalAmount Original invoice amount
 * @var string $originalCurrency Original invoice currency code
 * @var string $returnUrl WHMCS return URL
 * @var string $checkoutMode 'overlay' or 'inline'
 * @var int    $invoiceId WHMCS invoice ID
 */

$isSandbox = ($environment === 'sandbox');
$isConverted = ($originalCurrency !== 'USD');
$isInline = ($checkoutMode === 'inline');
?>
<div class="paddle-checkout-container" id="paddle-checkout-wrapper-<?= (int)$invoiceId ?>">
    <!-- Paddle.js v2 CDN -->
    <script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>

    <div class="paddle-payment-box text-center p-3 mb-3 border rounded bg-light">
        <div class="paddle-amount-summary mb-2">
            <h4 class="mb-1">
                <?= Security::escapeHtml($originalCurrency) ?> <?= number_format($originalAmount, 2) ?>
            </h4>
            <?php if ($isConverted): ?>
                <p class="text-muted small mb-1">
                    <em>(Converted to USD $<?= number_format($usdAmount, 2) ?> for Paddle processing)</em>
                </p>
            <?php endif; ?>
            <?php if ($isSandbox): ?>
                <span class="badge badge-warning text-dark mb-2" style="background:#ffc107; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                    SANDBOX TEST MODE
                </span>
            <?php endif; ?>
        </div>

        <?php if ($isInline): ?>
            <!-- Inline Checkout Frame Container -->
            <div id="paddle-inline-container-<?= (int)$invoiceId ?>" class="paddle-inline-checkout-frame my-3" style="width: 100%; min-width: 312px; min-height: 450px;">
                <div class="text-center p-4 text-muted" id="paddle-inline-loading-<?= (int)$invoiceId ?>">
                    <i class="fas fa-spinner fa-spin fa-2x mb-2 text-primary"></i>
                    <div>Loading Secure Paddle Payment Form...</div>
                </div>
            </div>
            <button type="button" id="paddle-pay-btn-<?= (int)$invoiceId ?>" class="btn btn-secondary btn-sm px-3 mt-2" style="display: none;">
                <i class="fas fa-redo me-1"></i> Reload Payment Form
            </button>
        <?php else: ?>
            <button type="button" id="paddle-pay-btn-<?= (int)$invoiceId ?>" class="btn btn-primary btn-lg px-4" style="background-color: #2e384d; border-color: #2e384d; color: #fff;">
                <i class="fas fa-lock me-1"></i> Pay Now with Paddle
            </button>
        <?php endif; ?>

        <div id="paddle-verifying-notice-<?= (int)$invoiceId ?>" class="alert alert-info mt-3 d-none" style="display: none;">
            <i class="fas fa-spinner fa-spin me-2"></i>
            <strong>Payment Received!</strong> We are verifying your transaction with Paddle. This page will refresh shortly...
        </div>
    </div>

    <script type="text/javascript">
    (function() {
        const clientToken = <?= json_encode($clientToken) ?>;
        const transactionId = <?= json_encode($transactionId) ?>;
        const isSandbox = <?= json_encode($isSandbox) ?>;
        const returnUrl = <?= json_encode($returnUrl) ?>;
        const invoiceId = <?= (int)$invoiceId ?>;
        const checkoutMode = <?= json_encode($checkoutMode ?? 'overlay') ?>;
        const payButton = document.getElementById('paddle-pay-btn-' + invoiceId);
        const noticeBox = document.getElementById('paddle-verifying-notice-' + invoiceId);
        const inlineContainer = document.getElementById('paddle-inline-container-' + invoiceId);
        const inlineLoading = document.getElementById('paddle-inline-loading-' + invoiceId);

        let paddleInitialized = false;
        let checkoutOpened = false;

        function initPaddle() {
            if (typeof Paddle === 'undefined' || paddleInitialized) {
                return;
            }
            try {
                if (isSandbox) {
                    Paddle.Environment.set('sandbox');
                }
                Paddle.Initialize({
                    token: clientToken,
                    eventCallback: function(data) {
                        if (data && data.name === 'checkout.completed') {
                            if (payButton) payButton.style.display = 'none';
                            if (inlineContainer) inlineContainer.style.display = 'none';
                            if (noticeBox) {
                                noticeBox.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> <strong>Payment Received!</strong> We are verifying your transaction with Paddle. This page will refresh shortly...';
                                noticeBox.className = 'alert alert-info mt-3';
                                noticeBox.style.display = 'block';
                            }
                            setTimeout(function() {
                                window.location.href = returnUrl;
                            }, 2000);
                        } else if (data && data.name === 'checkout.loaded') {
                            if (inlineLoading) inlineLoading.style.display = 'none';
                        }
                    }
                });
                paddleInitialized = true;

                // Auto-open inline checkout once Paddle.js initializes
                if (checkoutMode === 'inline' && !checkoutOpened) {
                    openPaddleCheckout();
                }
            } catch (err) {
                console.error('Paddle initialization error:', err);
            }
        }

        // Initialize Paddle if script is already present
        if (typeof Paddle !== 'undefined') {
            initPaddle();
        } else {
            // Check periodically in case CDN is still loading
            let checkCount = 0;
            const timer = setInterval(function() {
                checkCount++;
                if (typeof Paddle !== 'undefined') {
                    clearInterval(timer);
                    initPaddle();
                } else if (checkCount > 50) {
                    clearInterval(timer);
                }
            }, 100);
        }

        function openPaddleCheckout() {
            if (!transactionId) {
                console.error('Missing Paddle transaction ID.');
                return;
            }
            try {
                if (checkoutMode === 'inline') {
                    Paddle.Checkout.open({
                        transactionId: transactionId,
                        settings: {
                            displayMode: 'inline',
                            frameTarget: 'paddle-inline-container-' + invoiceId,
                            frameInitialHeight: 450,
                            frameStyle: 'width: 100%; min-width: 312px; background-color: transparent; border: none;',
                            theme: 'light',
                            locale: 'en',
                            successUrl: returnUrl
                        }
                    });
                    checkoutOpened = true;
                    if (inlineLoading) {
                        inlineLoading.style.display = 'none';
                    }
                } else {
                    Paddle.Checkout.open({
                        transactionId: transactionId,
                        settings: {
                            displayMode: 'overlay',
                            theme: 'light',
                            locale: 'en',
                            successUrl: returnUrl
                        }
                    });
                }
            } catch (err) {
                console.error('Paddle.Checkout.open error:', err);
                if (inlineLoading) {
                    inlineLoading.style.display = 'none';
                }
                if (payButton && checkoutMode === 'inline') {
                    payButton.style.display = 'inline-block';
                }
                if (noticeBox) {
                    noticeBox.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> <strong>Unable to launch checkout:</strong> ' + (err.message || 'Please check if your domain is approved in Paddle Dashboard > Website approval.');
                    noticeBox.className = 'alert alert-danger mt-3';
                    noticeBox.style.display = 'block';
                }
            }
        }

        // Attach click listener
        if (payButton) {
            payButton.addEventListener('click', function(e) {
                e.preventDefault();

                if (!clientToken || !transactionId) {
                    alert('Paddle configuration error: Missing transaction ID or client token.');
                    return;
                }

                if (typeof Paddle === 'undefined') {
                    payButton.disabled = true;
                    payButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Loading Paddle Checkout...';

                    let waitAttempts = 0;
                    const waitInterval = setInterval(function() {
                        waitAttempts++;
                        if (typeof Paddle !== 'undefined') {
                            clearInterval(waitInterval);
                            initPaddle();
                            payButton.disabled = false;
                            payButton.innerHTML = (checkoutMode === 'inline') ? '<i class="fas fa-redo me-1"></i> Reload Payment Form' : '<i class="fas fa-lock me-1"></i> Pay Now with Paddle';
                            openPaddleCheckout();
                        } else if (waitAttempts > 30) {
                            clearInterval(waitInterval);
                            payButton.disabled = false;
                            payButton.innerHTML = (checkoutMode === 'inline') ? '<i class="fas fa-redo me-1"></i> Reload Payment Form' : '<i class="fas fa-lock me-1"></i> Pay Now with Paddle';
                            if (noticeBox) {
                                noticeBox.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> <strong>Paddle CDN failed to load.</strong> Please check your internet connection or ad-blocker.';
                                noticeBox.className = 'alert alert-danger mt-3';
                                noticeBox.style.display = 'block';
                            }
                        }
                    }, 100);
                    return;
                }

                if (!paddleInitialized) {
                    initPaddle();
                }

                openPaddleCheckout();
            });
        }
    })();
    </script>
</div>
