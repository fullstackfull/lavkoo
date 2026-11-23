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
        /**
         * Null while the consent gate is off, and then the template adds NOTHING — not even whitespace.
         *
         * Its @if and @endif sit at column 0 in abandoned-cart-reminder.blade.php for that reason: Blade leaves a
         * directive line's own indentation in the rendered output, so an indented conditional put 8 bytes into the
         * footer of a message that is supposed to be unchanged. Measured and pinned by
         * MarketingConsentDormancyTest::test_with_the_switch_off_the_rendered_reminder_is_byte_for_byte_the_footer_main_renders.
         */
        public ?string $unsubscribeUrl = null,
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
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ]);
    }
}
