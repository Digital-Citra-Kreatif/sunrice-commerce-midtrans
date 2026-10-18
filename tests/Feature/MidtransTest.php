<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Sunrice\Commerce\Catalog\Models\Variant;
use Sunrice\Commerce\Features\Actions\ToggleFeature;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Payments\Events\PaymentRecorded;
use Sunrice\Commerce\Payments\Models\WebhookCall;
use Sunrice\Commerce\Payments\PaymentContext;
use Sunrice\Commerce\Payments\PaymentManager;
use Sunrice\Commerce\Settings\StoreSettings;
use Sunrice\CommerceMidtrans\MidtransClient;

/*
 * Midtrans Snap with a fake client (no network): the redirect, its reuse,
 * signed notifications through the webhook route, and the status check.
 */

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config(['honeypot.enabled' => false, 'commerce-midtrans.sandbox_server_key' => 'SB-Mid-server-test']);
    $this->mug = checkoutStore();
    app(ToggleFeature::class)->handle('midtrans', true);
    app(StoreSettings::class)->saveSection('payments', ['methods' => ['midtrans' => ['active' => true, 'title' => ['id' => 'Midtrans'], 'instructions' => ['id' => 'Bayar lewat Midtrans.']]]]);
    $this->midtrans = new class implements MidtransClient
    {
        public array $created = [];

        public array $statuses = [];

        public function installed(): bool
        {
            return true;
        }

        public function createTransaction(array $params, string $serverKey, bool $production): array
        {
            $this->created[] = [$params, $serverKey, $production];

            return ['token' => 'tok-'.count($this->created), 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-'.count($this->created)];
        }

        public function status(string $orderId, string $serverKey, bool $production): array
        {
            return $this->statuses[$orderId] ?? throw new RuntimeException('Not found', 404);
        }
    };
    app()->instance(MidtransClient::class, $this->midtrans);
    app()->forgetScopedInstances();
});

function placeWithMidtrans(Variant $mug): Order
{
    $cart = cartWith([[$mug, 1]]);
    $input = checkoutInput(['payment_method' => 'midtrans']);
    test()->post('/checkout', [...$input, 'action' => 'place', 'totals_hash' => seenHash($cart, $input)])
        ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/tok-1');

    return Order::query()->sole();
}

/** A notification signed with the server key, for the order's payment. */
function midtransNotify(Order $order, array $data, string $key = 'SB-Mid-server-test'): TestResponse
{
    $data = ['order_id' => $order->payments()->sole()->reference, 'status_code' => '200', 'gross_amount' => '60000.00', 'transaction_id' => 'tx-1', ...$data];
    $data['signature_key'] ??= hash('sha512', $data['order_id'].$data['status_code'].$data['gross_amount'].$key);

    return test()->postJson('/commerce/webhooks/midtrans', $data);
}

it('is offered only while its module is on and its key is set', function () {
    $offered = fn () => array_keys(app(PaymentManager::class)->available(new PaymentContext(60000)));
    expect($offered())->toContain('midtrans');

    config(['commerce-midtrans.sandbox_server_key' => null]);
    app()->forgetScopedInstances();
    expect($offered())->not->toContain('midtrans');

    config(['commerce-midtrans.sandbox_server_key' => 'SB-Mid-server-test']);
    app(ToggleFeature::class)->handle('midtrans', false);
    app()->forgetScopedInstances();
    expect($offered())->not->toContain('midtrans');
    $this->postJson('/commerce/webhooks/midtrans', [])->assertNotFound();
});

it('starts a Snap transaction once per payment and sends the customer to it', function () {
    $order = placeWithMidtrans($this->mug);
    $payment = $order->payments()->sole();
    [$params, $key, $production] = $this->midtrans->created[0];

    expect($key)->toBe('SB-Mid-server-test')->and($production)->toBeFalse()
        ->and($params['transaction_details'])->toBe(['order_id' => $payment->reference, 'gross_amount' => 60000])
        ->and($params['customer_details']['email'])->toBe('rina@example.com')
        ->and($params['callbacks']['finish'])->toEndWith("/order/{$order->order_key}/payment/return")
        ->and($params['expiry']['duration'])->toBeGreaterThan(1430)
        ->and($payment->reference)->toStartWith(preg_replace('/[^A-Za-z0-9]/', '', (string) $order->number).'-'.$payment->id.'-')
        ->and($payment->instructions['url'])->toBe('https://app.sandbox.midtrans.com/snap/v4/redirection/tok-1')
        ->and($payment->meta['sandbox'])->toBeTrue();

    // Paying again with the same method keeps the payment and its Snap page.
    $this->post("/order/{$order->order_key}/pay", ['payment_method' => 'midtrans'])->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/tok-1');
    expect($this->midtrans->created)->toHaveCount(1);

    $this->get($order->url())->assertOk()->assertSee('Bayar lewat Midtrans.')->assertSee('https://app.sandbox.midtrans.com/snap/v4/redirection/tok-1');
});

it('marks the payment paid on a signed settlement, once', function () {
    $order = placeWithMidtrans($this->mug);
    Event::fake([PaymentRecorded::class]);

    midtransNotify($order, ['transaction_status' => 'settlement', 'settlement_time' => '2026-10-18 10:15:00'])->assertOk();
    $order->refresh();
    expect([$order->status, $order->payment_status, $order->paid_total])->toBe(['processing', 'paid', 60000])
        ->and($order->payments()->sole()->paid_at->toIso8601String())->toBe('2026-10-18T03:15:00+00:00');

    midtransNotify($order, ['transaction_status' => 'settlement'])->assertOk()->assertJson(['duplicate' => true]);
    expect($order->fresh()->paid_total)->toBe(60000)
        ->and(WebhookCall::query()->orderBy('id')->pluck('status')->all())->toBe(['processed', 'ignored']);
    Event::assertDispatchedTimes(PaymentRecorded::class, 1);
});

it('refuses a bad signature, another key and a different amount', function () {
    $order = placeWithMidtrans($this->mug);

    midtransNotify($order, ['transaction_status' => 'settlement', 'signature_key' => str_repeat('0', 128)])->assertStatus(400);
    midtransNotify($order, ['transaction_status' => 'settlement'], 'Mid-server-live')->assertStatus(400);
    midtransNotify($order, ['transaction_status' => 'settlement', 'gross_amount' => '1000.00'])->assertStatus(400);

    expect($order->fresh()->payment_status)->toBe('unpaid')
        ->and(WebhookCall::query()->pluck('status')->unique()->all())->toBe(['failed'])
        ->and(WebhookCall::query()->latest('id')->value('error'))->toBe('The paid amount does not match the payment.');
});

it('keeps pending and challenged payments pending', function () {
    $order = placeWithMidtrans($this->mug);

    midtransNotify($order, ['transaction_status' => 'pending', 'status_code' => '201'])->assertOk();
    midtransNotify($order, ['transaction_status' => 'capture', 'fraud_status' => 'challenge'])->assertOk();
    expect([$order->fresh()->status, $order->fresh()->payment_status])->toBe(['pending', 'unpaid']);

    midtransNotify($order, ['transaction_status' => 'capture', 'fraud_status' => 'accept'])->assertOk();
    expect($order->fresh()->payment_status)->toBe('paid');
});

it('maps denials to failed and expiry to a cancelled order', function () {
    $order = placeWithMidtrans($this->mug);
    midtransNotify($order, ['transaction_status' => 'deny', 'status_code' => '202'])->assertOk();
    expect([$order->fresh()->status, $order->fresh()->payment_status])->toBe(['failed', 'failed']);

    midtransNotify($order, ['transaction_status' => 'expire', 'status_code' => '407'])->assertOk();
    expect([$order->fresh()->status, $order->payments()->sole()->status])->toBe(['cancelled', 'expired']);
});

it('records refunds by the refunded total', function () {
    $order = placeWithMidtrans($this->mug);
    midtransNotify($order, ['transaction_status' => 'settlement'])->assertOk();

    midtransNotify($order, ['transaction_status' => 'partial_refund', 'refunds' => [['refund_chargeback_id' => 1, 'refund_amount' => '20000.00']]])->assertOk();
    expect($order->fresh()->refunded_total)->toBe(20000);
    midtransNotify($order, ['transaction_status' => 'refund', 'refunds' => [['refund_chargeback_id' => 1, 'refund_amount' => '20000.00'], ['refund_chargeback_id' => 2, 'refund_amount' => '40000.00']]])->assertOk();
    expect([$order->fresh()->refunded_total, $order->fresh()->payment_status])->toBe([60000, 'refunded']);
});

it('asks Midtrans for the status on the return and before expiring', function () {
    $order = placeWithMidtrans($this->mug);
    $reference = $order->payments()->sole()->reference;

    // Never chose a channel: Midtrans answers 404, still pending.
    $this->get("/order/{$order->order_key}/payment/return?transaction_status=settlement")->assertRedirect($order->url());
    expect($order->fresh()->payment_status)->toBe('unpaid');

    $this->midtrans->statuses[$reference] = ['order_id' => $reference, 'transaction_status' => 'settlement', 'gross_amount' => '60000.00'];
    $this->travel(25)->hours();
    $this->artisan('commerce:tick');
    expect($order->fresh()->payment_status)->toBe('paid');
});

it('shows which keys are set in its settings, never the keys', function () {
    actingAsUserWith(['commerce.settings.payments']);
    $response = $this->get('/cms/commerce/settings/payments/midtrans')->assertOk();
    $page = json_encode($response->viewData('page'));

    expect($page)->toContain('MIDTRANS_SANDBOX_SERVER_KEY (set)')->toContain('MIDTRANS_SERVER_KEY (missing)')
        ->toContain('commerce\/webhooks\/midtrans')->not->toContain('SB-Mid-server-test');

    $this->put('/cms/commerce/settings/payments/midtrans', ['values' => ['active' => true, 'sandbox' => false]])->assertRedirect('/cms/commerce/settings/payments');
    app()->forgetScopedInstances();
    expect(app(StoreSettings::class)->get('payments.methods.midtrans.sandbox'))->toBeFalse()
        ->and(array_keys(app(PaymentManager::class)->available(new PaymentContext(60000))))->not->toContain('midtrans');
});
