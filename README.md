# Sunrice Commerce Midtrans

[Midtrans Snap](https://midtrans.com) payments for [Sunrice Commerce](https://github.com/Digital-Citra-Kreatif/sunrice-commerce):
the customer is sent to Midtrans' payment page and picks a card, e-wallet, QRIS, virtual account or
other channel there. Notifications are verified, mapped to the payment's state and recorded by
commerce; the order follows.

Requirements: PHP 8.4+, Laravel 13, Sunrice Commerce 1.x with `Payments\Methods\GatewayMethod`, a
store in IDR. The Midtrans SDK (`midtrans/midtrans-php`) comes with the package.

## Contents

- [Install](#install)
- [Keys](#keys)
- [The notification URL](#the-notification-url)
- [Switching it on](#switching-it-on)
- [Sandbox](#sandbox)
- [How it works](#how-it-works)
- [Status mapping](#status-mapping)
- [Refunds](#refunds)
- [Configuration](#configuration)
- [Testing your store](#testing-your-store)

## Install

```bash
composer require sunrice/commerce-midtrans
```

The service provider is discovered by Laravel. It adds the `midtrans` module and the `midtrans`
payment method to commerce.

## Keys

The package needs only the **Server Key**: Snap runs as a redirect, so the client key is not used.
Copy it from the Midtrans dashboard ([dashboard.sandbox.midtrans.com](https://dashboard.sandbox.midtrans.com)
for sandbox, [dashboard.midtrans.com](https://dashboard.midtrans.com) for production), Settings →
Access Keys, into `.env`:

```dotenv
MIDTRANS_SANDBOX_SERVER_KEY=SB-Mid-server-…
MIDTRANS_SERVER_KEY=Mid-server-…
```

Keys stay in `.env`, never in the store settings. The settings form shows only whether each key is
set.

> The dashboard menu paths here (Settings → Access Keys, Settings → Payment → Notification URL)
> come from the original commerce implementation and were not checked against Midtrans' live
> dashboard or documentation. If a menu has moved, look for "Access Keys" and "Notification URL" in the
> dashboard's settings, and see [docs.midtrans.com](https://docs.midtrans.com).

## The notification URL

In each dashboard, sandbox and production, set the **Payment Notification URL** (Settings →
Payment → Notification URL) to:

```
https://<your-store>/commerce/webhooks/midtrans
```

This is commerce's webhook route for the method key `midtrans`. The Finish URL needs no setting:
each transaction sends the customer back to `/order/{key}/payment/return`.

## Switching it on

1. Settings → Store → **Features**: switch on **Midtrans** (group Payments). It needs the
   `payments` module and is off by default.
2. Settings → Store → **Payments**: switch on the method, set its title and instructions per
   language, and choose sandbox or production.

The method is offered at checkout only when the store currency is IDR and the server key for the
current mode is set.

## Sandbox

**Sandbox (test mode)** is on until you switch it off. On, the method uses
`MIDTRANS_SANDBOX_SERVER_KEY` and Midtrans' sandbox; off, `MIDTRANS_SERVER_KEY` and production.
The switch's help text names which keys are set and shows the notification URL. Each payment
remembers whether it was made in sandbox (`meta.sandbox`).

Pay in sandbox with Midtrans' test cards and simulators, then switch the method to production when
the production key and notification URL are in place.

## How it works

- At checkout (and on the pay page) the method creates a Snap transaction once per payment: order
  id `{order number}-{payment id}-{random}`, the payment's amount in whole rupiah, the customer's
  name, email and phone, the finish URL, and an expiry at the payment deadline. The redirect URL
  and Snap token are stored on the payment. Paying again with the same method reuses the page; the
  order page and the "order received" email link to it while the payment is pending.
- Notifications are verified with `signature_key` = SHA-512 of `order_id`, `status_code`,
  `gross_amount` and the server key. A bad signature, or a paid amount that isn't the payment's,
  answers `400` (the call is logged `failed`).
- The event id is the transaction id with the status (and fraud status, and refunded total), so a
  repeated notification is a duplicate and changes nothing.
- When the customer comes back, and once before an unpaid order expires, the method asks Midtrans
  for the status (`Transaction::status`) instead of trusting the query string. A transaction
  Midtrans doesn't know yet (the customer never chose a channel) stays pending.

## Status mapping

| `transaction_status` | Result |
| --- | --- |
| `settlement`; `capture` with `fraud_status` `accept` | paid |
| `pending`; `capture` with `challenge` (accept or deny it in the dashboard) | pending |
| `deny`, `cancel`, `failure`; `capture` with `deny` | failed |
| `expire` | expired (the order is cancelled) |
| `refund`, `partial_refund` | refunded, by the refunded total |

Paid times come from `settlement_time` (or `transaction_time`), read in Asia/Jakarta time.

## Refunds

Refund in the Midtrans dashboard. Midtrans then sends a `refund` or `partial_refund` notification,
and the method records the difference between Midtrans' refunded total (the sum of its `refunds`)
and what the payment already has, so each refund is counted once. A full refund marks the payment
refunded. The method does not send refunds to Midtrans from the store's admin.

## Configuration

`config/commerce-midtrans.php` (key `commerce-midtrans`); publish it with
`php artisan vendor:publish --tag=commerce-midtrans-config`.

| Key | Default | |
| --- | --- | --- |
| `commerce-midtrans.server_key` | `env('MIDTRANS_SERVER_KEY')` | Production server key |
| `commerce-midtrans.sandbox_server_key` | `env('MIDTRANS_SANDBOX_SERVER_KEY')` | Sandbox server key |

## Testing your store

The SDK calls go through `Sunrice\CommerceMidtrans\MidtransClient` (bound with `bindIf` to
`MidtransSdkClient`). Bind your own in the container to test without the network:

```php
app()->instance(MidtransClient::class, new FakeMidtransClient);
```

This package's own tests do so (`tests/Feature/MidtransTest.php`). Run them with `composer test`,
`composer lint` and `composer analyse`.

## License

MIT. See [LICENSE](LICENSE).
