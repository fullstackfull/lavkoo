<?php

namespace App\Services\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * I2-01 and I2-03: how the legal text of a storefront is written, approved, published and revoked. A text is a row of `storefront_legal_documents`; the readiness of a storefront
 * (StorefrontLegalReadiness) reads the newest effective published row of each required type and locale and counts it only when someone other than its author approved it, it is
 * not revoked, not blank and not an unedited template. This service is the only writer of those rows, and every step it takes is also written to the audit log in the same
 * transaction, so a step whose trace cannot be written is not taken.
 *
 *   template ──draft──▶ draft ──approve──▶ approved ──publish──▶ published ──revoke──▶ revoked (the row stays, and keeps the storefront closed)
 *                          ▲  edit/discard        │ edit returns it to draft
 *
 * - A copy of a template is a **draft** carrying the hash of the template, and is never published by being copied. A text that is still the template when it is approved is refused.
 * - A **published text is never edited**: a change is a new version, which is a new draft that starts as a copy of the text it replaces.
 * - The approver is **not the author**. The author of a text is the last person who changed it, so an edit by the approver makes the approver the author and voids the approval.
 * - An approval or a publication is bound to the words the person read: when they name the hash of those words, the step is refused if the locked text is not that any more.
 * - A text takes effect on a day the publisher names (now, or later), never in the past, and never before another text of the same type and locale that is already scheduled: the
 *   newest text by effective day is the one in force, and a newer version must not lose to an older one that was scheduled for later.
 * - Revoking keeps the row, and a revoked text in force keeps the storefront closed until a replacement takes effect. Nothing is deleted: a draft that is not wanted is discarded.
 * - The default storefront keeps the documents it has always had (GCC-19) and has no text here.
 *
 * Who the person is: the id of the admin, which is what the columns of the table hold. Whether that admin may do it is the business of the entry point (a command today, an admin
 * screen with its permission later), not of this service.
 */
#[Singleton]
final class StorefrontLegalPublisher
{
    private const AUDIT_SUBJECT = 'storefront_legal_document';

    private const DOCUMENT_TYPE_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    private const LOCALE_PATTERN = '/^[a-z]{2}$/';

    /** A text may be published for a moment that has just passed (the clock of the person and of the server never agree to the second), not for a day in the past. */
    private const TOLERATED_LATENESS_IN_MINUTES = 5;

    public function __construct(private readonly StorefrontLegalReadiness $readiness)
    {
    }

    /** A draft that is a copy of a template: the text is the template, and its hash says so, so that the template unedited can be told from a text. */
    public function draftFromTemplate(string $country, string $documentType, string $locale, string $template, int $author): StorefrontLegalDocument
    {
        $country = $this->knownStorefront($country);
        $documentType = $this->documentType($documentType);
        $locale = $this->locale($locale);
        $this->nonBlank(text: $template, what: 'The template');
        $this->knownPerson($author);

        return DB::transaction(function () use ($country, $documentType, $locale, $template, $author): StorefrontLegalDocument {
            $this->refuseASecondOpenText(country: $country, documentType: $documentType, locale: $locale);

            $draft = $this->insertDraft(country: $country, documentType: $documentType, locale: $locale, body: $template, templateHash: hash('sha256', $template), author: $author);

            $this->audit(action: 'storefront.legal_text.drafted', text: $draft, before: null, after: $draft, admin: $author, context: ['from' => 'template', 'template_hash' => $draft->template_hash]);

            return $draft;
        });
    }

    /** A new version of a published text: a draft that starts as a copy of the text it replaces, which stays in force until the new version takes effect. */
    public function draftNewVersionOf(int $textId, int $author): StorefrontLegalDocument
    {
        $this->knownPerson($author);

        return DB::transaction(function () use ($textId, $author): StorefrontLegalDocument {
            $published = $this->locked($textId);

            if ($published->status !== StorefrontLegalDocument::STATUS_PUBLISHED) {
                throw new RuntimeException("Text {$textId} is not published ({$published->status}): only a published text has a new version; a draft is edited.");
            }

            $this->refuseASecondOpenText(country: $published->country_code, documentType: $published->document_type, locale: $published->locale);

            $draft = $this->insertDraft(country: $published->country_code, documentType: $published->document_type, locale: $published->locale, body: (string) $published->body, templateHash: null, author: $author);

            $this->audit(action: 'storefront.legal_text.drafted', text: $draft, before: null, after: $draft, admin: $author, context: ['from' => 'version', 'previous_text_id' => $published->id]);

            return $draft;
        });
    }

    /** Changes the text of a draft, or of an approved text, which goes back to draft: the editor is its author now, and an approval of another text is no approval of this one. */
    public function edit(int $textId, string $body, int $editor): StorefrontLegalDocument
    {
        $this->nonBlank(text: $body, what: 'The text');
        $this->knownPerson($editor);

        return DB::transaction(function () use ($textId, $body, $editor): StorefrontLegalDocument {
            $text = $this->locked($textId);

            if (!in_array($text->status, [StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::STATUS_APPROVED], true)) {
                throw new RuntimeException("Text {$textId} is {$text->status} and is never edited in place: a published text gets a new version (draftNewVersionOf), a discarded one stays as it is.");
            }

            $before = $text->replicate()->forceFill(['id' => $text->id]);

            $text->forceFill([
                'body' => $body,
                'body_hash' => hash('sha256', $body),
                'status' => StorefrontLegalDocument::STATUS_DRAFT,
                'created_by' => $editor,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->audit(action: 'storefront.legal_text.edited', text: $text, before: $before, after: $text, admin: $editor, context: ['previous_body_hash' => $before->body_hash]);

            return $text;
        });
    }

    /**
     * The approval of a draft by someone other than its author, of a text that is not blank and is not the template it was copied from. An approver who names the hash of the words
     * they read ($expectedBodyHash) approves those words only: a text that has changed since is refused.
     */
    public function approve(int $textId, int $approver, ?string $expectedBodyHash = null): StorefrontLegalDocument
    {
        $this->knownPerson($approver);

        return DB::transaction(function () use ($textId, $approver, $expectedBodyHash): StorefrontLegalDocument {
            $text = $this->locked($textId);
            $this->refuseATextChangedSinceItWasRead(text: $text, expectedBodyHash: $expectedBodyHash);

            if ($text->status !== StorefrontLegalDocument::STATUS_DRAFT) {
                throw new RuntimeException("Text {$textId} is {$text->status}: only a draft is approved.");
            }

            if ((int) $text->created_by === $approver) {
                throw new RuntimeException("Text {$textId} was written by admin {$approver}, and a text is approved by someone other than its author.");
            }

            $this->nonBlank(text: (string) $text->body, what: 'The text');

            if ($text->template_hash !== null && $text->template_hash === $text->body_hash) {
                throw new RuntimeException("Text {$textId} is still the template it was copied from: it is edited before it is approved.");
            }

            $before = $text->replicate()->forceFill(['id' => $text->id]);

            $text->forceFill(['status' => StorefrontLegalDocument::STATUS_APPROVED, 'approved_by' => $approver, 'approved_at' => now()])->save();

            $this->audit(action: 'storefront.legal_text.approved', text: $text, before: $before, after: $text, admin: $approver, context: ['author' => (int) $text->created_by]);

            return $text;
        });
    }

    /**
     * Publishes an approved text from a day that is now or later. It is in force from then on, and it never loses to a text of the same type and locale that is scheduled for later.
     * A publisher who names the hash of the words they read ($expectedBodyHash) publishes those words only: a text that has changed since is refused.
     */
    public function publish(int $textId, int $publisher, ?DateTimeInterface $effectiveFrom = null, ?string $expectedBodyHash = null): StorefrontLegalDocument
    {
        $this->knownPerson($publisher);
        // The model keeps the moment as the digits of its own time zone and reads them back in the application's, so a moment given in UTC (the console command and the admin screen read
        // theirs as UTC) is converted first: stored as it came, it would be read back hours early or late and the text would take effect at the wrong time.
        $effective = CarbonImmutable::instance($effectiveFrom ?? CarbonImmutable::now())->setTimezone(date_default_timezone_get());

        if ($effective->lessThan(CarbonImmutable::now()->subMinutes(self::TOLERATED_LATENESS_IN_MINUTES))) {
            throw new InvalidArgumentException('A text does not take effect in the past: name a day that is now or later.');
        }

        return DB::transaction(function () use ($textId, $publisher, $effective, $expectedBodyHash): StorefrontLegalDocument {
            $text = $this->locked($textId);
            $this->refuseATextChangedSinceItWasRead(text: $text, expectedBodyHash: $expectedBodyHash);

            if ($text->status !== StorefrontLegalDocument::STATUS_APPROVED) {
                throw new RuntimeException("Text {$textId} is {$text->status}: only an approved text is published.");
            }

            $scheduled = StorefrontLegalDocument::query()
                ->where('country_code', $text->country_code)
                ->where('document_type', $text->document_type)
                ->where('locale', $text->locale)
                ->where('status', StorefrontLegalDocument::STATUS_PUBLISHED)
                ->max('effective_from');

            if ($scheduled !== null && $effective->lessThan(CarbonImmutable::parse($scheduled))) {
                throw new RuntimeException("Another text of this type and locale is already scheduled for {$scheduled}: a text takes effect on that day or later, or the scheduled one is revoked first.");
            }

            $before = $text->replicate()->forceFill(['id' => $text->id]);

            $text->forceFill(['status' => StorefrontLegalDocument::STATUS_PUBLISHED, 'published_at' => now(), 'effective_from' => $effective])->save();

            $this->audit(action: 'storefront.legal_text.published', text: $text, before: $before, after: $text, admin: $publisher, context: ['effective_from' => $effective->toIso8601String()]);

            return $text;
        });
    }

    /** Revokes a published text. It stays a row, and while it is the text in force the storefront is closed; a text that has not taken effect yet is archived instead, and never takes effect. */
    public function revoke(int $textId, int $revoker, string $reason): StorefrontLegalDocument
    {
        $this->nonBlank(text: $reason, what: 'The reason');
        $this->knownPerson($revoker);

        return DB::transaction(function () use ($textId, $revoker, $reason): StorefrontLegalDocument {
            $text = $this->locked($textId);

            if ($text->status !== StorefrontLegalDocument::STATUS_PUBLISHED || $text->revoked_at !== null) {
                throw new RuntimeException("Text {$textId} is not a published text that is still standing ({$text->status}" . ($text->revoked_at === null ? '' : ', revoked') . ').');
            }

            $before = $text->replicate()->forceFill(['id' => $text->id]);

            // A text that has not taken effect yet is not the text in force and never will be: it is archived, so that on its day it is not the newest text of its type and
            // locale (the readiness orders by effective day), which a revoked text that stayed published would be, closing the storefront in the text it was meant to replace.
            $beforeItTookEffect = $text->effective_from !== null && $text->effective_from->isFuture();

            $text->forceFill(['revoked_at' => now()] + ($beforeItTookEffect ? ['status' => StorefrontLegalDocument::STATUS_ARCHIVED] : []))->save();

            $this->audit(action: 'storefront.legal_text.revoked', text: $text, before: $before, after: $text, admin: $revoker, context: ['reason' => trim($reason), 'before_it_took_effect' => $beforeItTookEffect]);

            return $text;
        });
    }

    /** Puts away a draft or an approved text that will not be published. The row is kept, as history. */
    public function discard(int $textId, int $admin, string $reason): StorefrontLegalDocument
    {
        $this->nonBlank(text: $reason, what: 'The reason');
        $this->knownPerson($admin);

        return DB::transaction(function () use ($textId, $admin, $reason): StorefrontLegalDocument {
            $text = $this->locked($textId);

            if (!in_array($text->status, [StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::STATUS_APPROVED], true)) {
                throw new RuntimeException("Text {$textId} is {$text->status}: only a draft or an approved text is discarded; a published text is revoked.");
            }

            $before = $text->replicate()->forceFill(['id' => $text->id]);

            $text->forceFill(['status' => StorefrontLegalDocument::STATUS_ARCHIVED])->save();

            $this->audit(action: 'storefront.legal_text.discarded', text: $text, before: $before, after: $text, admin: $admin, context: ['reason' => trim($reason)]);

            return $text;
        });
    }

    private function insertDraft(string $country, string $documentType, string $locale, string $body, ?string $templateHash, int $author): StorefrontLegalDocument
    {
        $version = (int) StorefrontLegalDocument::query()->where('country_code', $country)->where('document_type', $documentType)->where('locale', $locale)->max('version') + 1;

        $draft = (new StorefrontLegalDocument())->forceFill([
            'country_code' => $country,
            'document_type' => $documentType,
            'locale' => $locale,
            'version' => $version,
            'status' => StorefrontLegalDocument::STATUS_DRAFT,
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'template_hash' => $templateHash,
            'created_by' => $author,
        ]);
        $draft->save();

        return $draft->refresh();
    }

    /** One text of a type and locale is being written at a time: a second draft beside an unpublished one is two people writing the same page. */
    private function refuseASecondOpenText(string $country, string $documentType, string $locale): void
    {
        $open = StorefrontLegalDocument::query()
            ->where('country_code', $country)
            ->where('document_type', $documentType)
            ->where('locale', $locale)
            ->whereIn('status', [StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::STATUS_APPROVED])
            ->lockForUpdate()
            ->first();

        if ($open !== null) {
            throw new RuntimeException("Text {$open->id} ({$open->status}, version {$open->version}) of this type and locale is not published yet: it is published or discarded before another is started.");
        }
    }

    /** A step is taken on the words the person read: when they name the hash of what they read and the locked text is not that any more, nothing is changed and nothing is audited. */
    private function refuseATextChangedSinceItWasRead(StorefrontLegalDocument $text, ?string $expectedBodyHash): void
    {
        if ($expectedBodyHash !== null && !hash_equals((string) $text->body_hash, strtolower($expectedBodyHash))) {
            throw new RuntimeException("Text {$text->id} changed after it was read: the words it holds now are not the ones whose hash was given. Read it again before taking this step.");
        }
    }

    private function locked(int $textId): StorefrontLegalDocument
    {
        $text = StorefrontLegalDocument::query()->lockForUpdate()->find($textId);

        if ($text === null) {
            throw new InvalidArgumentException("There is no legal text {$textId}.");
        }

        return $text;
    }

    private function knownStorefront(string $country): string
    {
        $code = strtoupper(trim($country));

        if ($this->readiness->isGrandfathered($code)) {
            throw new InvalidArgumentException("{$code} keeps the documents it has always had: it has no text here.");
        }

        if (!array_key_exists($code, (array) config('gcc.storefronts.countries', []))) {
            throw new InvalidArgumentException("{$code} is not a storefront of this platform.");
        }

        return $code;
    }

    private function documentType(string $documentType): string
    {
        $type = strtolower(trim($documentType));

        if (preg_match(self::DOCUMENT_TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException('A document type is lower case letters, digits and underscores, from 2 to 32 characters, for example terms.');
        }

        return $type;
    }

    private function locale(string $locale): string
    {
        $code = strtolower(trim($locale));

        if (preg_match(self::LOCALE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException('A locale is two letters, for example ar or en.');
        }

        return $code;
    }

    private function nonBlank(string $text, string $what): void
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException("{$what} is blank.");
        }
    }

    private function knownPerson(int $adminId): void
    {
        if ($adminId < 1) {
            throw new InvalidArgumentException('A step is taken by an admin: name the id of the admin who takes it.');
        }
    }

    private function audit(string $action, StorefrontLegalDocument $text, ?StorefrontLegalDocument $before, StorefrontLegalDocument $after, int $admin, array $context): void
    {
        app(AuditLogger::class)->recordOrFail(
            action: $action,
            subject: ['type' => self::AUDIT_SUBJECT, 'id' => $text->id],
            before: $before === null ? null : $this->summaryOf($before),
            after: $this->summaryOf($after),
            context: ['admin_id' => $admin, 'storefront' => $text->country_code, 'document_type' => $text->document_type, 'locale' => $text->locale, 'version' => (int) $text->version] + $context,
        );
    }

    /** What an audit line keeps of a text: its state, who and when, and the hash of its words, not the words themselves. */
    private function summaryOf(StorefrontLegalDocument $text): array
    {
        return [
            'status' => $text->status,
            'body_hash' => $text->body_hash,
            'created_by' => $text->created_by,
            'approved_by' => $text->approved_by,
            'approved_at' => $text->approved_at?->toIso8601String(),
            'published_at' => $text->published_at?->toIso8601String(),
            'effective_from' => $text->effective_from?->toIso8601String(),
            'revoked_at' => $text->revoked_at?->toIso8601String(),
        ];
    }
}
