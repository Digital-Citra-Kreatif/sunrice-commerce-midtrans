# Working on Sunrice Midtrans

The Midtrans Snap payment method for Sunrice Commerce, as an extension package. Commerce's
`docs/extending.md` (Payments) and `docs/extension-package.md` describe the contract; the method
extends commerce's `Payments\Methods\GatewayMethod`.

## Rules

- Use only commerce's public API (`Commerce::register…`, `GatewayMethod`, `PaymentResult`,
  `WebhookEvent`). Missing something? Propose the change in commerce.
- Report results; never update orders or payments yourself (`RecordPaymentResult` does).
- Money is integers in minor units; Midtrans takes whole rupiah.
- Secrets stay in `.env` through `config/midtrans.php`, never in store settings.
- Tests never touch the network: bind a fake `MidtransClient`.
- Every change ships Pest tests (Orchestra Testbench). Before opening a PR run `composer test`,
  `composer lint` and `composer analyse`.
- Update `README.md` and `CHANGELOG.md` when behaviour or keys change.
- Do not commit `vendor/`, `composer.lock` or `.env`.

## Conventions

- PHP 8.4+, Laravel 13. Namespace `Sunrice\Midtrans\` → `src/`; tests `Sunrice\Midtrans\Tests\` →
  `tests/`. Every PHP file starts `declare(strict_types=1);`.
- Config `config/midtrans.php` (key `midtrans`, publish tag `midtrans-config`); module and method
  key `midtrans`.
- Tests boot the same providers as commerce's `tests/TestCase.php`, plus this package's; the
  helpers in `tests/Pest.php` are trimmed copies of commerce's.

## Commands

```bash
composer test      # Pest
composer lint      # Pint (test mode)
composer format    # Pint (fix)
composer analyse   # Larastan
```
