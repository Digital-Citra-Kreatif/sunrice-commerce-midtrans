<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Sunrice\Commerce\Commerce;
use Sunrice\Commerce\Features\FeatureRegistry;
use Sunrice\Commerce\Payments\PaymentRegistry;
use Sunrice\Midtrans\Midtrans;
use Sunrice\Midtrans\MidtransClient;
use Sunrice\Midtrans\MidtransSdkClient;
use Sunrice\Midtrans\MidtransServiceProvider;

it('registers the midtrans module, off by default and needing payments', function () {
    $feature = app(FeatureRegistry::class)->get('midtrans');

    expect($feature)->not->toBeNull()
        ->and([$feature->group, $feature->requires, $feature->default])->toBe(['payments', ['payments'], false])
        ->and(app(Commerce::class)->active('midtrans'))->toBeFalse();
});

it('registers the method under the key midtrans, with the SDK client', function () {
    expect(app(PaymentRegistry::class)->classes()['midtrans'] ?? null)->toBe(Midtrans::class)
        ->and(app(MidtransClient::class))->toBeInstanceOf(MidtransSdkClient::class)
        ->and(app(MidtransClient::class)->installed())->toBeTrue();
});

it('reads its keys from config/midtrans.php, which can be published', function () {
    expect(config()->has('midtrans.server_key'))->toBeTrue()
        ->and(config()->has('midtrans.sandbox_server_key'))->toBeTrue()
        ->and(ServiceProvider::pathsToPublish(MidtransServiceProvider::class, 'midtrans-config'))->toHaveCount(1);
});
