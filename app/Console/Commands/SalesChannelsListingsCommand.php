<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\ChannelListingDraft;
use App\Services\SalesChannels\Listings\ListingDraftService;
use App\Services\SalesChannels\Listings\ListingOutcome;
use App\Services\SalesChannels\Listings\ListingPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * The drafts that put the store's products on a channel, from the command line: what state each is in, what the channel says is missing, and — when a person asks for it by id —
 * sending one.
 *
 * `--check` reads the channels and writes nothing to them. Sending needs every switch and the approval (see ListingPublisher); `--publish` without an id refuses, because a
 * regulated product may only be published by a named person and a sweep is nobody.
 */
class SalesChannelsListingsCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:listings
        {--account= : only this account id}
        {--check : ask each channel what it requires and say what is missing; writes nothing to any channel}
        {--publish= : the draft id to send (a person must say which; a draft the channel already holds is never sent again)}
        {--admin= : the admin id asking, recorded with the submission}
        {--clear-regulated= : the draft id to record a regulatory clearance for (with --admin and --reference); nothing is sent}
        {--reference= : the licence, registration or decision that allows this product on this channel}
        {--follow : ask the channels what they HOLD for the drafts they are still working on}';

    protected $description = 'Show, check, send and follow up the drafts that list the store\'s products on a sales channel, and record the regulatory clearance a regulated product needs (sending is a WRITE: it needs the listing switch, the product-sync capability and a recorded product-writes approval)';

    public function handle(ListingDraftService $drafts, ListingPublisher $publisher): int
    {
        if ($this->notMigratedYet() || !Schema::hasTable('channel_listing_drafts')) {
            $this->line('The listing drafts table is not there yet; nothing to do.');

            return self::SUCCESS;
        }

        $query = ChannelListingDraft::query()->when($this->option('account'), fn ($builder, $id) => $builder->where('account_id', (int) $id))->orderBy('id');

        if ($this->option('clear-regulated') !== null) {
            return $this->recordClearance($drafts, (int) $this->option('clear-regulated'));
        }
        if ($this->option('publish') !== null) {
            return $this->publishOne($publisher, (int) $this->option('publish'));
        }
        if ($this->option('check')) {
            foreach ($query->get() as $draft) {
                $outcome = $drafts->validate($draft);
                $this->line("#{$draft->id} product {$draft->product_id}: {$outcome->state}" . ($outcome->reason === null ? '' : " ({$outcome->reason})") . $this->problems($outcome));
            }
        }
        if ($this->option('follow')) {
            foreach ((clone $query)->waitingForTheChannel()->get() as $draft) {
                $outcome = $publisher->follow($draft);
                $this->line("#{$draft->id}: {$outcome->state}" . $this->problems($outcome));
            }
        }

        $this->table(
            ['id', 'account', 'channel', 'product', 'variant', 'state', 'channel says', 'listing', 'submission', 'refusal', 'problems'],
            $query->get()->map(static fn (ChannelListingDraft $draft): array => [
                $draft->id, $draft->account_id, $draft->sales_channel, $draft->product_id, $draft->variant_key === '' ? '—' : $draft->variant_key,
                $draft->state, implode(',', (array) ($draft->channel_listing_statuses ?? [])) ?: '—', $draft->product_mapping_id ?? '—',
                $draft->submission_id ?? '—', $draft->refusal_code ?? '—',
                count((array) ($draft->problems ?? [])) + count((array) ($draft->channel_problems ?? [])),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * Records the clearance a regulated product needs. It sends nothing: the person recording it must hold the `sales_channels` module, and a reference (the licence, the
     * registration, the decision) is what makes it a clearance — an admin id on its own is not one.
     */
    private function recordClearance(ListingDraftService $drafts, int $draftId): int
    {
        $admin = $this->option('admin');
        $reference = (string) $this->option('reference');
        if ($admin === null || trim($reference) === '') {
            $this->error('A clearance needs --admin= (who records it) and --reference= (the licence, registration or decision that allows this product on this channel).');

            return self::FAILURE;
        }
        $draft = ChannelListingDraft::find($draftId);
        if ($draft === null) {
            $this->error("There is no draft #{$draftId}.");

            return self::FAILURE;
        }

        $outcome = $drafts->recordClearance($draft, $reference, (int) $admin);
        $this->line("#{$draft->id}: " . ($outcome->ok() ? 'clearance recorded' : (string) $outcome->reason));

        return $outcome->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function publishOne(ListingPublisher $publisher, int $draftId): int
    {
        $admin = $this->option('admin');
        if ($admin === null) {
            $this->error('Say which admin is asking (--admin=): a submission is recorded against a person.');

            return self::FAILURE;
        }
        $draft = ChannelListingDraft::find($draftId);
        if ($draft === null) {
            $this->error("There is no draft #{$draftId}.");

            return self::FAILURE;
        }

        $outcome = $publisher->publish($draft, (int) $admin);
        $this->line("#{$draft->id}: {$outcome->state}" . ($outcome->reason === null ? '' : " ({$outcome->reason})") . $this->problems($outcome));

        return $outcome->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function problems(ListingOutcome $outcome): string
    {
        return $outcome->problems === [] ? '' : ' — ' . implode('; ', array_slice($outcome->problems, 0, 5));
    }

}
