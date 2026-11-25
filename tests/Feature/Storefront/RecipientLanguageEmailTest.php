<?php

namespace Tests\Feature\Storefront;

use App\Events\AddFundToWalletEvent;
use App\Events\CustomerRegistrationEvent;
use App\Models\User;
use App\Services\Notification\MessageLanguage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;

/**
 * I1-03: a queued e-mail is written in the RECIPIENT's language, not in the language of the worker that happens to send it.
 *
 * Half of this requirement was already met and the card had not caught up: an e-mail ABOUT AN ORDER has been written in its recipient's
 * language since I1-06, because `OrderNotificationContext` reads the order's own customer, seller or delivery man and binds that language
 * around the send (OrderNotificationStorefrontTest::test_a_queued_order_email_is_written_in_the_language_of_its_recipient, and the vendor
 * and digital-download e-mails beside it).
 *
 * The other half was not. A registration, a verification, a password reset, a wallet top-up or a restock notice reaches `sendingMail()` with
 * nothing but an address, so it was written in `getDefaultLanguage()` -- which in a queue worker is the install default, or whatever the job
 * before it left in the session. The recipient's own choice sits in the row that holds their address, and that is where it is now read from:
 * never from `request()`, because the request that raised the event may belong to an administrator or to a payment gateway, and in a worker
 * there is no request worth reading.
 *
 * The order half still decides for itself where it already does. An order knows its own recipients and its market's language, which an
 * address lookup does not, so `MessageLanguage` records that a language has been chosen and the sender leaves it alone.
 */
class RecipientLanguageEmailTest extends OrderNotificationTestCase
{
    private const TITLE_OF_THE_TOP_UP_IN_ENGLISH = 'Funds added to your wallet';

    private const TITLE_OF_THE_TOP_UP_IN_ARABIC = 'تم إضافة رصيد إلى محفظتك';

    public function test_a_queued_email_about_no_order_is_written_in_the_language_of_its_recipient(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedTheTopUpTemplate();
        $customer = $this->customerWhoReads('sa');

        $this->sendTheTopUpMailTo($customer);

        $this->assertSame(self::TITLE_OF_THE_TOP_UP_IN_ARABIC, $this->titleOfTheEmailSent());
    }

    public function test_a_recipient_who_has_chosen_no_language_is_written_to_in_the_store_language(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedTheTopUpTemplate();

        $this->sendTheTopUpMailTo($this->customerWhoReads(null));

        $this->assertSame(self::TITLE_OF_THE_TOP_UP_IN_ENGLISH, $this->titleOfTheEmailSent());
    }

    /** The first test of the switch: with storefronts off the platform is one market with one language and every e-mail is written as it is today. */
    public function test_while_storefronts_are_off_a_queued_email_is_written_in_the_store_language_as_it_always_was(): void
    {
        Mail::fake();
        $this->seedTheTopUpTemplate();

        $this->sendTheTopUpMailTo($this->customerWhoReads('sa'));

        $this->assertSame(self::TITLE_OF_THE_TOP_UP_IN_ENGLISH, $this->titleOfTheEmailSent());
    }

    /** An address that belongs to nobody: the e-mail is written exactly as the sender would have written it, and nothing fails. */
    public function test_an_address_that_belongs_to_no_recipient_is_written_to_in_the_store_language(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedTheTopUpTemplate();

        $this->sendTheTopUpMail(email: 'nobody@example.test');

        $this->assertSame(self::TITLE_OF_THE_TOP_UP_IN_ENGLISH, $this->titleOfTheEmailSent());
    }

    /**
     * The order half keeps deciding. The order is sold to a customer who reads Arabic, and the e-mail goes to an address no row holds -- a
     * guest checkout -- so an address lookup would find nothing and leave the message in the store's language. It is Arabic, which can only
     * have come from the order.
     */
    public function test_an_email_about_an_order_takes_its_language_from_the_order_and_not_from_the_address(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Your order is placed', titleInArabic: 'تم إنشاء طلبك');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->placeOrderMailFor($order);

        $this->assertSame('تم إنشاء طلبك', $this->titleOfTheEmailSent());
    }

    /** Platform staff read the platform's own panel, not a market's, so a message to them stays in the platform's language. */
    public function test_an_email_to_platform_staff_stays_in_the_platform_language(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedEmailTemplate(templateName: 'registration', userType: 'admin', titleInEnglish: 'A seller registered', titleInArabic: 'سجل بائع جديد');
        $staff = $this->customerWhoReads('sa');

        event(new CustomerRegistrationEvent(email: $staff->email, data: [
            'userType' => 'admin', 'templateName' => 'registration', 'subject' => 'registration', 'title' => 'registration',
        ]));

        $this->assertSame('A seller registered', $this->titleOfTheEmailSent());
    }

    /** One message may not decide the language of the next: the worker's own language is put back, failure included. */
    public function test_the_language_of_one_message_does_not_reach_the_message_after_it(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedTheTopUpTemplate();
        App::setLocale('en');

        $this->sendTheTopUpMailTo($this->customerWhoReads('sa'));

        $this->assertSame('en', App::getLocale(), 'the locale the worker was in is put back');
        $this->assertNull(session('local'), 'and so is the session language');
    }

    public function test_the_language_is_put_back_when_the_send_fails(): void
    {
        $this->switchStorefrontsOn();
        App::setLocale('en');

        try {
            app(MessageLanguage::class)->write(language: 'sa', callback: static fn () => throw new \RuntimeException('the send failed'));
            $this->fail('the failure was swallowed');
        } catch (\RuntimeException) {
            $this->assertSame('en', App::getLocale());
            $this->assertNull(session('local'));
        }
    }

    /** A message that has had no language chosen for it is still open to one; one that has is not. */
    public function test_a_language_already_chosen_for_a_message_is_not_chosen_again(): void
    {
        $this->switchStorefrontsOn();
        $language = app(MessageLanguage::class);

        $outer = $language->write(language: 'sa', callback: static fn (): string => App::getLocale());
        $inner = $language->write(
            language: 'sa',
            callback: fn (): string => $language->asTheRecipientReads(userType: 'customer', sentTo: 'anyone@example.test', callback: static fn (): string => App::getLocale()),
        );

        $this->assertSame('sa', $outer);
        $this->assertSame('sa', $inner, 'the inner chooser left the language the order path had chosen alone');
    }

    private function customerWhoReads(?string $language): User
    {
        return $this->makeCustomer(['app_language' => $language, 'f_name' => 'Noura', 'l_name' => 'Al Sabah']);
    }

    private function seedTheTopUpTemplate(): void
    {
        $this->seedEmailTemplate(
            templateName: 'add-fund-to-wallet',
            userType: 'customer',
            titleInEnglish: self::TITLE_OF_THE_TOP_UP_IN_ENGLISH,
            titleInArabic: self::TITLE_OF_THE_TOP_UP_IN_ARABIC,
        );
    }

    /** The wallet top-up e-mail as the payment settlement raises it, through the queued listener: an e-mail about no order at all. */
    private function sendTheTopUpMailTo(User $customer): void
    {
        $this->sendTheTopUpMail(email: $customer->email);
    }

    private function sendTheTopUpMail(string $email): void
    {
        event(new AddFundToWalletEvent(email: $email, data: [
            'userName' => 'Noura', 'userType' => 'customer', 'templateName' => 'add-fund-to-wallet',
            'subject' => 'add fund to wallet', 'title' => 'add fund to wallet',
        ]));
    }
}
