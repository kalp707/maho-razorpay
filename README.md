# Maho Razorpay

[Razorpay](https://razorpay.com) payment gateway module **for [Maho Commerce](https://mahocommerce.com) only**.

Creates a Razorpay Order per checkout, opens Checkout.js, verifies the payment signature server-side, and optionally handles `payment.captured` webhooks.

**Composer:** `kalp707/maho-razorpay`  
**Repository:** https://github.com/kalp707/maho-razorpay  
**License:** [OSL-3.0](LICENSE)

## Requirements

- Maho Commerce `>=26.5` (PHP 8.2+)
- Composer package `razorpay/razorpay` `2.*` (pulled automatically)
- Not supported on Magento 1 / OpenMage as a first-class target

## Install with Composer

From your Maho project root:

```bash
composer require kalp707/maho-razorpay
php maho cache:flush
```

Package type is `maho-module`. Maho loads code and templates from `vendor/`.

## Admin configuration

1. **System → Configuration → Sales → Payment Methods → Razorpay**
2. Set **Enabled** = Yes
3. Enter **Key ID** and **Key Secret** from the [Razorpay Dashboard](https://dashboard.razorpay.com/app/keys) (use Test keys while developing)
4. Optional: Webhook Secret, merchant name, theme color, debug logging
5. Save and flush cache

### Recommended Razorpay Dashboard settings

1. Enable **Auto-capture** for payments (Dashboard → Settings → Payments)
2. Add a webhook:
   - URL: `https://YOUR-DOMAIN/razorpay/webhook`
   - Event: `payment.captured`
   - Copy the webhook secret into the Magento config field

## Payment flow

1. Customer places the order with method **Razorpay** (`pending_payment`)
2. Redirect to `/razorpay/payment/redirect`
3. Server creates a Razorpay Order via the official SDK
4. Frontend opens Checkout.js with that `order_id`
5. Callback to `/razorpay/payment/callback` verifies the signature and invoices the order
6. Optional webhook on `/razorpay/webhook` captures missed browser callbacks

## Online refunds

Credit memos created online (against an invoice) call the Razorpay Refunds API. Partial refunds are supported. The Razorpay refund id is stored as the credit memo / payment transaction id.

## Endpoints

| URL | Purpose |
|-----|---------|
| `/razorpay/payment/redirect` | Starts Checkout.js |
| `/razorpay/payment/callback` | Verifies signature and completes the order |
| `/razorpay/payment/cancel` | Cancels pending payment |
| `/razorpay/webhook` | Webhook backup for `payment.captured` |

## Module layout

| Piece | Path |
|-------|------|
| Declaration | `app/etc/modules/Kalp_Razorpay.xml` |
| Code | `app/code/community/Kalp/Razorpay/` |
| Templates / layout | `app/design/frontend/base/default/...` |

## License

[Open Software License 3.0 (OSL-3.0)](LICENSE)

This licenses the Maho integration code only. Merchants need their own Razorpay account and API keys. The official PHP client is MIT (`razorpay/razorpay`).
