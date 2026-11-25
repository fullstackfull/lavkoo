<?php

namespace App\Services\Retention;

use App\Models\StorefrontLegalAcceptance;
use App\Models\StorefrontLegalDocument;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * How long the legal texts of a storefront and the acceptances of them are kept, and what may ever be removed (I2-01, I2-05).
 *
 * Nothing said. Both tables were created without a word about retention, which for a consent record is the one question a regulator asks
 * first, and "undefined" is the worst answer because it means every installation decides by accident -- one by never pruning and one by a
 * cron somebody wrote.
 *
 * The rules, in {@see rules()} so that the console command, the test and this docblock cannot drift apart:
 *
 *  1. **A legal text that was ever APPROVED is kept for good.** Published, archived, revoked -- it makes no difference: an acceptance row
 *     points at it by id, version and hash, and the text in force on a given date has to be showable afterwards. Revocation is not
 *     deletion; a revoked version is history that happens to have ended.
 *  2. **A DRAFT that was never approved may be removed** once it is older than `gcc.retention.legal.draft_days`. It is nobody's proof: no
 *     acceptance can point at it, because an acceptance is only written for a published text. This is the ONLY removal these rules permit.
 *  3. **An acceptance row is never deleted and never changed.** The model enforces both ({@see StorefrontLegalAcceptance::booted}), and
 *     these rules do not ask for an exception: a receipt that can be edited is not a receipt, and this is the one shown to a regulator.
 *  4. **The identity on an acceptance is not held by the acceptance.** The row carries a `customer_id` or a `guest_hash` and no name,
 *     address or telephone number, so closing the customer's account is what removes the person: `CustomerAccountDeletionService` either
 *     anonymises the customer row or deletes it, and in both cases the acceptance keeps the version and the hash it was written with. The
 *     proof survives the customer, which is the retention answer for a consent record -- keep what was agreed, lose who agreed it.
 *  5. **Nothing is removed by default.** `draft_days` ships at 0, which means never, so an installation that has not chosen a budget keeps
 *     everything, exactly as it does today. That is asserted first.
 */
#[Singleton]
final class LegalRecordRetention
{
    /** Statuses a legal text is never removed in. `draft` is absent on purpose and is governed by rule 2. */
    public const KEPT_FOR_GOOD = [
        StorefrontLegalDocument::STATUS_APPROVED,
        StorefrontLegalDocument::STATUS_PUBLISHED,
        StorefrontLegalDocument::STATUS_ARCHIVED,
    ];

    /** @return array<string, string> each record this policy governs, and the rule that governs it */
    public static function rules(): array
    {
        return [
            'legal_text_ever_approved' => 'kept for good: an acceptance points at it by id, version and hash, and the text in force on a date must stay showable',
            'legal_text_revoked' => 'kept for good: revocation ends a version, it does not remove one',
            'legal_text_draft_never_approved' => 'removable once older than gcc.retention.legal.draft_days, which is 0 (never) by default',
            'legal_acceptance_row' => 'never deleted and never changed; the model refuses both',
            'legal_acceptance_identity' => 'not held by the row: closing the customer account removes the person and the acceptance keeps the version and hash',
        ];
    }

    /** The budget in days after which a never-approved draft may be removed. Zero means never, which is what it ships as. */
    public function draftBudgetInDays(): int
    {
        return max(0, (int) config('gcc.retention.legal.draft_days', 0));
    }

    /** Whether these rules can remove anything at all on this installation. */
    public function removesAnything(): bool
    {
        return $this->draftBudgetInDays() > 0 && Schema::hasTable((new StorefrontLegalDocument())->getTable());
    }

    /**
     * The legal texts rule 2 permits removing: a draft, never approved, never published, older than the budget.
     *
     * `approved_at` and `published_at` are both read, not just the status, because a row can be put back to `draft` and a version that was
     * ever approved is kept for good whatever its status says now.
     */
    public function removableDrafts(): Builder
    {
        $query = StorefrontLegalDocument::query()->whereRaw('1 = 0');

        if (!$this->removesAnything()) {
            return $query;
        }

        return StorefrontLegalDocument::query()
            ->where('status', StorefrontLegalDocument::STATUS_DRAFT)
            ->whereNull('approved_at')
            ->whereNull('published_at')
            ->where('created_at', '<', now()->subDays($this->draftBudgetInDays()));
    }

    /**
     * What applying these rules would remove, without removing it. An operator reads this before `--apply`, and the console command's
     * default is this and not the removal.
     *
     * @return array{draft_days: int, removable_drafts: int, kept_for_good: int, acceptances: int}
     */
    public function plan(): array
    {
        $documentsExist = Schema::hasTable((new StorefrontLegalDocument())->getTable());
        $acceptancesExist = Schema::hasTable((new StorefrontLegalAcceptance())->getTable());

        return [
            'draft_days' => $this->draftBudgetInDays(),
            'removable_drafts' => $this->removesAnything() ? $this->removableDrafts()->count() : 0,
            'kept_for_good' => $documentsExist
                ? StorefrontLegalDocument::query()
                    ->where(fn (Builder $kept) => $kept->whereIn('status', self::KEPT_FOR_GOOD)->orWhereNotNull('approved_at')->orWhereNotNull('published_at'))
                    ->count()
                : 0,
            'acceptances' => $acceptancesExist ? StorefrontLegalAcceptance::query()->count() : 0,
        ];
    }

    /** Applies rule 2 and nothing else. Returns how many drafts were removed. */
    public function apply(): int
    {
        if (!$this->removesAnything()) {
            return 0;
        }

        return (int) $this->removableDrafts()->delete();
    }
}
