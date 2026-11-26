<?php

namespace App\Services\Notification;

use App\Models\NotificationMessage;
use App\Models\Translation;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Schema;

/**
 * The words one market sends a notification in (I1-03).
 *
 * `notification_messages` held one row per `(user_type, key)` for the whole installation, so every market told its customers the same
 * thing in the same words. That is not a translation question: the brand, the delivery promise and the legal phrasing of "your order is on
 * its way" differ per country, and a market cannot be given its own wording by adding a locale.
 *
 * **The order is the one the requirement states, and the LANGUAGE outranks the market at the second step:**
 *
 *  1. the market's own row, translated into the language the recipient reads -- a Kuwaiti customer who reads Arabic gets Kuwait's Arabic;
 *  2. the global row, translated into that language -- so a market that has written an override but not translated it into this
 *     recipient's language does not send them another language's words;
 *  3. the market's own untranslated message -- once no language matches at all, the market's own words beat the platform's. This step is a
 *     refinement of "then the global text" and is stated here rather than left to be inferred;
 *  4. the global untranslated message, which is what every send does today.
 *
 * **A market may switch a message OFF for itself, and that beats the global switch.** Where the market has a row of its own, its `status`
 * is the answer: a market that has turned one message off sends nothing, whatever the global row says, because the override is the whole of
 * that market's decision about that message. A market with no row of its own follows the global switch, as it always has.
 *
 * **The market is the one IN FORCE**, which for a message about an order is the market the order was sold in
 * ({@see \App\Services\Storefront\OrderNotificationContext}) and for every other the market of the request that raised it. The DEFAULT
 * market is read as the global row and never as an override: the global text is already the default market's text, so Saudi wording cannot
 * be moved by this mechanism even by writing an SA row.
 *
 * Off unless `gcc.notifications.market_templates` is on AND the migration has run. With either missing this answers null and its one caller
 * reads the row it has always read.
 */
#[Singleton]
final class MarketNotificationText
{
    public const COLUMN = 'storefront_country';

    private const TABLE = 'notification_messages';

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $columnAnswer = null;

    /** Whether a market may have wording of its own at all: the switch is on and the migration has run. */
    public function isEnabled(): bool
    {
        return (bool) config('gcc.notifications.market_templates', false) && $this->columnReady();
    }

    /**
     * The text this market sends, or null when nothing here applies and the caller should read the row it always has.
     *
     * `false` is a real answer and is not null: it means the message is switched OFF for this market, which is a decision and not an
     * absence.
     */
    public function of(string $key, string $userType, string $language, ?string $country = null): string|false|null
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $market = $this->marketInForce(country: $country);

        if ($market === null) {
            return null;
        }

        $override = $this->rowFor(key: $key, userType: $userType, market: $market);

        if ($override === null) {
            return null;
        }

        if ((int) $override->status === 0) {
            return false;
        }

        $global = $this->rowFor(key: $key, userType: $userType, market: null);

        return $this->translationOf(row: $override, language: $language)
            ?? ($global === null ? null : $this->translationOf(row: $global, language: $language))
            ?? $this->messageOf($override)
            ?? ($global === null ? null : $this->messageOf($global));
    }

    /**
     * The market a message's wording is read for, or null when there is no market to read for: storefronts off, nothing bound, or the
     * DEFAULT market, whose wording is the global row.
     */
    private function marketInForce(?string $country): ?string
    {
        $resolver = StorefrontResolver::fromConfig();

        if (!$resolver->isEnabled()) {
            return null;
        }

        $code = strtoupper(trim((string) ($country ?? StorefrontContext::current()->country)));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== strtoupper($resolver->defaultCountry()) ? $code : null;
    }

    /**
     * One row, by the LOWEST id so that an installation already holding two rows for one key answers the same on every engine and on
     * every request. There is no unique key on this table and the migration deliberately does not add one -- see its docblock.
     */
    private function rowFor(string $key, string $userType, ?string $market): ?NotificationMessage
    {
        return NotificationMessage::query()
            ->where('key', $key)
            ->where('user_type', $userType)
            ->when($market === null, fn ($query) => $query->whereNull(self::COLUMN), fn ($query) => $query->where(self::COLUMN, $market))
            ->orderBy('id')
            ->first();
    }

    private function translationOf(NotificationMessage $row, string $language): ?string
    {
        $value = Translation::query()
            ->where('translationable_type', NotificationMessage::class)
            ->where('translationable_id', $row->id)
            ->where('locale', $language)
            ->orderBy('id')
            ->value('value');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function messageOf(NotificationMessage $row): ?string
    {
        $message = $row->getAttribute('message');

        return is_string($message) && trim($message) !== '' ? $message : null;
    }

    private function columnReady(): bool
    {
        $answer = $this->columnAnswer;
        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasColumn(self::TABLE, self::COLUMN);
        $this->columnAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }

    /** Forgets what was learnt about the column, for a test that adds or drops it. */
    public function forgetTheSchema(): void
    {
        $this->columnAnswer = null;
    }
}
