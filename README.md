# Sunrice Commerce Midtrans

[Midtrans Snap](https://midtrans.com) payments for [Sunrice Commerce](https://github.com/Digital-Citra-Kreatif/sunrice-commerce):
the customer is sent to Midtrans' payment page and picks a card, e-wallet, QRIS, virtual account or
other channel there. Notifications are verified, mapped to the payment's state and recorded by
commerce; the order follows.

Requirements: PHP 8.4+, Laravel 13, Sunrice Commerce 0.1, 0.2 or 0.3 (`sunrice/commerce`, which Composer installs with it), a
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
- [The scheduler](#the-scheduler)
- [Deposits](#deposits)
- [Troubleshooting](#troubleshooting)
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
and what the payment already has, so each refund is counted once, also when staff recorded
it in the admin first. A full refund marks the payment
refunded. The method does not send refunds to Midtrans from the store's admin.

## The scheduler

Run Laravel's scheduler, which runs commerce's `commerce:tick` every minute:

```cron
* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
```

It is the safety net for webhooks. When an unpaid payment's deadline passes, `commerce:tick` first
asks Midtrans for its status, so a payment whose notification was lost is still recorded as paid.
Only a payment that is still unpaid then expires, which cancels the order. Without the scheduler, a
lost notification leaves the order unpaid and unpaid orders never expire.

## Deposits

When an extension asks for a deposit (commerce's
[Deposits](https://github.com/Digital-Citra-Kreatif/sunrice-commerce/blob/main/docs/payments.md#deposits),
Sunrice Commerce 0.3), the deposit and the balance are two separate payments, each with its own
Midtrans Snap transaction for its own amount. The deposit is charged at checkout, the balance when
the customer pays it from the order page. A notification for the balance with the deposit's amount,
or the reverse, is refused like any other wrong amount. An expired balance leaves the order open and
Partially paid.

## Troubleshooting

Commerce stores every call to `/commerce/webhooks/midtrans` in the `commerce_webhook_calls` table
(kept 30 days, `commerce.webhooks.keep_days`). The admin has no page for it, so query it:

```sql
SELECT received_at, status, error, event_id, payment_id
FROM commerce_webhook_calls WHERE method = 'midtrans' ORDER BY id DESC LIMIT 20;
```

| `status` | Meaning |
| --- | --- |
| `processed` | Verified and recorded: the payment (and order) changed. |
| `ignored` | Verified, but nothing to change: a duplicate, a status the payment already has, or an unknown reference (`error` says which). |
| `failed` | Refused with `400` (`error` says why), or failed while recording with `500` (Midtrans retries). |

If a payment doesn't update:

- **No rows at all**: Midtrans isn't calling the store. Check that the notification URL is set in
  the dashboard for the mode you're paying in (test and live are separate), that it is reachable
  from the internet, and that the Payments and Midtrans modules are on (a module that's off answers
  `404`).
- **`failed`, signature**: the server key (`MIDTRANS_SANDBOX_SERVER_KEY` or `MIDTRANS_SERVER_KEY`)
  in `.env` belongs to the other mode, or the method's **Sandbox** switch doesn't match the
  dashboard you're paying in. After editing `.env`, clear the config cache
  (`php artisan config:clear`).
- **`failed`, amount**: Midtrans reported a different amount from the payment's, and the payment
  stays unpaid. Check the transaction in the dashboard, then mark the order paid by hand if the
  money arrived.
- **`ignored`, unknown reference**: the notification is for a transaction this store didn't create
  (another site on the same Midtrans account, or a dashboard test).
- **No change after the customer paid**: wait for the notification or the scheduler's check (see
  [The scheduler](#the-scheduler)). A `capture` with `fraud_status` `challenge` waits for you to
  accept or deny it in the Midtrans dashboard.

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
