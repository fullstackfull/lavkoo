<?php

namespace Tests\Feature\Storefront;

use App\Events\OrderStatusEvent;
use App\Models\Order;
use App\Services\Notification\MessageLanguage;
use App\Services\Storefront\MarketLanguage;

/**
 * A1-17: the language a market writes in to a recipient who has chosen none.
 *
 * A recipient's own `app_language` has always decided this and still does. What had no answer was the recipient who has chosen none -- a
 * customer created at a till, a rider who never opened the settings -- because the platform then writes in whatever language the sending
 * process is in, and for a queue worker that is the language of whichever job it ran last. "The platform's default" is the right answer for
 * one market and the wrong one for six.
 *
 * The map ships EMPTY, so the first test here is the one that matters most: with nothing configured, nothing changes.
 *
 * I1-16 adds the other half: a market may name the LANGUAGES IT PRESENTS, and a market never writes to a customer in a language its own
 * storefront is not translated into. A customer whose stored `app_language` is one the market does not present is written to in the market's
 * own language instead, because a message in a language the links in it do not lead to is worse than one in the market's language. A market
 * that names no list presents whatever the platform presents, which is every market today and the state the lists ship in.
 *
 * Every assertion reads the title of the push that would have left the server, in the language it was written in.
 */
class MarketLanguageTest extends OrderNotificationTestCase
{
    private const NOT_INSTALLED = 'fr';

    private const ARABIC = 'sa';

    private const ENGLISH = 'en';

    private function marketWrites(string $country, string $language): void
    {
        config(['gcc.storefronts.language.by_country' => [$country => $language]]);
    }

    /** A Kuwaiti order whose rider has chosen no language, notified through the real event and the queued listener. */
    private function notifyARiderWithNoLanguageOfAKuwaitiOrder(): void
    {
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));
    }

    private function titleSent(): string
    {
        return $this->theOnePushSent()['message']['notification']['title'];
    }

    public function test_the_map_ships_empty_so_a_recipient_with_no_language_is_written_to_as_today(): void
    {
        $this->assertSame([], config('gcc.storefronts.language.by_country'), 'no market names a language until somebody decides one');

        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->notifyARiderWithNoLanguageOfAKuwaitiOrder();

        $this->assertSame(self::TITLE_IN_ENGLISH, $this->titleSent(), 'the platform default, exactly as before');
    }

    public function test_a_market_that_names_a_language_writes_in_it_to_a_recipient_who_has_chosen_none(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->marketWrites(country: 'KW', language: self::ARABIC);

        $this->notifyARiderWithNoLanguageOfAKuwaitiOrder();

        $this->assertSame(self::TITLE_IN_ARABIC, $this->titleSent(), 'the market Kuwait sells in, not the platform default');
    }

    /** A person's own choice outranks their market's, which is the rule that was already there and must not move. */
    public function test_a_recipients_own_language_outranks_the_markets(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ARABIC);
        $this->marketWrites(country: 'KW', language: self::ARABIC);

        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: self::ENGLISH)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        $this->assertSame(self::TITLE_IN_ENGLISH, $this->titleSent(), 'the rider reads English and asked for it');
    }

    /**
     * A locale the shop has not installed is ignored rather than bound. translate() writes the keys it cannot find into
     * resources/lang/<locale>/new-messages.php, so binding one would have the platform writing language files nobody can read.
     */
    public function test_a_language_the_platform_has_not_installed_is_ignored(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->marketWrites(country: 'KW', language: self::NOT_INSTALLED);

        $this->notifyARiderWithNoLanguageOfAKuwaitiOrder();

        $this->assertNull(MarketLanguage::of('KW'), 'a locale the shop does not have is not a language it can write in');
        $this->assertSame(self::TITLE_IN_ENGLISH, $this->titleSent());
    }

    /**
     * An order that records no market -- sold before the records existed, or while the switches were off -- reads the DEFAULT market's
     * language, and not the language of the market the sending request happens to be serving. That is the same reasoning that binds its
     * money, and it is what keeps one order's notification from deciding the next one's.
     */
    public function test_an_order_that_records_no_market_reads_the_default_markets_language(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->serveRequestFrom('KW');
        $this->marketWrites(country: 'SA', language: self::ARABIC);

        $this->insertOrder(self::SA_ORDER, [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'legacy-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: Order::findOrFail(self::SA_ORDER)));

        $this->assertSame(self::TITLE_IN_ARABIC, $this->titleSent(), "the default market's language, which is what a legacy order belongs to");
    }

    /** The switch of the second half: the lists ship empty, so a market presents whatever the shop presents and nothing changes. */
    public function test_the_lists_ship_empty_so_every_market_presents_what_the_shop_presents(): void
    {
        $this->assertSame([], config('gcc.storefronts.language.lists_by_country'), 'no market names a language list until somebody decides one');

        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);

        $this->assertSame([], MarketLanguage::listOf('KW'), 'an unlisted market has no list of its own');
        $this->assertTrue(MarketLanguage::presents(country: 'KW', locale: self::ARABIC), 'and presents everything the shop does');
        $this->assertTrue(MarketLanguage::presents(country: 'KW', locale: self::ENGLISH));
        $this->assertFalse(MarketLanguage::presents(country: 'KW', locale: self::NOT_INSTALLED), 'but not a locale the shop has not installed');
    }

    public function test_a_markets_list_is_its_own_and_drops_a_locale_the_shop_has_not_installed(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->marketPresents(country: 'KW', languages: [self::ARABIC, self::NOT_INSTALLED, self::ARABIC]);

        $this->assertSame([self::ARABIC], MarketLanguage::listOf('KW'), 'the locale the shop has not installed, and the repeat, are dropped');
        $this->assertTrue(MarketLanguage::presents(country: 'KW', locale: self::ARABIC));
        $this->assertFalse(MarketLanguage::presents(country: 'KW', locale: self::ENGLISH), 'a language the market does not present');
    }

    /** A market that names a list but no default writes in the first of its list: one decision rather than two. */
    public function test_a_market_with_a_list_and_no_default_writes_in_the_first_of_its_list(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->marketPresents(country: 'KW', languages: [self::ARABIC, self::ENGLISH]);

        $this->assertSame(self::ARABIC, MarketLanguage::of('KW'));
        $this->assertSame('rtl', MarketLanguage::directionOf('KW'), "the direction the shop's own list declares for that locale");
    }

    /** The direction is the language list entry's and is never derived from the code, which is the rule the website already follows. */
    public function test_the_direction_is_the_one_the_shops_language_list_declares(): void
    {
        $this->setStoreLanguages(default: self::ENGLISH);

        $this->assertSame('rtl', MarketLanguage::directionOfLocale(self::ARABIC));
        $this->assertSame('ltr', MarketLanguage::directionOfLocale(self::ENGLISH));
        $this->assertSame('ltr', MarketLanguage::directionOfLocale(self::NOT_INSTALLED), 'a locale the list does not name reads left to right');
    }

    /**
     * The criterion itself, end to end through the real event and the queued listener: a rider who READS English is written to in Arabic
     * because the Kuwaiti storefront presents Arabic only.
     */
    public function test_a_recipient_whose_language_the_market_does_not_present_is_written_to_in_the_markets_own(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->marketPresents(country: 'KW', languages: [self::ARABIC]);

        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: self::ENGLISH)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        $this->assertSame(self::TITLE_IN_ARABIC, $this->titleSent(), 'the rider was written to in a language the Kuwaiti storefront does not present');
    }

    /** And a recipient whose language the market DOES present keeps it, which is the behaviour this must not break. */
    public function test_a_recipient_whose_language_the_market_presents_keeps_it(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->marketPresents(country: 'KW', languages: [self::ARABIC, self::ENGLISH]);

        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: self::ENGLISH)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        $this->assertSame(self::TITLE_IN_ENGLISH, $this->titleSent());
    }

    /**
     * The other sender, which is a different code path: a message that is about NO order (a registration, a password reset) reaches
     * `MessageLanguage::asTheRecipientReads()` with nothing but an address, and the market in force is the request's.
     */
    public function test_a_message_about_no_order_also_respects_the_markets_list(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->marketPresents(country: 'KW', languages: [self::ARABIC]);
        $this->serveRequestFrom('KW');
        $customer = $this->makeCustomer(['app_language' => self::ENGLISH, 'email' => 'reader@example.test']);

        $chosen = app(MessageLanguage::class)->asTheRecipientReads(
            userType: 'customer',
            sentTo: $customer->email,
            callback: static fn (): string => getDefaultLanguage(),
        );

        $this->assertSame(self::ARABIC, $chosen, 'a customer who reads English was written to in English by a Kuwaiti storefront that does not present it');
    }

    /** And the market in force decides it: the same customer, served from a market that presents English, keeps English. */
    public function test_a_message_about_no_order_keeps_a_language_the_market_presents(): void
    {
        $this->switchStorefrontsOn();
        $this->setStoreLanguages(default: self::ENGLISH);
        $this->marketPresents(country: 'KW', languages: [self::ARABIC, self::ENGLISH]);
        $this->serveRequestFrom('KW');
        $customer = $this->makeCustomer(['app_language' => self::ENGLISH, 'email' => 'reader@example.test']);

        $chosen = app(MessageLanguage::class)->asTheRecipientReads(
            userType: 'customer',
            sentTo: $customer->email,
            callback: static fn (): string => getDefaultLanguage(),
        );

        $this->assertSame(self::ENGLISH, $chosen);
    }

    /** @param list<string> $languages */
    private function marketPresents(string $country, array $languages): void
    {
        config(['gcc.storefronts.language.lists_by_country' => [$country => $languages]]);
    }

    public function test_a_market_nobody_named_and_no_market_at_all_answer_nothing(): void
    {
        $this->marketWrites(country: 'KW', language: self::ARABIC);

        $this->assertSame(self::ARABIC, MarketLanguage::of('kw'), 'the code is read whatever case it arrives in');
        $this->assertNull(MarketLanguage::of('AE'), 'a market nobody has decided for');
        $this->assertNull(MarketLanguage::of(''), 'no market at all');
        $this->assertNull(MarketLanguage::of(null));
    }
}
