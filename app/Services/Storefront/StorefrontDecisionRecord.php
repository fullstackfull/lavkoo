<?php

namespace App\Services\Storefront;

use App\Models\BusinessSetting;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * How the decision of a person with the authority about one storefront is kept (the approval of its tax, the independent review of its legal texts): one business setting
 * for each storefront, written and withdrawn in the same transaction as its audit line, so that a decision whose trace cannot be written is not recorded. What the decision
 * says and when it counts is the business of the service that owns it; this only keeps it.
 */
final class StorefrontDecisionRecord
{
    /** A decision names its author and its source in at most this many characters. */
    public const MAXIMUM_TEXT_LENGTH = 191;

    /** A tool, a pipeline or an assistant is not a person with the authority to decide. Best effort on free text: the owner is who runs the command. */
    private const AUTOMATED_IDENTITY = '/\b(claude|anthropic|chatgpt|openai|bot|ci|automation|automated|github[\s_-]*actions?)\b/i';

    public static function namesATool(string $text): bool
    {
        return preg_match(self::AUTOMATED_IDENTITY, $text) === 1;
    }

    /** What the setting holds: null when it is not there, the decoded array, or whatever else was stored there. */
    public function of(string $setting): string|object|array|null
    {
        return getWebConfig(name: $setting);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $context
     */
    public function write(string $setting, array $record, string $action, string $country, array $context = []): void
    {
        DB::transaction(function () use ($setting, $record, $action, $country, $context): void {
            $before = json_decode((string) BusinessSetting::query()->where('type', $setting)->lockForUpdate()->value('value'), true);

            BusinessSetting::query()->updateOrInsert(['type' => $setting], ['value' => json_encode($record), 'updated_at' => now()]);

            app(AuditLogger::class)->recordOrFail(
                action: $action,
                subject: ['type' => 'storefront', 'id' => $country],
                before: is_array($before) ? $before : null,
                after: $record,
                context: $context,
            );
        });

        clearWebConfigCacheKeys();
    }

    /**
     * Withdraws the decision. False when there was none.
     *
     * @param array<string, mixed> $context
     */
    public function withdraw(string $setting, string $action, string $country, array $context = []): bool
    {
        $withdrawn = DB::transaction(function () use ($setting, $action, $country, $context): bool {
            $before = json_decode((string) BusinessSetting::query()->where('type', $setting)->lockForUpdate()->value('value'), true);

            if (BusinessSetting::query()->where('type', $setting)->delete() === 0) {
                return false;
            }

            app(AuditLogger::class)->recordOrFail(
                action: $action,
                subject: ['type' => 'storefront', 'id' => $country],
                before: is_array($before) ? $before : null,
                context: $context,
            );

            return true;
        });

        clearWebConfigCacheKeys();

        return $withdrawn;
    }
}
