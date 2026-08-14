<?php

namespace App\Providers;

use App\Auth\WordPressEloquentUserProvider;
use App\Auth\WordPressHasher;
use App\Listeners\EnforcePurchaseLimits;
use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $allowedIPs = array_map('trim', explode(',', config('app.debug_allowed_ips', '')));

        $allowedIPs = array_filter($allowedIPs);

        if (empty($allowedIPs)) {
            return;
        }

        if (in_array(Request::ip(), $allowedIPs)) {
            Debugbar::enable();
        } else {
            Debugbar::disable();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });

        /**
         * Custom auth provider used by the "customer" guard. It verifies legacy
         * WordPress/WooCommerce password hashes and upgrades them to Bagisto's
         * native bcrypt format on first login (see config/auth.php).
         */
        Auth::provider('wordpress', function ($app, array $config) {
            return new WordPressEloquentUserProvider(new WordPressHasher, $config['model']);
        });

        /**
         * Per-customer purchase limits (feature list): enforced on every cart
         * write via the `purchase_limit_per_customer` product attribute.
         */
        Event::listen(
            ['checkout.cart.add.after', 'checkout.cart.update.after'],
            EnforcePurchaseLimits::class
        );
    }
}
