<?php

namespace App\Services\Storefront;

use App\Models\Admin;
use App\Models\StorefrontLegalDocument;
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
                fn (array $pair): array => ['type' => $pair[0], 'locale' => $pair[1], 'in_force' => $this->tableExists() ? $this->readiness->currentTextFor($country, $pair[0], $pair[1]) : null],
                $this->readiness->requiredPairsOf($country),
            ),
            'texts' => $texts->map(fn (StorefrontLegalDocument $text): array => ['text' => $text, 'steps' => $this->stepsOf($text)])->all(),
            'people' => Admin::query()->whereIn('id', $texts->pluck('created_by')->merge($texts->pluck('approved_by'))->filter()->unique())->pluck('name', 'id')->all(),
        ];
    }

    /**
     * The steps a version can take in the state it is in. Who may take them is the role's business, and whether this particular person may is the service's.
     *
     * @return list<string>
     */
    public function stepsOf(StorefrontLegalDocument $text): array
    {
        return match (true) {
            $text->status === StorefrontLegalDocument::STATUS_DRAFT => [self::EDIT, self::APPROVE, self::DISCARD],
            $text->status === StorefrontLegalDocument::STATUS_APPROVED => [self::EDIT, self::PUBLISH, self::DISCARD],
            $text->status === StorefrontLegalDocument::STATUS_PUBLISHED && $text->revoked_at === null => [self::REVOKE, self::NEW_VERSION],
            $text->status === StorefrontLegalDocument::STATUS_PUBLISHED => [self::NEW_VERSION],
            default => [],
        };
    }
}
