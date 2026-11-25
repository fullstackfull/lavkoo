<?php

namespace App\Providers;

use App\Services\PlatformAdmin\PlatformAdminPushIsNotConfigured;
use App\Services\PlatformAdmin\PlatformAdminPushTransport;
use Illuminate\Support\ServiceProvider;

/**
 * What the platform admin API binds (GCC-20).
 *
 * One binding: the push transport, which is the one that sends NOTHING. Whether push is wanted at all, for which severities, and through which
 * project are the owner's open decisions (contract s.10 item 9), so a device may register, the server may name its topics and the row may be
 * removed when the session ends -- all of it testable -- without this platform sending a notification to anybody before that is decided.
 *
 * Everything else of this API resolves itself: its services are plain constructor-injected classes, and the ones that must serve a whole request
 * once carry the container's own `#[Scoped]` attribute rather than a registration here.
 */
class PlatformAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PlatformAdminPushTransport::class, PlatformAdminPushIsNotConfigured::class);
    }
}
