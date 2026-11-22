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
 * which reads the platform's own currency and language settings: exactly what every order is notified as today. That is bound rather than
 * left alone so that a legacy order, whose amounts are in the platform's money, is not notified in the money of whichever market the
 * sending request happens to be serving. While storefronts are off the order's record is not even read, and nothing is bound at all.
 */
final class OrderNotificationContext
{
    /**
     * Runs the callback in the storefront the order was sold in, and in the language the recipient of this notification reads.
     *
     * @param string $userType the recipient, as the senders name it: customer, seller, vendor, delivery_man, promoter or admin
     */
    public static function within(object|array $order, string $userType, Closure $callback): mixed
    {
        $context = self::storefrontOf(order: $order);

        if ($context === null) {
            return $callback();
        }

        return $context->serve(callback: fn () => self::inLanguage(
            language: self::languageOf(order: $order, userType: $userType),
            callback: $callback,
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

        return $resolver->contextOfRecordedCountry(is_string($country) ? $country : null) ?? $resolver->context();
    }

    /**
     * The language the recipient reads, or null when that recipient has chosen none, which leaves the language exactly as the sender would
     * have taken it. The recipient's own choice is used because a storefront has no language of its own: per-country notification texts are
     * requirement I1-03, and the body of a push is already written in the recipient's language, so its title and the e-mail about the same
     * order now agree with it instead of answering in whatever language the sending process settled on.
     *
     * A promoter is not listed: the sender finds the promoter behind the customer itself, through the referral record, and the customer's
     * language is not the promoter's.
     */
    private static function languageOf(object|array $order, string $userType): ?string
    {
        $language = match ($userType) {
            'customer' => data_get($order, 'customer.app_language'),
            'seller', 'vendor' => data_get($order, 'seller.app_language'),
            'delivery_man' => data_get($order, 'deliveryMan.app_language'),
            default => null,
        };

        return is_string($language) && trim($language) !== '' ? $language : null;
    }

    /**
     * Binds both languages a sender may ask the request for — the application locale an API request answers with, and the session language a
     * web request answers with — and puts both back. getDefaultLanguage() reads the session, and a queue worker's session outlives the job
     * that filled it, so without this a queued notification is written in whatever language the worker last settled on.
     */
    private static function inLanguage(?string $language, Closure $callback): mixed
    {
        if ($language === null) {
            return $callback();
        }

        $previousLocale = App::getLocale();
        $previousRequestLanguage = session('local');

        App::setLocale($language);
        session()->put('local', $language);

        try {
            return $callback();
        } finally {
            App::setLocale($previousLocale);
            $previousRequestLanguage === null ? session()->forget('local') : session()->put('local', $previousRequestLanguage);
        }
    }
}
