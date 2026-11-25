<?php

use App\Http\Controllers\RestAPI\PlatformAdmin\v1\AlertController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\AuditLogController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\AuthController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\DeviceController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\ExportController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\HealthController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\MarketController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\MeController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\OverviewController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\ReportController;
use App\Http\Controllers\RestAPI\PlatformAdmin\v1\SessionController;
use Illuminate\Support\Facades\Route;

/*
| Platform admin API, level 1: read-only monitoring for the platform owner (GCC-20).
|
| Mapped under the `api` prefix by RouteServiceProvider, so the URLs are /api/platform-admin/v1/...
|
| Deliberately NOT in the `api` middleware group: that group records a telemetry row and a response shape for every request, sets a visitor
| cookie and runs the analytics, and the answers here are private. Nothing of the storefront applies either: no storefront is resolved from a
| header or a path, a market is always a validated `market` parameter. The `platform_admin` middleware answers 404 while the API is switched
| off and 503 until its tables exist; `platform_admin_auth` accepts only a token of this API. The only routes without it are the two steps of the sign-in.
|
| Every route but the sign-in, the sign-out and `me` carries `platform_admin_can:<capability>`: the part of the API a grant must include to reach it.
|
| Every route is named `platform_admin.<action>`, and the action is what the audit trail records. The only routes that are not reads are the
| sign-in (two steps), the step-up, the sign-out, the ending of sessions (one, or all but the one in use) and the registering and forgetting of a
| device of this session; no route changes business data: a step-up stamps the session's own row, a device is this session's own phone, and
| nothing else is written.
|
| The export is the one route that also carries `platform_admin_step_up`: it takes a market's rows OFF the platform, so the second factor is proved
| again within minutes of the request and not merely at some point in the last eight hours.
*/
Route::prefix('platform-admin/v1')->middleware('platform_admin')->name('platform_admin.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/mfa', [AuthController::class, 'mfa'])->name('auth.mfa');

    Route::middleware('platform_admin_auth')->group(function () {
        Route::post('auth/step-up', [AuthController::class, 'stepUp'])->name('auth.step_up');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', MeController::class)->name('me.view');
        Route::get('sessions', [SessionController::class, 'index'])->middleware('platform_admin_can:sessions')->name('sessions.list');
        Route::delete('sessions', [SessionController::class, 'endOthers'])->middleware('platform_admin_can:sessions')->name('sessions.end_others');
        Route::delete('sessions/{id}', [SessionController::class, 'destroy'])->whereNumber('id')->middleware('platform_admin_can:sessions')->name('session.revoke');
        Route::get('markets', MarketController::class)->middleware('platform_admin_can:markets')->name('markets.view');
        Route::get('overview', OverviewController::class)->middleware('platform_admin_can:overview')->name('overview.view');
        Route::get('alerts', AlertController::class)->middleware('platform_admin_can:alerts')->name('alerts.view');
        Route::get('audit-logs', AuditLogController::class)->middleware('platform_admin_can:audit')->name('audit_logs.view');
        Route::get('health', HealthController::class)->middleware('platform_admin_can:health')->name('health.view');
        Route::get('devices', [DeviceController::class, 'index'])->middleware('platform_admin_can:alerts')->name('devices.list');
        Route::post('devices', [DeviceController::class, 'store'])->middleware('platform_admin_can:alerts')->name('devices.register');
        Route::delete('devices/{id}', [DeviceController::class, 'destroy'])->whereNumber('id')->middleware('platform_admin_can:alerts')->name('device.forget');
        Route::get('reports', ReportController::class)->middleware('platform_admin_can:reports')->name('reports.view');
        Route::get('exports/orders', [ExportController::class, 'orders'])->middleware(['platform_admin_can:export', 'platform_admin_step_up'])->name('exports.orders');
    });
});
