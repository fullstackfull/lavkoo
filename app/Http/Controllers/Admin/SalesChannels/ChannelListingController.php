<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\Support\ListingLabels;
use App\Http\Controllers\Admin\SalesChannels\Support\ProductDirectory;
use App\Http\Controllers\Admin\SalesChannels\Support\WriteState;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSalesChannelsMigrated;
use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Data\ListingRequirements;
use App\Services\SalesChannels\Listings\ListingDraftService;
use App\Services\SalesChannels\Listings\ListingOutcome;
use App\Services\SalesChannels\Listings\ListingPublisher;
use App\Services\SalesChannels\Listings\ProductChannelPanel;
use App\Services\SalesChannels\Listings\RegulatedProductGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Putting one of OUR products on a channel, from the product's own screen: completing the channel's fields, checking them, recording the clearance a regulated product needs,
 * sending it, and asking the channel afterwards what it made of it.
 *
 * It is a screen of its own, reached from the product form's "sales channels" card, and not a form inside that form: the product form is one big form already, and a form
 * inside a form is not valid HTML — the browser drops it, so the buttons would quietly do nothing. Being its own screen also keeps the module's own gates on it (the
 * `sales_channels` module, and the migrations having run) instead of putting them on the product form.
 *
 * What it will and will not do:
 *
 *  - OPENING IT CALLS NOBODY. Everything shown is what we already store — the draft, what the channel last said it requires, what it last said about the submission.
 *  - EVERY CALL IS A POST behind a rate limit, so a link on another site cannot start one, and one that really writes needs the confirmation box as well.
 *  - IT DECIDES NOTHING ITSELF. Whether a call may be made is ListingPublisher's and the transport's business; a refusal is shown as what it is — nothing was sent — and
 *    never as done. The host's listing switch ships OFF, so until the owner turns it on, "send" answers `listing_publishing_off` and sends nothing.
 *  - IT WRITES NO PRODUCT. A draft holds the CHANNEL's field names and values; the store's product is only read.
 */
class ChannelListingController extends Controller
{
    /** How many blank field rows the form offers beyond what the draft and the channel's requirements already name. */
    private const SPARE_FIELD_ROWS = 3;

    /** A channel's field name: what Amazon and Trendyol actually use. Anything else is not a field name and is refused rather than stored. */
    private const FIELD_NAME = 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/';

    public function __construct(
        private readonly ListingDraftService $drafts,
        private readonly ListingPublisher $publisher,
        private readonly ProductChannelPanel $panel,
        private readonly RegulatedProductGate $regulated,
        private readonly ProductDirectory $products,
        private readonly ListingLabels $labels,
    ) {
    }

    /** Where one product stands on every channel, and the fields each channel is waiting for. Calls no channel. */
    public function show(Request $request, int $product): View
    {
        if (!$this->draftsTableExists()) {
            return $this->sayItNeedsItsMigration();
        }
        $variantKey = $this->variantKeyIn($request);
        $rows = $this->panel->for($product);
        $drafts = ChannelListingDraft::where('product_id', $product)->where('variant_key', $variantKey)->get()->keyBy('account_id');

        return view('admin-views.sales-channels.listing', [
            'productId' => $product,
            'product' => $this->products->named([$product])->get($product),
            'variantKey' => $variantKey,
            'productProblems' => $this->panel->productProblems($product, $variantKey),
            'rows' => array_map(fn (array $row): array => $this->rowFor($row, $drafts->get($row['account']->id)), $rows),
            'publishingEnabled' => (bool) config('sales_channels.listing.publish_enabled', false),
            'labels' => $this->labels,
        ]);
    }

    /** Saves the channel's fields. It publishes nothing and calls nobody: editing a draft is a note to ourselves about what we intend to send. */
    public function draft(Request $request, int $product, SalesChannelAccount $account): RedirectResponse|View
    {
        if (!$this->draftsTableExists()) {
            return $this->sayItNeedsItsMigration();
        }
        $data = $request->validateWithBag('draft_' . $account->id, [
            'variant_key' => ['nullable', 'string', 'max:191'],
            'product_type' => ['nullable', 'string', 'max:191'],
            'fields' => ['nullable', 'array', 'max:60'],
            'fields.*.name' => ['nullable', 'string', 'max:191', self::FIELD_NAME],
            'fields.*.value' => ['nullable', 'string', 'max:2000'],
        ]);

        $draft = $this->drafts->draftFor(
            account: $account,
            productId: $product,
            variantKey: (string) ($data['variant_key'] ?? ''),
            productType: ($data['product_type'] ?? '') === '' ? null : (string) $data['product_type'],
            payload: $this->fieldsFrom($data['fields'] ?? []),
        );
        SafeToast::success(translate('listing_draft_saved_nothing_sent'));

        return $this->back($draft);
    }

    /**
     * Checks the draft: the store's own product first, then what the channel says it requires. It may READ from the channel (its product-type definition) and never writes.
     */
    public function check(ChannelListingDraft $draft): RedirectResponse
    {
        $this->announce($this->drafts->validate($draft), translate('listing_is_ready_to_send'));

        return $this->back($draft);
    }

    /** Records the clearance a regulated product needs. The person must hold the module; the reference is kept as typed and is what an inspector would be shown. */
    public function clearance(Request $request, ChannelListingDraft $draft): RedirectResponse
    {
        $data = $request->validateWithBag('clearance_' . $draft->id, ['reference' => ['required', 'string', 'max:190']]);

        $this->announce($this->drafts->recordClearance($draft, (string) $data['reference'], (int) auth('admin')->id()), translate('regulatory_clearance_recorded'));

        return $this->back($draft);
    }

    /** Sends the draft to the channel. The only action here that can change anything at a channel, and the only one that needs the confirmation box. */
    public function publish(Request $request, ChannelListingDraft $draft): RedirectResponse
    {
        $account = $draft->account;
        if ($account !== null && WriteState::of($account)->sendsForReal() && config('sales_channels.listing.publish_enabled', false)) {
            $request->validateWithBag('publish_' . $draft->id, ['confirm_live' => ['accepted']], ['confirm_live.accepted' => translate('confirm_that_this_is_sent_for_real')]);
        }

        $this->announce($this->publisher->publish($draft, (int) auth('admin')->id()), translate('listing_sent_waiting_for_the_channel'));

        return $this->back($draft);
    }

    /** Asks the channel what it HOLDS for our SKU. A read: it is what turns a submission the channel took into a listing we record, or into its refusal. */
    public function follow(ChannelListingDraft $draft): RedirectResponse
    {
        $this->announce($this->publisher->follow($draft), translate('channel_confirms_the_listing'));

        return $this->back($draft);
    }

    /** A person stops it. It takes the draft out of the way of the command's sweep; it tells the channel nothing, and removes no listing the channel already holds. */
    public function withdraw(ChannelListingDraft $draft): RedirectResponse
    {
        if ($draft->state === ChannelListingDraft::SUBMITTED) {
            // It is with the channel: withdrawing it here would only lose track of an answer that is still coming.
            SafeToast::warning(translate('listing_is_with_the_channel_follow_it_up_first'));

            return $this->back($draft);
        }

        $draft->forceFill(['state' => ChannelListingDraft::WITHDRAWN, 'refusal_code' => null])->save();
        SafeToast::info(translate('listing_draft_withdrawn'));

        return $this->back($draft);
    }

    /**
     * One account's row as the screen needs it: the draft, what it is waiting for, and the fields to show. Everything comes from what we store.
     *
     * @param array{account: SalesChannelAccount, listing: ?SalesChannelProductMapping, draft: ?ChannelListingDraft, state: string, problems: list<string>, canCreate: bool} $row
     * @return array<string, mixed>
     */
    private function rowFor(array $row, ?ChannelListingDraft $draft): array
    {
        $account = $row['account'];
        $requirements = (array) ($draft?->requirements ?? []);
        $required = array_values(array_map('strval', (array) ($requirements['required'] ?? [])));

        return [
            'account' => $account,
            'listing' => $row['listing'],
            'draft' => $draft,
            'state' => $draft !== null && $row['listing'] === null ? (string) $draft->state : $row['state'],
            'problems' => array_values(array_map('strval', [...(array) ($draft?->problems ?? []), ...(array) ($draft?->channel_problems ?? [])])),
            'canCreate' => $row['canCreate'],
            'writeState' => WriteState::of($account),
            'needsClearance' => $draft !== null && $this->regulated->needsClearance((int) $draft->product_id, (string) $account->channel),
            'requirements' => array_values(array_map('strval', (array) ($requirements['required'] ?? []))),
            'requirementsFromTheOwner' => ($requirements['source'] ?? null) === ListingRequirements::DECLARED_BY_THE_OWNER,
            'sku' => (string) (($draft->payload ?? [])['sku'] ?? ''),
            'readyToSend' => $draft?->state === ChannelListingDraft::VALIDATED,
            'waiting' => $draft !== null && $draft->isWaitingForTheChannel(),
            'withdrawn' => $draft?->state === ChannelListingDraft::WITHDRAWN,
            'fields' => $this->fieldRows($draft, $required),
        ];
    }

    /**
     * The field rows the form shows: what the draft already holds, then every field the channel said it requires and the draft has not, then a few blanks.
     *
     * The seller SKU is not among them — it is decided once, by the draft service, and a screen that let a person retype it would be a way of making a second listing of the
     * same product.
     *
     * @param list<string> $required
     * @return list<array{name: string, value: string, required: bool, fixed: bool}>
     */
    private function fieldRows(?ChannelListingDraft $draft, array $required): array
    {
        $payload = (array) ($draft?->payload ?? []);
        $attributes = (array) ($payload['attributes'] ?? array_diff_key($payload, array_flip(['sku', 'product_type', 'attributes'])));

        $rows = [];
        foreach ($attributes as $name => $value) {
            $rows[] = ['name' => (string) $name, 'value' => $this->shown($value), 'required' => in_array((string) $name, $required, true), 'fixed' => true];
        }
        foreach ($required as $name) {
            if (!array_key_exists($name, $attributes)) {
                $rows[] = ['name' => $name, 'value' => '', 'required' => true, 'fixed' => true];
            }
        }
        for ($spare = 0; $spare < self::SPARE_FIELD_ROWS; $spare++) {
            $rows[] = ['name' => '', 'value' => '', 'required' => false, 'fixed' => false];
        }

        return $rows;
    }

    /** A stored value as a form shows it. A channel's value can be a list or an object; it is shown as JSON so that saving it back does not flatten it by accident. */
    private function shown(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The field rows a person filled in, as the channel's own field names and values. A row with no name is a blank the person did not use.
     *
     * @param array<int|string, mixed> $fields
     * @return array<string, string>
     */
    private function fieldsFrom(array $fields): array
    {
        $payload = [];
        foreach ($fields as $field) {
            $name = trim((string) (((array) $field)['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $payload[$name] = (string) (((array) $field)['value'] ?? '');
        }

        return $payload;
    }

    /** What the attempt came to, in words, and never more than it was: a refusal says nothing was sent. */
    private function announce(ListingOutcome $outcome, string $done): void
    {
        $reason = $this->labels->refusal($outcome->reason);
        $problems = $outcome->problems === [] ? null : $this->labels->problems(array_slice($outcome->problems, 0, 8));

        match ($outcome->state) {
            ListingOutcome::REFUSED => SafeToast::warning(translate('nothing_was_sent_and_here_is_why'), $problems === null ? $reason : $reason . ' — ' . $problems),
            ListingOutcome::REJECTED => SafeToast::error(translate('the_channel_refused_the_listing'), $problems ?? $reason),
            ListingOutcome::WORKING => SafeToast::info(translate('the_channel_has_not_finished_with_it_yet')),
            ListingOutcome::ACCEPTED => $problems === null
                ? SafeToast::success(translate('channel_confirms_the_listing'))
                : SafeToast::warning(translate('channel_confirms_the_listing_with_something_to_look_at'), $problems),
            default => SafeToast::success($done, $problems),
        };
    }

    /**
     * The module's readiness check (SalesChannelSchema) deliberately does NOT require this table: without it the product form's channels card simply shows nothing, rather
     * than taking the whole catalogue down over a migration that has not run. This screen cannot work without it, so it says which migration is missing — the same page the
     * module shows when none of them has run — instead of failing on a missing table.
     */
    private function draftsTableExists(): bool
    {
        return Schema::hasTable((new ChannelListingDraft())->getTable());
    }

    private function sayItNeedsItsMigration(): View
    {
        return view('admin-views.sales-channels.not-migrated', ['migrations' => EnsureSalesChannelsMigrated::MIGRATIONS]);
    }

    /** A variant key as a request may name it; the empty string means the product itself, which is what the draft table stores. */
    private function variantKeyIn(Request $request): string
    {
        return mb_substr(trim((string) $request->query('variant', '')), 0, 191);
    }

    private function back(ChannelListingDraft $draft): RedirectResponse
    {
        return redirect()->route('admin.sales-channels.listings.show', [
            'product' => $draft->product_id,
            'variant' => (string) $draft->variant_key === '' ? null : $draft->variant_key,
        ]);
    }
}
