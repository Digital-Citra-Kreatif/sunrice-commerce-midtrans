# Changelog

## Unreleased — 1.0.0

The first release, moved out of Sunrice Commerce. Needs Sunrice Commerce with
`Payments\Methods\GatewayMethod` (`dev-main` until commerce is tagged), PHP 8.4 and Laravel 13.

Renamed before the first release to match the other commerce packages (`sunrice/commerce-biteship`):
the package is `sunrice/commerce-midtrans` (was `sunrice/midtrans`), the namespace
`Sunrice\CommerceMidtrans\` (was `Sunrice\Midtrans\`), the config `config/commerce-midtrans.php` with
key `commerce-midtrans` and publish tag `commerce-midtrans-config` (were `midtrans` and
`midtrans-config`), and the repository
[sunrice-commerce-midtrans](https://github.com/Digital-Citra-Kreatif/sunrice-commerce-midtrans). The
module and method key stay `midtrans`, so the webhook URL and the `.env` keys are unchanged.

- The `midtrans` module (group Payments, needs `payments`, off by default) and the `midtrans`
  payment method: Midtrans Snap as a redirect, created once per payment and reused.
- Notifications verified with SHA-512 of `order_id`, `status_code`, `gross_amount` and the server
  key; settlement and accepted captures paid, pending and challenged captures pending, denials
  failed, expiry expired, refunds recorded by the refunded total.
- The status asked from Midtrans on the customer's return and before an unpaid order expires.
- Keys in `.env` (`MIDTRANS_SERVER_KEY`, `MIDTRANS_SANDBOX_SERVER_KEY`) through
  `config/commerce-midtrans.php`; the settings form shows only whether each is set.
- `MidtransClient` (bound with `bindIf`) so stores and tests can swap the SDK calls.
