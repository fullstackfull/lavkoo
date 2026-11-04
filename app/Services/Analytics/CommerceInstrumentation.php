<?php

namespace App\Services\Analytics;

use App\Models\Order;
use App\Models\ProductCompare;
use App\Models\Review;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;

/**
 * Where commerce events actually come from.
 *
 * The obvious way to instrument a shop is to add a call to every controller that does something
 * interesting. It is also the way that guarantees drift: this project places orders from the web
 * checkout, two REST API versions, the vendor POS and the admin POS, and an event added to one of
 * them is missing from the other four the day it ships. The project's own rule says web and API
 * logic must stay in sync — the only way to keep that promise is to instrument the thing they all
 * have in common.
 *
 * So the commerce events are taken from the models. An order created anywhere in the codebase is
 * one `created` event; a cart row written by the POS looks exactly like one written by the
 * storefront. Views and searches, which write nothing, are instrumented at the controllers that
 * render them — there is no model event for looking at a page.
 *
 * Everything here is best-effort and silent on failure: a listener that could throw would make
 * analytics able to fail an order, which is the one thing it must never do.
 */
class CommerceInstrumentation
{
    public function __construct(private readonly Analytics $analytics)
    {
    }

    public function boot(): void
    {
        // Only the models this application actually writes through Eloquent are listened to.
        // Orders and cart rows are written with DB::table()->insertGetId() and Cart::insertGetId(),
        // which are query-builder calls that fire NO model event — listening for them would be a
        // hook that silently never runs, so those two are instrumented explicitly at their managers
        // instead (OrderManager::generateOrder, CartManager::add_to_cart).
        Order::updated(fn (Order $order) => $this->safely(fn () => $this->orderUpdated($order)));
        Wishlist::created(fn (Wishlist $wishlist) => $this->safely(fn () => $this->analytics->wishlistAdded((int) $wishlist->product_id)));
        // The compare tray is written through the model from the web controller and would be
        // written the same way by anything added later, so the model is where it is counted.
        ProductCompare::created(fn (ProductCompare $compare) => $this->safely(fn () => $this->analytics->compareAdded((int) $compare->product_id)));
        Review::created(fn (Review $review) => $this->safely(fn () => $this->reviewSubmitted($review)));
        User::created(fn (User $user) => $this->safely(fn () => $this->analytics->signedUp((int) $user->id)));

        // Sign-in happens in eight places — the login form, four social providers, OTP, a password
        // reset, and the API — and instrumenting eight controllers would have meant missing at
        // least one. The framework already fires one event for all of them.
        Event::listen(Login::class, fn (Login $login) => $this->safely(fn () => $this->signedIn($login)));
    }


    private function orderUpdated(Order $order): void
    {
        if ($order->wasChanged('order_status')) {
            $this->analytics->orderStatusChanged(
                orderId: (int) $order->id,
                status: (string) $order->order_status,
                amount: (float) $order->order_amount,
            );
        }

        // Payment landing later than the order — a gateway callback, an offline payment approved
        // by an administrator — is the same event, recorded when it actually happened.
        if ($order->wasChanged('payment_status') && $order->payment_status === 'paid') {
            $this->analytics->paymentAttempted(
                gateway: (string) ($order->payment_method ?: 'unknown'),
                outcome: 'succeeded',
                amount: (float) $order->order_amount,
                orderId: (int) $order->id,
            );
        }
    }



    private function signedIn(Login $login): void
    {
        // Only shoppers. An administrator or a vendor signing in is staff traffic, and counting it
        // as a customer sign-in would put the shop's own team in its conversion funnel.
        if ($login->guard !== 'customer' || !isset($login->user->id)) {
            return;
        }

        $this->analytics->signedIn(customerId: (int) $login->user->id, method: 'session');
    }

    private function reviewSubmitted(Review $review): void
    {
        if ($review->product_id === null) {
            return;
        }

        $this->analytics->reviewSubmitted(
            productId: (int) $review->product_id,
            rating: (int) $review->rating,
        );
    }

    private function safely(callable $record): void
    {
        try {
            $record();
        } catch (\Throwable) {
            // An order must never fail because analytics could not describe it.
        }
    }
}
