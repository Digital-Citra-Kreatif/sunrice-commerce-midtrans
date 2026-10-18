<?php

declare(strict_types=1);

namespace Sunrice\Midtrans;

use Illuminate\Support\ServiceProvider;
use Sunrice\Commerce\Facades\Commerce;
use Sunrice\Commerce\Features\Feature;

/**
 * The Midtrans module and payment method for Sunrice Commerce. Keys come
 * from config/midtrans.php (.env); the SDK client can be swapped in the
 * container.
 */
class MidtransServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/midtrans.php', 'midtrans');
        $this->app->bindIf(MidtransClient::class, MidtransSdkClient::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/midtrans.php' => config_path('midtrans.php')], 'midtrans-config');

        Commerce::registerFeature(new Feature('midtrans', 'payments', 'Midtrans', 'Cards, e-wallets, QRIS and virtual accounts on Midtrans Snap. Needs MIDTRANS_SERVER_KEY.',
            ['payments'], ['shop', 'quote'], default: false));
        Commerce::registerPaymentMethod(Midtrans::class);
    }
}
