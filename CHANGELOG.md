# Changelog

## Unreleased — 1.0.0

The first release, moved out of Sunrice Commerce. Needs Sunrice Commerce with
`Payments\Methods\GatewayMethod` (`dev-main` until commerce is tagged), PHP 8.4 and Laravel 13.

- The `midtrans` module (group Payments, needs `payments`, off by default) and the `midtrans`
  payment method: Midtrans Snap as a redirect, created once per payment and reused.
- Notifications verified with SHA-512 of `order_id`, `status_code`, `gross_amount` and the server
  key; settlement and accepted captures paid, pending and challenged captures pending, denials
  failed, expiry expired, refunds recorded by the refunded total.
- The status asked from Midtrans on the customer's return and before an unpaid order expires.
- Keys in `.env` (`MIDTRANS_SERVER_KEY`, `MIDTRANS_SANDBOX_SERVER_KEY`) through `config/midtrans.php`;
  the settings form shows only whether each is set.
- `MidtransClient` (bound with `bindIf`) so stores and tests can swap the SDK calls.
