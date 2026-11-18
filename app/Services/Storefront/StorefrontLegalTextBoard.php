<?php

namespace App\Services\Storefront;

use App\Models\Admin;
use App\Models\StorefrontLegalDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * What the legal-text screen of one storefront shows: the text each required document type and locale needs and the one in force today, every version that was ever written with
 * who wrote and who approved it, and the steps each version can still take. It reads the table and the readiness service and decides nothing: StorefrontLegalPublisher refuses what
 * may not be done, and the screen offers a step only where its state allows it, so that a person is not shown a button that can only fail.
 */
final class StorefrontLegalTextBoard
{
    public const EDIT = 'edit';

    public const DISCARD = 'discard';

    public const APPROVE = 'approve';

    public const PUBLISH = 'publish';

    public const REVOKE = 'revoke';

    public const NEW_VERSION = 'new_version';

    public function __construct(private readonly StorefrontLegalReadiness $readiness)
    {
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('storefront_legal_documents');
    }

    /**
     * @return array{
     *     country: string,
     *     is_grandfathered: bool,
     *     required: list<array{type: string, locale: string, in_force: ?StorefrontLegalDocument}>,
     *     texts: list<array{text: StorefrontLegalDocument, steps: list<string>}>,
     *     people: array<int, string>
     * }
     */
    public function of(string $country): array
    {
        $texts = $this->tableExists()
            ? StorefrontLegalDocument::query()->where('country_code', $country)->orderBy('document_type')->orderBy('locale')->orderByDesc('version')->orderByDesc('id')->get()
            : collect();

        return [
            'country' => $country,
            'is_grandfathered' => $this->readiness->isGrandfathered($country),
            'required' => array_map(
                fn (array $pair): array => ['type' => $pair[0], 'locale' => $pair[1], 'in_force' => $this->tableExists() ? $this->readiness->currentTextFor(countryCode: $country, documentType: $pair[0], locale: $pair[1]) : null],
                $this->readiness->requiredPairsOf($country),
            ),
            'texts' => $texts->map(fn (StorefrontLegalDocument $text): array => ['text' => $text, 'steps' => $this->stepsOf(text: $text, siblings: $this->siblingsOf(text: $text, texts: $texts))])->all(),
            'people' => Admin::query()->whereIn('id', $texts->pluck('created_by')->merge($texts->pluck('approved_by'))->filter()->unique())->pluck('name', 'id')->all(),
        ];
    }

    /**
     * The steps a version can take in the state it is in. A published text that is standing can be revoked. It has a new version only while it is the newest published text of its type
     * and locale (a revoked one counts: the publisher copies the newest published text, and refuses a revoked one and one that a newer text has superseded) and no draft or approved text of
     * that type and locale is open (one text of a type and locale is written at a time, and the publisher refuses a second). Who may take the steps is the role's business, and whether
     * this particular person may is the service's.
     *
     * @param Collection<int, StorefrontLegalDocument> $siblings the texts of the same type and locale as this one, itself included
     *
     * @return list<string>
     */
    public function stepsOf(StorefrontLegalDocument $text, Collection $siblings): array
    {
        $isNewestPublished = (int) $text->version === (int) $siblings->where('status', StorefrontLegalDocument::STATUS_PUBLISHED)->max('version');
        $isOneBeingWritten = $siblings->whereIn('status', [StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::STATUS_APPROVED])->isNotEmpty();

        return match (true) {
            $text->status === StorefrontLegalDocument::STATUS_DRAFT => [self::EDIT, self::APPROVE, self::DISCARD],
            $text->status === StorefrontLegalDocument::STATUS_APPROVED => [self::EDIT, self::PUBLISH, self::DISCARD],
            $text->status === StorefrontLegalDocument::STATUS_PUBLISHED && $text->revoked_at !== null => [],
            $text->status === StorefrontLegalDocument::STATUS_PUBLISHED && $isNewestPublished && !$isOneBeingWritten => [self::REVOKE, self::NEW_VERSION],
            $text->status === StorefrontLegalDocument::STATUS_PUBLISHED => [self::REVOKE],
            default => [],
        };
    }

    /**
     * @param Collection<int, StorefrontLegalDocument> $texts every text of the storefront
     *
     * @return Collection<int, StorefrontLegalDocument> the texts of the same type and locale as this one, itself included
     */
    private function siblingsOf(StorefrontLegalDocument $text, Collection $texts): Collection
    {
        return $texts->where('document_type', $text->document_type)->where('locale', $text->locale);
    }
}
