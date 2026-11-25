<?php

namespace App\Services\Notification;

use App\Services\Storefront\MarketLanguage;
use App\Services\Storefront\StorefrontContext;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The language one message is written in (I1-03).
 *
 * A queued listener runs in a worker, not in the request that raised it: the worker is a console process whose locale is the install
 * default and whose session is whatever the job before it left behind. So a message was written in the language of the MACHINE rather than
 * of the person reading it -- a Kuwaiti customer whose app is in Arabic was e-mailed in English, and the e-mail template's translation rows,
 * which `EmailTemplate::translationCurrentLanguage` filters by `getDefaultLanguage()`, were never read at all.
 *
 * Two kinds of message ask this class the same question. A message ABOUT AN ORDER is already answered by
 * {@see \App\Services\Storefront\OrderNotificationContext}, which knows the order's own recipients and the market's language and binds the
 * answer through {@see write()}. Every OTHER message -- a registration, a verification, a password reset, a wallet top-up, a restock notice
 * -- reaches a sender with nothing but the recipient's address, so {@see asTheRecipientReads()} looks the recipient's own choice up in the
 * row that holds it. {@see inForce()} is what keeps the two from fighting: the order path binds first and the sender then leaves its answer
 * alone, so one message has one language decided in one place.
 *
 * Read from the STORED ROW, never from `request()`. The request that raised the event may belong to somebody else entirely -- an
 * administrator topping up a customer's wallet, a payment gateway calling back -- and in a worker there is no request worth reading.
 *
 * While storefronts are switched off this class binds nothing: the platform is one market with one language, and every message is written
 * exactly as it is written today. That is the same gate the order half already runs behind.
 */
#[Singleton]
final class MessageLanguage
{
    /** Where each kind of recipient keeps the language they chose: the table, and the column that holds their address. */
    private const WHERE_A_RECIPIENT_KEEPS_THEIR_LANGUAGE = [
        'customer' => ['table' => 'users', 'address' => 'email'],
        'vendor' => ['table' => 'sellers', 'address' => 'email'],
        'seller' => ['table' => 'sellers', 'address' => 'email'],
        'delivery-man' => ['table' => 'delivery_men', 'address' => 'email'],
        'delivery_man' => ['table' => 'delivery_men', 'address' => 'email'],
    ];

    private const LANGUAGE_COLUMN = 'app_language';

    /** Whether a language has been chosen for the message being sent right now, so a second chooser leaves it alone. */
    private bool $chosen = false;

    /** @var array<string, bool> whether a table can be asked for a recipient's language at all, remembered for this process */
    private array $tableCanAnswer = [];

    /**
     * Runs the callback with this language in force, and puts back what was there before -- after a failure as well.
     *
     * Both languages a sender may ask the request for are bound, because they are read in different places: `App::getLocale()` is what an
     * API request answers with and what `translate()` rewrites, and `session('local')` is what `getDefaultLanguage()` reads for everything
     * else. A worker's session outlives the job that filled it, so without binding both, a message would be written in whatever language the
     * message before it settled on.
     *
     * A null language binds nothing and still marks the message as decided: a recipient who has chosen no language is written to exactly as
     * the sender would have written to them, and nothing downstream may then choose for them.
     */
    public function write(?string $language, Closure $callback): mixed
    {
        $previousLocale = App::getLocale();
        $previousRequestLanguage = session('local');
        $previouslyChosen = $this->chosen;
        $this->chosen = true;

        if ($language !== null) {
            App::setLocale($language);
            session()->put('local', $language);
        }

        try {
            return $callback();
        } finally {
            $this->chosen = $previouslyChosen;
            App::setLocale($previousLocale);
            $previousRequestLanguage === null ? session()->forget('local') : session()->put('local', $previousRequestLanguage);
        }
    }

    /**
     * Runs the callback in the language the recipient of this message reads, unless something has already chosen one for this message.
     *
     * @param string $userType the recipient, as the senders name it: customer, vendor, seller, delivery-man, delivery_man or admin
     * @param mixed $sentTo the address the message is going to, as the sender was given it
     */
    public function asTheRecipientReads(string $userType, mixed $sentTo, Closure $callback): mixed
    {
        if ($this->chosen || !config('gcc.storefronts.enabled')) {
            return $callback();
        }

        return $this->write(language: $this->of(userType: $userType, sentTo: $sentTo), callback: $callback);
    }

    /**
     * The language the recipient of a message chose, or null when they have chosen none, when the platform cannot be asked, or when the
     * recipient is platform staff.
     *
     * An administrator is deliberately not listed: they read the platform's own panel, not a market's, so their messages stay in the
     * platform's language -- the same rule the order notifications follow.
     *
     * **A chosen language the recipient's MARKET does not present is not used** (I1-16). A market may list the languages it offers, and
     * writing to a customer in a language that market's own storefront is not translated into is worse than writing in the market's own:
     * the message arrives in a language the links in it do not lead to. So such a recipient is written to in the market's language
     * ({@see MarketLanguage::of()}), and a market that lists nothing presents whatever the platform presents, which is every market today.
     *
     * The market is the one IN FORCE, which is the order's own for a message about an order and the dispatching request's for every other,
     * so nothing had to be threaded through a sender to get it.
     */
    public function of(string $userType, mixed $sentTo): ?string
    {
        $where = self::WHERE_A_RECIPIENT_KEEPS_THEIR_LANGUAGE[$userType] ?? null;
        $address = $this->oneAddress($sentTo);

        if ($where === null || $address === null || !$this->canAnswer($where['table'], $where['address'])) {
            return null;
        }

        $language = DB::table($where['table'])->where($where['address'], $address)->value(self::LANGUAGE_COLUMN);
        $language = is_string($language) && trim($language) !== '' ? trim($language) : null;
        $market = StorefrontContext::current()->country;

        if ($language !== null && !MarketLanguage::presents(country: $market, locale: $language)) {
            return MarketLanguage::of($market);
        }

        return $language ?? MarketLanguage::of($market);
    }

    /**
     * The one address a message is going to, or null when the sender named none or named several: a message to a list has no single
     * recipient whose language could be read, so it stays in the sender's own, as it is today.
     */
    private function oneAddress(mixed $sentTo): ?string
    {
        if (is_array($sentTo)) {
            $sentTo = count($sentTo) === 1 ? reset($sentTo) : null;
        }

        return is_string($sentTo) && trim($sentTo) !== '' ? trim($sentTo) : null;
    }

    /**
     * Whether this table can be asked at all. The language column is added by no migration of this repository, so an installation that
     * predates the apps does not have it, and a notification must not become an error because of that. Remembered per process rather than
     * statically, so a test that builds its own tables is answered about its own tables.
     */
    private function canAnswer(string $table, string $addressColumn): bool
    {
        return $this->tableCanAnswer[$table] ??= Schema::hasTable($table)
            && Schema::hasColumn($table, self::LANGUAGE_COLUMN)
            && Schema::hasColumn($table, $addressColumn);
    }
}
