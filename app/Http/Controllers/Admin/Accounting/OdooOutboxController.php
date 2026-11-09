<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\OdooPosting;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooOutbox;
use App\Services\Accounting\Odoo\OdooPostingRun;
use App\Services\Accounting\Odoo\OdooPreflightRun;
use App\Services\Accounting\Odoo\OdooSchema;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What is waiting to be posted, what was posted, and — the part of this screen that matters — **what is waiting for a person**.
 *
 * A row reaches `needs_review` when a create went out and Odoo can neither confirm nor deny the entry. Nothing retries it,
 * because retrying might post the same cost twice and abandoning it might lose one, and a machine does not choose between those
 * in somebody's books. So this screen exists to let a person end that state with what they know, in exactly two ways:
 *
 *  - **"Odoo has it, and this is its id"** — the entry was found in Odoo by hand; the row is closed against that id;
 *  - **"it is not there and will not be"** — the row is closed as not posted, with the reason the person gives.
 *
 * Both are recorded with who decided and when. Neither invents a figure: the amount was never this screen's to change.
 */
class OdooOutboxController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly OdooOutbox $outbox,
        private readonly OdooPostingRun $run,
        private readonly OdooPreflightRun $preflight,
    ) {
    }

    public function index(Request $request): View
    {
        if (!OdooSchema::ready()) {
            return view('admin-views.accounting.odoo.not-migrated', ['migration' => OdooSchema::MIGRATION]);
        }

        $state = (string) $request->query('state', '');
        $rows = OdooPosting::query()
            ->when($state !== '', fn ($query) => $query->where('state', $state))
            // The rows a person is owed a decision on come first, however old the rest are.
            ->orderByRaw("CASE WHEN state = ? THEN 0 ELSE 1 END", [OdooPosting::NEEDS_REVIEW])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin-views.accounting.odoo.outbox', [
            'rows' => $rows,
            'counts' => $this->outbox->counts(),
            'state' => $state,
            'connection' => $this->connection(),
        ]);
    }

    /**
     * Works a batch.
     *
     * It is a POST and rate limited because it talks to Odoo, and it asks before it writes: every row has its reference looked
     * for in Odoo first, which is the only uniqueness Odoo can be asked for.
     */
    public function drain(): RedirectResponse
    {
        $connection = $this->connection();
        if ($connection === null) {
            SafeToast::warning(translate('odoo_save_the_host_first'));

            return back();
        }

        try {
            $preflight = $this->preflight->run($connection);
            if (!$preflight->couldBuildAnEntry()) {
                SafeToast::warning(translate('odoo_connection_test_incomplete'), implode(', ', $preflight->readinessCodes()));

                return back();
            }
            $stats = $this->run->drain($connection, $preflight);
        } catch (ChannelUnavailableException | ChannelPermissionDenied $refused) {
            SafeToast::warning(translate('odoo_posting_refused'), $refused->getMessage());

            return back();
        }

        $stats['stopped'] === null
            ? SafeToast::success(translate('odoo_posting_run_finished'), $this->runLine($stats))
            : SafeToast::warning(translate('odoo_posting_run_stopped'), (string) $stats['stopped']);

        return back();
    }

    /**
     * A person says Odoo holds the entry, and gives its id.
     *
     * The id is theirs to supply because they are the ones who looked. Nothing here asks Odoo again: the point of this action is
     * that the asking did not settle it.
     */
    public function found(Request $request, OdooPosting $row): RedirectResponse
    {
        $data = $request->validate([
            'odoo_move_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if ($row->state !== OdooPosting::NEEDS_REVIEW) {
            SafeToast::warning(translate('odoo_only_a_row_awaiting_review_is_settled_by_hand'));

            return back();
        }

        $row->forceFill([
            'state' => OdooPosting::POSTED,
            'odoo_move_id' => (int) $data['odoo_move_id'],
            'posted_at' => $row->posted_at ?? now(),
            'verified_at' => now(),
            'outcome' => 'found_by_a_person',
            'failure_code' => null,
            'failure_detail' => (string) $data['reason'],
            'reviewed_by' => (int) ($request->user()?->id ?? 0),
            'reviewed_at' => now(),
            'available_at' => null,
            'claimed_by' => null,
            'claimed_at' => null,
        ])->save();

        SafeToast::success(translate('odoo_row_closed_against_an_entry'));

        return back();
    }

    /** A person says the entry is not in Odoo and will not be. The row closes as not posted, with their reason. */
    public function closed(Request $request, OdooPosting $row): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        if ($row->state !== OdooPosting::NEEDS_REVIEW) {
            SafeToast::warning(translate('odoo_only_a_row_awaiting_review_is_settled_by_hand'));

            return back();
        }

        $row->forceFill([
            // Kept and counted, never deleted: a cost nobody posted belongs on a screen.
            'state' => OdooPosting::DEAD,
            'outcome' => 'closed_by_a_person',
            'failure_detail' => (string) $data['reason'],
            'reviewed_by' => (int) ($request->user()?->id ?? 0),
            'reviewed_at' => now(),
            'available_at' => null,
            'claimed_by' => null,
            'claimed_at' => null,
        ])->save();

        SafeToast::info(translate('odoo_row_closed_as_not_posted'));

        return back();
    }

    /** @param array<string, mixed> $stats */
    private function runLine(array $stats): string
    {
        $parts = [];
        foreach (['posted', 'found', 'needs_review', 'failed', 'not_postable', 'lost_the_row'] as $key) {
            if ((int) ($stats[$key] ?? 0) > 0) {
                $parts[] = translate('odoo_run_stat_' . $key) . ': ' . (int) $stats[$key];
            }
        }

        return $parts === [] ? translate('odoo_nothing_was_due') : implode(' · ', $parts);
    }

    private function connection(): ?SalesChannelAccount
    {
        return SalesChannelAccount::where('channel', 'odoo')->orderBy('id')->first();
    }
}
