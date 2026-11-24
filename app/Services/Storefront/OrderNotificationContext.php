<?php

namespace App\Services\Storefront;

use Closure;
use Illuminate\Support\Facades\App;

/**
 * The storefront a notification about an order is built from (GCC-13, I1-06): the one the order itself recorded when it was sold, never the
 * one the process that happens to send the notification is serving. A queue worker sends one order's notifications after another in one
 * process, so a storefront left bound would narrow the next order's, and a storefront taken from the request that queued the job would
 * narrow all of them. What was bound before is put back afterwards, even when the notification fails.
 *
 * An order with no record — one sold before the records existed, or while the switches were off — is notified as the default storefront,
 * which reads the platform's own currency and language settings: exactly what every order is notified as today while the switches are off.
 * That is bound rather than left alone so that a legacy order, whose amounts are in the platform's money, is not notified in the money of
 * whichever market the sending request happens to be serving. While storefronts are off the order's record is not even read, and nothing is
 * bound at all.
 */
final class OrderNotificationContext
{
    /**
     * Runs the callback in the storefront the order was sold in, in that order's money, and in the language the recipient of this
     * notification reads.
     *
     * The money is put in force by name as well as by context, because StorefrontCurrency reads its own market first and only falls back to
     * the storefront a context binds: a sender called from inside anything that has set a market — the money.market middleware, a panel
     * screen scoped to one country — would otherwise price an order of one market in another's. The country is the one storefrontOf has
     * already resolved, not a second reading of the record (StorefrontCurrency::withinOrder re-reads it), so one place decides it.
     *
     * @param string $userType the recipient, as the senders name it: customer, seller, vendor, delivery_man, promoter or admin
     */
    public static function within(object|array $order, string $userType, Closure $callback): mixed
    {
        $context = self::storefrontOf(order: $order);

        if ($context === null) {
            return $callback();
        }

        return $context->serve(callback: fn () => app(StorefrontCurrency::class)->within(
            country: $context->country,
            callback: fn () => self::inLanguage(
                language: self::languageOf(order: $order, userType: $userType, country: $context->country),
                callback: fn () => self::asTheCompanyThatSold(order: $order, callback: $callback),
            ),
        ));
    }

    /** The storefront the order records, the default storefront when it records none, or null while storefronts are off. */
    private static function storefrontOf(object|array $order): ?StorefrontContext
    {
        if (!config('gcc.storefronts.enabled')) {
            return null;
        }

        $country = data_get($order, 'storefront_country');
        $resolver = StorefrontResolver::fromConfig();

        return $resolver->contextOfRecordedCountry(countryCode: is_string($country) ? $country : null) ?? $resolver->context();
    }

    /**
     * The language the recipient reads, or null when that recipient has chosen none, which leaves the language exactly as the sender would
     * have taken it. The recipient's own choice is used because a storefront has no language of its own: per-country notification texts are
     * requirement I1-03, and the body of a push is already written in the recipient's language, so its title and the e-mail about the same
     * order now agree with it instead of answering in whatever language the sending process settled on.
     *
     * Two recipients are deliberately not listed, and both answer the platform's own language, as they always have. A promoter is reached
     * behind the customer, through the referral record, and the customer's language is not the promoter's. An admin is platform staff, who
     * reads the platform's panel, not a market's.
     */
    /**
     * The language this notification is written in: the recipient's own choice, and failing that the language the order's market writes in
     * (A1-17, {@see MarketLanguage}). The market's answer is null for every market until somebody has decided what it writes in, so a
     * recipient who has chosen no language is written to exactly as before.
     *
     * The country is the one storefrontOf has already resolved, so a legacy order that records no market reads the default market's
     * language and not the language of whichever order the worker handled before it -- the same reasoning that binds its money.
     */
    private static function languageOf(object|array $order, string $userType, string $country): ?string
    {
        $language = match ($userType) {
            'customer' => data_get($order, 'customer.app_language'),
            'seller', 'vendor' => data_get($order, 'seller.app_language'),
            'delivery_man' => data_get($order, 'deliveryMan.app_language'),
            default => null,
        };

        return is_string($language) && trim($language) !== '' ? $language : MarketLanguage::of($country);
    }

    /**
     * Sends the message as the company that sold the order, when the order records one (A1-17).
     *
     * The sender's NAME is the one thing a market can own here: `mail.from.name` is one setting for the platform, read once at boot out of
     * `mail_config`, so a Kuwaiti customer's message said it came from the Saudi company. The frozen company on the order answers it the
     * same way it answers the invoice ({@see OrderInvoiceIssuer::tradingNameOf}), which means it answers nothing -- and changes nothing --
     * for every order that invoice prints the shop's settings for: legal entities off, an order placed before the registry, a record that
     * cannot be read.
     *
     * Where the message is sent FROM does not move: the record holds no address, and inventing one would send mail from an address the
     * domain does not accept.
     *
     * It is put back afterwards, even when the sending fails, for the same reason the language is: a worker sends one order's messages
     * after another in one process, and the company of one order may not sign the next one's.
     */
    private static function asTheCompanyThatSold(object|array $order, Closure $callback): mixed
    {
        $company = OrderInvoiceIssuer::tradingNameOf($order);

        if ($company === null) {
            return $callback();
        }

        $previous = config('mail.from.name');
        config(['mail.from.name' => $company]);

        try {
            return $callback();
        } finally {
            config(['mail.from.name' => $previous]);
        }
    }

    /**
     * Binds both languages a sender may ask the request for — the application locale an API request answers with, and the session language a
     * web request answers with — and puts both back. getDefaultLanguage() reads the session, and a queue worker's session outlives the job
     * that filled it, so without this a queued notification is written in whatever language the worker last settled on.
     *
     * Both are put back even for a recipient who has chosen no language and so has nothing bound for them, because the senders themselves
     * move the language: getDefaultLanguage() writes the session language when it is empty, and translate() rewrites the locale on every
     * call (on an /api request it reads the locale back, so the locale walks sa -> ar -> es over successive sends). A notification about one
     * order may not decide the language of the next one, whoever it was addressed to.
     */
    private static function inLanguage(?string $language, Closure $callback): mixed
    {
        $previousLocale = App::getLocale();
        $previousRequestLanguage = session('local');

        if ($language !== null) {
            App::setLocale($language);
            session()->put('local', $language);
        }

        try {
            return $callback();
        } finally {
            App::setLocale($previousLocale);
            $previousRequestLanguage === null ? session()->forget('local') : session()->put('local', $previousRequestLanguage);
        }
    }
}
