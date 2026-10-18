<?php

declare(strict_types=1);

namespace Sunrice\Midtrans;

/**
 * The calls the Midtrans method makes (bound to MidtransSdkClient, which
 * uses midtrans/midtrans-php). Swap it in the container to test without
 * the network.
 */
interface MidtransClient
{
    /** Whether the SDK is installed. */
    public function installed(): bool;

    /**
     * Snap::createTransaction().
     *
     * @param  array<string, mixed>  $params
     * @return array{token: string, redirect_url: string}
     */
    public function createTransaction(array $params, string $serverKey, bool $production): array;

    /**
     * Transaction::status() by order id: the same fields as a notification.
     *
     * @return array<string, mixed>
     */
    public function status(string $orderId, string $serverKey, bool $production): array;
}
