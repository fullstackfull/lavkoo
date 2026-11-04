<?php

namespace App\Mail;

use App\Models\AbandonedCartReminder;
use App\Models\Cart;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The "you left something behind" message (Phase 2.4).
 *
 * The cart lines are read at build time rather than passed in, because the gap between selecting a
 * cart and rendering its email is where a product can go out of stock. Anything no longer active is
 * dropped from the listing, so the message never advertises something the customer cannot buy.
 */
class AbandonedCartReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AbandonedCartReminder $reminder,
        public string $recoveryUrl,
    ) {
    }

    public function build(): self
    {
        $lines = Cart::with('product')
            ->whereHas('product', fn ($query) => $query->active())
            ->where('cart_group_id', $this->reminder->cart_group_id)
            ->get();

        return $this->subject(translate('you_left_something_in_your_cart'))
            ->view('email-templates.abandoned-cart-reminder', [
                'reminder' => $this->reminder,
                'lines' => $lines,
                'recoveryUrl' => $this->recoveryUrl,
            ]);
    }
}
