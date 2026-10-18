<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Midtrans
|--------------------------------------------------------------------------
|
| Server keys for the Midtrans method (module `midtrans`). Secrets stay in
| .env, never in the store settings. The method's "Sandbox" switch in
| Settings → Store → Payments picks the sandbox key.
|
*/

return [
    'server_key' => env('MIDTRANS_SERVER_KEY'),
    'sandbox_server_key' => env('MIDTRANS_SANDBOX_SERVER_KEY'),
];
