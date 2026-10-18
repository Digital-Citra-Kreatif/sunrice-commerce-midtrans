<?php

declare(strict_types=1);

namespace Sunrice\CommerceMidtrans;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Sunrice\Commerce\Facades\Commerce;
use Sunrice\Commerce\Features\Feature;

/**
 * The Midtrans module and payment method for Sunrice Commerce. Keys come
 * from config/commerce-midtrans.php (.env); the SDK client can be swapped
 * in the container.
 */
class MidtransServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('commerce-midtrans')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->bindIf(MidtransClient::class, MidtransSdkClient::class);
    }

    public function packageBooted(): void
    {
        Commerce::registerFeature(new Feature('midtrans', 'payments', 'Midtrans', 'Cards, e-wallets, QRIS and virtual accounts on Midtrans Snap. Needs MIDTRANS_SERVER_KEY.',
            ['payments'], ['shop', 'quote'], default: false));
        Commerce::registerPaymentMethod(Midtrans::class);
    }
}
