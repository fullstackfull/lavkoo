<?php

use App\Http\Controllers\Admin\Notification\NotificationAccountController;
use Illuminate\Support\Facades\Route;

/*
| The notification accounts of each market in the admin panel (I1-01, I1-02): where an operator gives a market its own mail host, credentials and from address, and sends a
| test e-mail through it to see whether they work.
|
| It sits behind `module:3rd_party_setup`, which is the permission the one shared mail configuration already sits behind
| (`admin.third-party.mail.*`): a person who may change where the platform's e-mail leaves from is the person who may change where a market's does. The markets a given
| employee may configure are narrowed again inside the screen by their country scope, so the permission opens the screen and the scope decides what is on it.
|
| The screen works while GCC_STOREFRONTS_ENABLED is off, as the storefront screens do, because an account is prepared before a market is switched on -- but it says plainly
| when GCC_NOTIFICATION_PROFILES is off, because an account saved then is stored and read by nothing. Both POSTs have a throttle bucket of their own, the test send a tight
| one: it is the only admin action on the platform that makes an outbound SMTP connection to an address and a host the caller just typed.
*/
Route::group([
    'prefix' => 'admin/notification-accounts',
    'as' => 'admin.notification-accounts.',
    'middleware' => ['admin', 'actch:admin_panel', 'module:3rd_party_setup'],
], function () {
    Route::get('/', [NotificationAccountController::class, 'index'])->name('index');

    Route::post('mail', [NotificationAccountController::class, 'saveMailAccount'])->middleware('throttle:60,1,notification-account-write')->name('mail.save');
    Route::post('mail/test', [NotificationAccountController::class, 'sendTestMail'])->middleware('throttle:10,1,notification-account-test')->name('mail.test');
});
