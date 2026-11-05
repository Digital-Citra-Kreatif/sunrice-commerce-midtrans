<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Sunrice\Admin\Actions\Permissions\SyncPermissions;
use Sunrice\Admin\Panel;
use Sunrice\Cms\Actions\Entries\CreateEntry;
use Sunrice\Cms\Actions\Entries\PublishEntry;
use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\Entry;
use Sunrice\Commerce\Cart\CartView;
use Sunrice\Commerce\Cart\Models\Cart;
use Sunrice\Commerce\Catalog\Actions\ScaffoldStore;
use Sunrice\Commerce\Catalog\Models\Variant;
use Sunrice\Commerce\Checkout\CheckoutData;
use Sunrice\Commerce\Regions\RegionImporter;
use Sunrice\Commerce\Shipping\Models\ShippingMethod;
use Sunrice\Commerce\Shipping\Models\ShippingZone;
use Sunrice\CommerceMidtrans\Tests\TestCase;
use Workbench\App\Models\User;

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Test helpers (from commerce's tests/Pest.php)
|--------------------------------------------------------------------------
*/

/**
 * Sign in to the admin as a user whose role has these permissions (plus
 * the panel's access).
 *
 * @param  array<int, string>  $permissions
 */
function actingAsUserWith(array $permissions, string $email = 'staff@example.com'): User
{
    app(SyncPermissions::class)->handle();
    $role = Role::findOrCreate('Role '.md5($email), 'web');
    $role->givePermissionTo([Panel::ACCESS, ...$permissions]);
    $user = User::query()->create(['name' => 'Staff', 'email' => $email, 'password' => bcrypt('password')]);
    $user->assignRole($role);
    test()->actingAs($user, 'web');

    return $user;
}

/**
 * Create and publish a product entry in the store's products collection
 * (ScaffoldStore makes it), with the CMS's own actions.
 *
 * @param  array<string, mixed>  $value  the product field value
 */
function publishProduct(array $value, string $title): Entry
{
    $collection = Collection::query()->where('handle', 'products')->firstOrFail();
    $translation = app(CreateEntry::class)->handle($collection, ['title' => $title, 'slug' => Str::slug($title), 'data' => ['product' => $value]]);
    app(PublishEntry::class)->handle($translation->fresh());

    return $translation->entry->fresh();
}

/**
 * A store ready for checkout: regions, a "Mug" (50.000, 500 g, 5 in
 * stock) and shipping to Indonesia at a flat 10.000. Returns the mug
 * variant.
 */
function checkoutStore(): Variant
{
    (new RegionImporter(__DIR__.'/Fixtures/regions'))->import(4);
    app(ScaffoldStore::class)->handle();
    publishProduct(['variants' => [['id' => 'mug', 'sku' => 'MUG', 'price' => 50000, 'weight' => 500, 'track_stock' => true, 'initial_stock' => 5]]], title: 'Mug');
    $zone = ShippingZone::query()->create(['name' => 'Indonesia']);
    $zone->regions()->create(['country' => 'ID']);
    $zone->methods()->create(['type' => 'flat', 'title' => ['id' => 'Reguler', 'en' => 'Regular'], 'settings' => ['cost' => 10000], 'active' => true]);
    app()->forgetScopedInstances();

    return Variant::query()->where('sku', 'MUG')->firstOrFail();
}

/** Checkout input for a Bandung address with the flat rate. @return array<string, mixed> */
function checkoutInput(array $overrides = []): array
{
    $method = ShippingMethod::query()->where('type', 'flat')->value('id');

    return array_replace_recursive([
        'name' => 'Rina', 'email' => 'rina@example.com', 'phone' => '0812 3456 7890',
        'shipping_address' => ['name' => 'Rina', 'phone' => '0812 3456 7890', 'country' => 'ID', 'province_code' => '32', 'city_code' => '32.73',
            'district_code' => '32.73.01', 'line1' => 'Jl. Sarijadi 1'],
        'billing_same' => '1',
        'shipping_rate' => "flat:{$method}:default",
    ], $overrides);
}

/** A guest cart with these lines, sent as the visitor's cookie on the next requests. */
function cartWith(array $lines): Cart
{
    $cart = Cart::query()->create(['token' => Str::random(64), 'status' => 'active', 'currency' => 'IDR', 'last_activity_at' => now()]);
    foreach ($lines as [$variant, $qty]) {
        $cart->items()->create(['variant_id' => $variant->id, 'quantity' => $qty]);
    }
    test()->withCookie((string) config('commerce.cart.cookie', 'commerce_cart'), $cart->token);

    return $cart;
}

/** The totals hash of what the cart would cost with this input. */
function seenHash(Cart $cart, array $input): string
{
    app()->forgetScopedInstances();

    return CartView::for($cart->fresh(), CheckoutData::fromCart($cart->fresh())->merge($input)->context())->state->hash();
}
