<?php

namespace App\Console\Commands;

use App\Mail\AbandonedCartReminderMail;
use App\Models\AbandonedCartReminder;
use App\Services\Retention\AbandonedCartService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Sends the abandoned-cart reminders (Phase 2.4).
 *
 * This command puts mail in front of real people, so it is built to be **hard to fire by accident**:
 *
 *  - It does nothing unless the store owner has switched the feature on in the admin settings.
 *  - `--dry-run` prints exactly who would be written to and sends nothing. It is the intended way
 *    to try it the first time, and the output is the same table either way.
 *  - Every send is written to `abandoned_cart_reminders` **before** the mail leaves, keyed
 *    uniquely on (cart_group_id, stage). A second run cannot repeat a message even if the first
 *    run died halfway.
 *  - A per-run `--limit` caps the blast radius while the schedule is being tuned.
 *
 * Schedule it hourly once configured; the idle threshold, not the cron frequency, decides when a
 * cart is old enough to chase.
 */
class SendAbandonedCartReminders extends Command
{
    protected $signature = 'cart:remind-abandoned
                            {--dry-run : List the recipients and send nothing}
                            {--stage=1 : Position in the reminder sequence}
                            {--limit=100 : Maximum messages this run}';

    protected $description = 'Email customers who left items in their cart (off until enabled in settings)';

    public function handle(AbandonedCartService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stage = max(1, (int) $this->option('stage'));
        $limit = max(1, (int) $this->option('limit'));

        if (!$service->isEnabled()) {
            $this->warn('Abandoned-cart reminders are switched off. Enable them in the admin settings first.');

            // Not a failure: a disabled feature on a schedule should stay quiet, not alarm.
            return self::SUCCESS;
        }

        $candidates = $service->findAbandoned(stage: $stage);

        if ($candidates->isEmpty()) {
            $this->info('No abandoned carts match the current thresholds.');

            return self::SUCCESS;
        }

        $selected = $candidates->take($limit);
        if ($candidates->count() > $selected->count()) {
            // Never let a cap look like a complete run.
            $this->warn(sprintf(
                '%d carts matched; sending to the %d most recent because of --limit. Re-run for the rest.',
                $candidates->count(),
                $selected->count()
            ));
        }

        $this->table(
            ['cart group', 'recipient', 'guest', 'items', 'value', 'idle since'],
            $selected->map(fn (array $row) => [
                $row['cart_group_id'],
                $this->maskEmail($row['email']),
                $row['is_guest'] ? 'yes' : 'no',
                $row['item_count'],
                number_format($row['cart_value'], 2),
                Carbon::parse($row['last_activity'])->diffForHumans(),
            ])->all()
        );

        if ($dryRun) {
            $this->info(sprintf('Dry run: %d message(s) would be sent. Nothing was emailed.', $selected->count()));

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($selected as $row) {
            // Written first, and inside its own try: the unique key on (cart_group_id, stage) is
            // what stops a repeat, so it has to exist before the mail can possibly go out. If the
            // insert loses a race, another worker already claimed this cart — skip it silently.
            try {
                $reminder = AbandonedCartReminder::create([
                    'cart_group_id' => $row['cart_group_id'],
                    'customer_id' => $row['customer_id'],
                    'is_guest' => $row['is_guest'],
                    'email' => $row['email'],
                    'stage' => $stage,
                    'cart_value' => $row['cart_value'],
                    'item_count' => $row['item_count'],
                    'sent_at' => Carbon::now(),
                ]);
            } catch (\Throwable) {
                continue;
            }

            try {
                Mail::to($row['email'])->send(new AbandonedCartReminderMail(
                    reminder: $reminder,
                    recoveryUrl: $this->recoveryUrl($reminder),
                ));
                $sent++;
            } catch (\Throwable $exception) {
                $failed++;
                // The ledger row stays. It records an attempt, and leaving it in place means a
                // retry cannot turn one failed send into two delivered ones.
                $this->error(sprintf('%s: %s', $this->maskEmail($row['email']), $exception->getMessage()));
            }
        }

        $this->info(sprintf('Sent %d reminder(s).%s', $sent, $failed ? " {$failed} failed." : ''));

        return $failed && !$sent ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A signed link, so the recovery endpoint can trust the reminder id it is handed without
     * anybody being able to walk the sequence and mark other people's reminders as clicked.
     */
    private function recoveryUrl(AbandonedCartReminder $reminder): string
    {
        return URL::signedRoute('cart.recover', ['reminder' => $reminder->id], now()->addDays(30));
    }

    /** Console output is logged and screen-shared; addresses do not need to be in it in full. */
    private function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 2) . str_repeat('*', max(1, mb_strlen($user) - 2)) . '@' . $domain;
    }
}
