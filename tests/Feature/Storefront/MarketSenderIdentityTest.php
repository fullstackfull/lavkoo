<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\OrderLegalEntitySnapshot;
use Tests\Support\BuildsLegalEntityWorld;

/**
 * A1-17: a message about an order says it comes from the company that sold it.
 *
 * `mail.from.name` is one setting for the whole platform, read once at boot out of `mail_config`, so a Kuwaiti customer's order mail said
 * it came from the Saudi company -- the same defect the invoice had before GCC-13 froze the selling company on the order, and the same
 * record answers it. The sender's NAME is the one thing a market can own here: the frozen record holds no address of its own, so where a
 * message is sent FROM stays one setting until a market has one, because mail sent from an address the domain does not accept is worse
 * than mail with the platform's name on it.
 *
 * Everything here is read off the real message the mailer was handed, and the orders are stamped by the real snapshot writer.
 */
class MarketSenderIdentityTest extends OrderNotificationTestCase
{
    use BuildsLegalEntityWorld;

    private const PLATFORM_SENDER = 'Lavkoo';

    private const KUWAIT_COMPANY = 'Lavkoo KW';

    protected function setUp(): void
    {
        parent::setUp();

        $this->runLegalEntityMigrations();
        $this->captureTheEmailsTheMailerRenders();
        config(['mail.from.name' => self::PLATFORM_SENDER]);
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: self::TITLE_IN_ENGLISH, titleInArabic: self::TITLE_IN_ARABIC, design: 'order-place');
    }

    /** A Kuwaiti order whose selling company is registered and frozen on it by the real writer. */
    private function kuwaitiOrderSoldByItsOwnCompany(): \App\Models\Order
    {
        $this->turnLegalEntitiesOn();
        $this->registerEntity(country: 'KW');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);
        app(OrderLegalEntitySnapshot::class)->record(orderId: $order->id, country: 'KW');

        return $order->refresh();
    }

    public function test_a_message_about_an_order_comes_from_the_company_that_sold_it(): void
    {
        $order = $this->kuwaitiOrderSoldByItsOwnCompany();

        $this->placeOrderMailFor($order);

        $this->assertSame(self::KUWAIT_COMPANY, $this->senderOfTheEmailSent()['name']);
    }

    /** An order that records no company is sent as the platform, exactly as every order is sent today. */
    public function test_an_order_with_no_company_is_sent_as_the_platform(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->placeOrderMailFor($order);

        $this->assertSame(self::PLATFORM_SENDER, $this->senderOfTheEmailSent()['name']);
    }

    /** Switching legal entities off is the way back, and it takes the sender back with it even for an order that was stamped. */
    public function test_switching_legal_entities_off_sends_as_the_platform_again(): void
    {
        $order = $this->kuwaitiOrderSoldByItsOwnCompany();
        config(['gcc.storefronts.legal_entities.enabled' => false]);
        app(\App\Services\Storefront\LegalEntityRegistry::class)->flush();

        $this->placeOrderMailFor($order);

        $this->assertSame(self::PLATFORM_SENDER, $this->senderOfTheEmailSent()['name']);
    }

    /** And the setting is put back, so the company of one order can never sign the next order's message. */
    public function test_the_platform_sender_is_put_back_after_the_message_is_sent(): void
    {
        $order = $this->kuwaitiOrderSoldByItsOwnCompany();

        $this->placeOrderMailFor($order);

        $this->assertSame(self::PLATFORM_SENDER, config('mail.from.name'), 'the next message is not signed by this order\'s company');
    }

    /** Where the message is sent from does not move: a company has a name here, not a mailbox. */
    public function test_the_address_a_message_is_sent_from_is_the_platforms(): void
    {
        $order = $this->kuwaitiOrderSoldByItsOwnCompany();
        $addressBefore = config('mail.from.address');

        $this->placeOrderMailFor($order);

        $this->assertSame($addressBefore, $this->senderOfTheEmailSent()['address']);
    }
}
