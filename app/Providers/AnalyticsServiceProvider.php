<?php

namespace App\Providers;

use App\Services\Analytics\CommerceInstrumentation;
use App\Services\Analytics\Support\AnalyticsPolicy;
use Illuminate\Support\ServiceProvider;

/**
 * Wires analytics into the application without the application having to know.
 *
 * Registration is deliberately cheap and guarded: a console command, a migration and an
 * installation run must not pay for it, and a deployment where the analytics tables do not exist
 * yet must still boot. Analytics is an observer of this system, never a participant in it.
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons on purpose: the visitor context resolves the identity and the session once
        // per request, and the recorder holds the buffer that the terminable middleware flushes.
        // Two instances would mean two buffers, one of which nobody ever writes.
        $this->app->singleton(\App\Services\Analytics\VisitorContext::class);
        $this->app->singleton(\App\Services\Analytics\EventRecorder::class);
        $this->app->singleton(\App\Services\Analytics\Analytics::class);
    }

    public function boot(): void
    {
        if (!app(AnalyticsPolicy::class)->enabled()) {
            return;
        }

        // Model listeners are registered even in the console: an order placed by a queued job or
        // an order status changed by a scheduled command is still a real event.
        $this->app->make(CommerceInstrumentation::class)->boot();
    }
}
