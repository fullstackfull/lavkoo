<?php

namespace App\Services\Notification;

use App\Mail\TestEmailSender;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A test e-mail through one market's account, whose REAL result comes back (I1-01).
 *
 * This is the one send on the platform that is allowed to fail loudly, and it has to be: an operator who has just typed a Kuwaiti SMTP host
 * and password is asking whether THOSE credentials work, and the only useful answer is the one the transport gave. Every other send swallows
 * it -- `EmailTemplateTrait::writeAndSendMail` catches `Exception` and does nothing with it, which is defensible for an order confirmation
 * (a customer's order must not fail because a mail server is down) and useless here, because "nothing happened" is exactly what a wrong
 * password looks like.
 *
 * So the transport's own message is caught, named and handed back rather than logged and dropped. It is caught rather than allowed to escape
 * because an unhandled `TransportException` on an admin screen is a 500 page, and a mistyped password is not a server fault.
 *
 * **What the operator is shown back is the account the message LEFT THROUGH, read from inside the send**, not the account the screen thinks
 * is stored: {@see MailAccount::forThisMessage()} applies the market's account around the one send and takes it out again, so reading the
 * configuration afterwards reads the platform's account and would tell the operator their test proved something it did not. And the answer
 * says WHOSE account it was, because a market with no account of its own leaves through the platform's host -- "it worked" is misleading
 * when what worked was another company's account.
 */
final class MailAccountTestSend
{
    public function __construct(
        private readonly MailAccount $account,
        private readonly NotificationProfiles $profiles,
    ) {
    }

    /**
     * Sends one throwaway message to the address given, through the account of the market named.
     *
     * @return array{sent: bool, host: ?string, from: ?string, market: string, own_account: bool, error: ?string}
     */
    public function to(string $address, string $country): array
    {
        $market = strtoupper(trim($country));
        $profile = $this->profiles->for(channel: NotificationProfiles::MAIL, country: $market);
        $answer = [
            'market' => $market,
            'own_account' => $profile !== null && !$profile->isShared(),
            'host' => null,
            'from' => null,
        ];

        try {
            $this->account->forThisMessage(
                country: $market,
                callback: function () use (&$answer, $address): void {
                    $answer['host'] = $this->asText(config('mail.host'));
                    $answer['from'] = $this->asText(config('mail.from.address'));
                    Mail::to($address)->send(new TestEmailSender());
                },
            );
        } catch (Throwable $failure) {
            return [...$answer, 'sent' => false, 'error' => $this->reasonOf($failure)];
        }

        return [...$answer, 'sent' => true, 'error' => null];
    }

    /**
     * What went wrong, in the transport's own words, with the class of the failure named when it gave no words -- so that a message of
     * "Connection could not be established" can be told from one of "Expected response code 250".
     *
     * The message is the provider's and is shown as it is: paraphrasing it is how an operator ends up debugging the paraphrase. The view
     * escapes it, as it escapes every other refusal on these screens.
     */
    private function reasonOf(Throwable $failure): string
    {
        $reason = trim($failure->getMessage());

        return $reason === '' ? class_basename($failure) : $reason;
    }

    private function asText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
