<?php

declare(strict_types=1);

namespace Sunrice\CommerceMidtrans;

use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;
use RuntimeException;

/** MidtransClient through midtrans/midtrans-php. */
class MidtransSdkClient implements MidtransClient
{
    public function installed(): bool
    {
        return class_exists(Snap::class);
    }

    public function createTransaction(array $params, string $serverKey, bool $production): array
    {
        $this->configure($serverKey, $production);
        $response = (array) Snap::createTransaction($params);

        return ['token' => (string) ($response['token'] ?? ''), 'redirect_url' => (string) ($response['redirect_url'] ?? '')];
    }

    public function status(string $orderId, string $serverKey, bool $production): array
    {
        $this->configure($serverKey, $production);

        return (array) json_decode((string) json_encode(Transaction::status($orderId)), true);
    }

    /** The SDK keeps its settings in static properties: set them before every call. */
    protected function configure(string $serverKey, bool $production): void
    {
        if (! $this->installed()) {
            throw new RuntimeException('The Midtrans payment method needs its SDK: composer require midtrans/midtrans-php');
        }
        Config::$serverKey = $serverKey;
        Config::$isProduction = $production;
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }
}
