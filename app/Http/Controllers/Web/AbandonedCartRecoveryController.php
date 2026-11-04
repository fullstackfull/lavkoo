<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCartReminder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The link inside the abandoned-cart email (Phase 2.4).
 *
 * The route carries Laravel's `signed` middleware, so the reminder id in the URL cannot be walked:
 * without the signature a visitor cannot mark other people's reminders as clicked, or enumerate
 * which addresses were mailed.
 *
 * What this deliberately does **not** do is hand a cart to whoever holds the link:
 *
 *  - For a registered customer the cart already belongs to their account, so the link only records
 *    the click and sends them to the cart page. If they are not signed in, the platform's own
 *    redirect asks them to sign in first — the contents are never rendered to an anonymous visitor.
 *  - For a guest the cart is keyed by a guest id in the session, so recovery means restoring that
 *    id. That is inherent to guest recovery, and it is why guest reminders are off by default.
 */
class AbandonedCartRecoveryController extends Controller
{
    public function recover(Request $request, int $reminder): RedirectResponse
    {
        $record = AbandonedCartReminder::find($reminder);

        if (!$record) {
            return redirect()->route('home');
        }

        // First click only. A second visit is the same person opening the mail twice, and
        // overwriting the timestamp would misreport when the message actually worked.
        if (!$record->clicked_at) {
            $record->forceFill(['clicked_at' => now()])->save();
        }

        if ($record->is_guest && $record->customer_id === null) {
            // The guest id was not stored on the reminder (a guest id is not a user id), so it is
            // recovered from the cart group itself, which is still keyed by it.
            $guestId = \App\Models\Cart::where('cart_group_id', $record->cart_group_id)
                ->where('is_guest', 1)
                ->value('customer_id');

            if ($guestId) {
                session(['guest_id' => $guestId]);
            }

            // shop-cart requires a signed-in customer when guest checkout is disabled, which would
            // strand a guest at a login form. The home page shows the cart panel either way.
            return redirect()->route('home');
        }

        return redirect()->route('shop-cart');
    }
}
