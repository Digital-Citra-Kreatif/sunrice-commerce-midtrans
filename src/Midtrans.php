<?php

declare(strict_types=1);

namespace Sunrice\Midtrans;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Payments\InvalidWebhook;
use Sunrice\Commerce\Payments\Methods\GatewayMethod;
use Sunrice\Commerce\Payments\Models\Payment;
use Sunrice\Commerce\Payments\PaymentResult;
use Sunrice\Commerce\Payments\WebhookEvent;
use Throwable;

/**
 * Midtrans Snap (redirect): cards, e-wallets, QRIS, virtual accounts and
 * more on Midtrans' payment page. Notifications are verified with
 * signature_key = SHA512(order_id + status_code + gross_amount + server
 * key). The server key comes from MIDTRANS_SERVER_KEY (or
 * MIDTRANS_SANDBOX_SERVER_KEY in sandbox mode).
 */
class Midtrans extends GatewayMethod
{
    public function __construct(protected MidtransClient $client) {}

    public static function key(): string
    {
        return 'midtrans';
    }

    public static function feature(): ?string
    {
        return 'midtrans';
    }

    public function label(): string
    {
        return 'Midtrans';
    }

    public function settingsSchema(): array
    {
        return $this->gatewayFields([
            'sandbox' => ['midtrans.sandbox_server_key' => 'MIDTRANS_SANDBOX_SERVER_KEY'],
            'live' => ['midtrans.server_key' => 'MIDTRANS_SERVER_KEY'],
        ]);
    }

    public function configured(): bool
    {
        return $this->serverKey() !== '' && $this->client->installed();
    }

    protected function currencies(): array
    {
        return ['IDR'];
    }

    protected function serverKey(): string
    {
        return (string) config($this->sandbox() ? 'midtrans.sandbox_server_key' : 'midtrans.server_key');
    }

    /** Midtrans takes whole rupiah. */
    protected function toGateway(int $amount): float|int
    {
        return (int) round(parent::toGateway($amount));
    }

    protected function createPayment(Order $order, Payment $payment, string $reference): array
    {
        $params = [
            'transaction_details' => ['order_id' => $reference, 'gross_amount' => $this->toGateway($payment->amount)],
            'customer_details' => array_filter(['first_name' => $order->customer_name, 'email' => $order->email, 'phone' => $order->phone]),
            'callbacks' => ['finish' => commerce_url('commerce.order.payment-return', ['key' => $order->order_key])],
        ];
        if ($payment->expires_at !== null && $payment->expires_at->isFuture()) {
            $params['expiry'] = ['start_time' => now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s O'), 'unit' => 'minute',
                'duration' => max(1, (int) now()->diffInMinutes($payment->expires_at))];
        }
        $snap = $this->client->createTransaction($params, $this->serverKey(), ! $this->sandbox());

        return ['url' => $snap['redirect_url'], 'meta' => ['snap_token' => $snap['token']]];
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = (array) $request->json()->all();
        $key = $this->serverKey();
        $signed = (string) ($data['order_id'] ?? '').($data['status_code'] ?? '').($data['gross_amount'] ?? '').$key;
        if ($key === '' || ! isset($data['order_id'], $data['transaction_status']) || ! hash_equals(hash('sha512', $signed), (string) ($data['signature_key'] ?? ''))) {
            throw new InvalidWebhook('Invalid Midtrans signature.');
        }
        $result = $this->result($data, $this->paymentFor((string) $data['order_id']));
        if ($result === null) {
            throw new InvalidWebhook('The paid amount does not match the payment.');
        }
        // One event per transaction status (and refunded total): the same notification twice is a duplicate.
        $event = implode(':', array_filter([$data['transaction_id'] ?? $data['order_id'], $data['transaction_status'], $data['fraud_status'] ?? null,
            $result->status === 'refunded' ? $this->refunded($data) : null], fn ($v) => $v !== null && $v !== ''));

        return new WebhookEvent($event, (string) $data['order_id'], $result);
    }

    public function check(Payment $payment): PaymentResult
    {
        if ($payment->reference === null) {
            return PaymentResult::pending();
        }
        try {
            $data = $this->client->status($payment->reference, $this->serverKey(), ! $this->sandbox());
        } catch (Throwable $e) {
            if ($e->getCode() === 404) {
                return PaymentResult::pending($payment->reference); // the customer never chose a payment channel
            }
            throw $e;
        }

        return $this->result($data, $payment) ?? PaymentResult::pending($payment->reference);
    }

    /**
     * Maps a notification or status response (null: paid, but not the payment's amount).
     *
     * @param  array<string, mixed>  $data
     */
    protected function result(array $data, ?Payment $payment): ?PaymentResult
    {
        $reference = (string) ($data['order_id'] ?? $payment?->reference);
        $raw = Arr::except($data, ['signature_key']);
        $at = $this->time($data['settlement_time'] ?? $data['transaction_time'] ?? null, 'Asia/Jakarta');
        $paid = fn () => $this->paidResult($payment, (float) ($data['gross_amount'] ?? 0), $reference, $at, $raw);

        return match ((string) ($data['transaction_status'] ?? '')) {
            'settlement' => $paid(),
            'capture' => match ((string) ($data['fraud_status'] ?? 'accept')) {
                'accept' => $paid(),
                'deny' => PaymentResult::failed($reference, $raw),
                default => PaymentResult::pending($reference), // challenge: approve or deny it in the Midtrans dashboard
            },
            'deny', 'cancel', 'failure' => PaymentResult::failed($reference, $raw),
            'expire' => new PaymentResult('expired', null, $reference, $at, $raw),
            'refund', 'partial_refund' => PaymentResult::refunded(max(0, $this->fromGateway($this->refunded($data)) - (int) $payment?->refunded_amount), $reference),
            default => PaymentResult::pending($reference),
        };
    }

    /**
     * The total refunded so far, in rupiah.
     *
     * @param  array<string, mixed>  $data
     */
    protected function refunded(array $data): float
    {
        $refunds = array_filter((array) ($data['refunds'] ?? []), 'is_array');

        return $refunds !== [] ? (float) array_sum(array_map(fn (array $r) => (float) ($r['refund_amount'] ?? 0), $refunds)) : (float) ($data['refund_amount'] ?? 0);
    }
}
