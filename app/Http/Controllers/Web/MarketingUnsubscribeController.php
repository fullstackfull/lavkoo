<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentRecorder;
use App\Services\Marketing\MarketingUnsubscribeLink;
use Illuminate\Contracts\View\View;

/**
 * The unsubscribe link inside a marketing e-mail.
 *
 * NO LOGIN. That is the whole point of the link: the person reading the message is in their mail client, not signed
 * in to the store, and often on a different device. The route carries Laravel's `signed` middleware instead, which is
 * what makes the token safe to act on.
 *
 * The answer is the same page whatever the token said — recorded, already recorded, or not a token this store wrote —
 * so the page cannot be used to find out whether an address is on file.
 */
class MarketingUnsubscribeController extends Controller
{
    public function __construct(private readonly MarketingConsentRecorder $recorder)
    {
    }

    public function unsubscribe(string $token): View
    {
        $link = MarketingUnsubscribeLink::read(token: $token);

        if ($link !== null) {
            $this->recorder->withdraw(
                subjectHash: $link['subject_hash'],
                market: $link['market'],
                channel: $link['channel'],
                purpose: $link['purpose'],
                source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
                customerId: $link['customer_id'],
            );
        }

        return view('web-views.marketing.unsubscribed');
    }
}
