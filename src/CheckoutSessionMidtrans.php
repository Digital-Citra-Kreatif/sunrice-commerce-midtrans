<?php

declare(strict_types=1);

namespace Sunrice\CommerceMidtrans;

use Sunrice\Commerce\Payments\Contracts\StartsCheckoutSession;

// Older Commerce releases still use the same gateway after placing an order.
if (interface_exists(StartsCheckoutSession::class)) {
    final class CheckoutSessionMidtrans extends Midtrans implements StartsCheckoutSession {}
}
