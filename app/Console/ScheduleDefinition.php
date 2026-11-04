<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Everything this platform runs on a clock, in one readable place.
 *
 * It lived in the `withSchedule` closure in `bootstrap/app.php`, which Laravel invokes from
 * `Artisan::starting` — so the schedule exists only inside a console process. A web request never
 * starts Artisan, which meant the admin Scheduler page could show the runs it had recorded and
 * could not show what is *supposed* to run: an operator could see that `payouts:mature` failed last
 * night and not that it should fire again at 02:00, and a task that had never run once was
 * indistinguishable from a task that does not exist.
 *
 * Naming it lets both callers read the same definition: the console registers it as before, and the
 * monitoring collector applies it to a throwaway Schedule to enumerate what is defined. There is
 * still exactly one list, so a task added here is monitored the moment it exists.
 */
class ScheduleDefinition
{
    public static function define(Schedule $schedule): void
    {
        // Abandoned-cart reminder emails. This command is the only sender of them, so without a
        // schedule the retention feature ships but never actually emails anyone.
        $schedule->command('cart:remind-abandoned')->everyThirtyMinutes()->withoutOverlapping();

        // Mature vendor earnings out of the return window and roll up settlements. Order earnings are
        // recorded pending with an available_at; `--release` matures a delivered order's earning to
        // available so the seller can be settled and paid. Without this run nothing ever matures and no
        // seller can be paid through the ledger. (Requires the server cron `* * * * * php artisan
        // schedule:run` to be installed — a deployment step.)
        $schedule->command('marketplace:settle --release')->dailyAt('02:00')->withoutOverlapping();

        // Second-touch abandoned-cart reminder. The command supports staged drips but only stage 1 was
        // scheduled, so a second reminder never sent. Runs daily; still gated by the admin toggle.
        $schedule->command('cart:remind-abandoned --stage=2')->dailyAt('10:00')->withoutOverlapping();

        // Evaluate seller SLA thresholds into the breach ledger. Previously only the admin button ran
        // this, so breaches went stale between manual clicks.
        $schedule->command('marketplace:evaluate-sla')->dailyAt('03:00')->withoutOverlapping();

        // Recompute what each seller should be looking at. Hourly rather than daily because the
        // things it raises — stock about to run out, an order approaching its deadline — stop being
        // useful the moment they are stale.
        $schedule->command('seller:refresh-insights')->hourly()->withoutOverlapping();

        // Publish the theme versions a merchant scheduled. Five minutes is the resolution the
        // builder promises, and matches the heartbeat that tells the dashboard the cron is alive —
        // a scheduled publish is only as trustworthy as the run that fires it.
        $schedule->command('theme:publish-due')->everyFiveMinutes()->withoutOverlapping();

        // Promote issues nobody has answered. Every four hours rather than hourly: escalation asks
        // how long something has stood, and that answer changes slowly. Running it as often as
        // detection would spend a sweep to learn nothing almost every time.
        $schedule->command('seller:escalate-issues')->everyFourHours()->withoutOverlapping();

        // Seller rules. Every fifteen minutes is the floor a rule's own cooldown is measured
        // against — a rule set to run hourly still runs hourly; this only decides how often the
        // sweep asks. Non-overlapping, because two sweeps evaluating the same rule would each see
        // the pre-run state and could both act on the same listing.
        $schedule->command('seller:run-automation')->everyFifteenMinutes()->withoutOverlapping();

        // Webhook retries. The schedule lives on the delivery row rather than in the queue's own
        // delayed jobs, so that a seller can read "next attempt in eight minutes" on their screen —
        // and so nothing is lost to a worker restart. This sweep is what turns the schedule into
        // work.
        $schedule->command('seller:retry-webhooks')->everyFiveMinutes()->withoutOverlapping();

        // Bulk price and stock changes are queued, which makes them depend on a worker running. A
        // deployment without one would leave every bulk job at `queued` for ever while the app shows
        // the seller a change that is never going to happen — the exact failure the receipt exists to
        // prevent. Where a worker does exist this sweep finds nothing.
        $schedule->command('seller:run-stuck-bulk-jobs')->everyMinute()->withoutOverlapping();

        // Reconcile the storefront search index against the catalogue in case a bulk import bypassed the
        // model observer that keeps it fresh in realtime.
        $schedule->command('search:reindex-products')->weekly()->sundays()->at('04:00')->withoutOverlapping();

        // Heartbeat: record the last scheduler run in the DB (not cache, which optimize:clear wipes) so
        // the admin dashboard can warn when the server cron `schedule:run` has stopped firing — the
        // failure mode where settlements silently never mature.
        $schedule->call(function () {
            \App\Models\BusinessSetting::updateOrCreate(
                ['type' => 'scheduler_last_run_at'],
                ['value' => now()->toDateTimeString()]
            );
        })->everyFiveMinutes()->name('scheduler-heartbeat')->withoutOverlapping();

        /*
        | Monitoring.
        |
        | The one-minute flush is the collection heartbeat: it drains the request counters that web
        | requests have been incrementing in Redis and takes a reading of every gauge. If it stops,
        | the dashboard reports that monitoring itself has gone blind rather than showing the last
        | numbers it saw as if they were current.
        */
        $schedule->command('monitoring:flush')->everyMinute()->withoutOverlapping()->runInBackground();
        // Health and synthetic checks: probes of the things a request does not touch.
        //$schedule->command('monitoring:check')->everyFiveMinutes()->withoutOverlapping();
        // Evaluate alert rules against the series the flush just wrote.
        $schedule->command('monitoring:evaluate')->everyMinute()->withoutOverlapping();
        // Minutes into hours into days, and prune past each resolution's retention.
        $schedule->command('monitoring:rollup')->hourlyAt(3)->withoutOverlapping();
        $schedule->command('monitoring:rollup --prune')->dailyAt('01:45')->withoutOverlapping();

        /*
        | Analytics.
        |
        | Hourly so today's charts are never more than an hour behind, and a nightly pass that
        | rebuilds yesterday (late events, an order paid after midnight) and prunes raw rows past
        | their retention. Rollups are a rebuild of the day, so running them repeatedly is safe.
        */
        $schedule->command('analytics:rollup')->hourlyAt(12)->withoutOverlapping();
        $schedule->command('analytics:rollup --days=2 --prune')->dailyAt('02:15')->withoutOverlapping();

        // Rebuild the per-product engagement summary dynamic collections rank by. After the
        // rollup on purpose: views_30d and carted_30d read analytics_daily, so running first
        // would rank against yesterday twice.
        $schedule->command('commerce:metrics-refresh')->hourlyAt(22)->withoutOverlapping();

        // Campaign lifecycle: the serve path already obeys the window, so this only tidies
        // statuses and flushes caches at the exact transition (§32–33).
        $schedule->command('commerce:campaigns-tick')->everyFiveMinutes()->withoutOverlapping();

        // Compress raw request telemetry into daily rollups for Analytics; the
        // nightly run also prunes raw rows past the retention window.
        $schedule->command('telemetry:rollup')->hourlyAt(7)->withoutOverlapping();
        // Yesterday, once it is over. The hourly run only ever covers the current day, so its last
        // pass at 23:07 left 23:07 to midnight in no daily row at all — fifty-three minutes of
        // every day missing, permanently, once the raw rows aged out.
        $schedule->command('telemetry:rollup --date=yesterday')->dailyAt('00:20')->withoutOverlapping();
        $schedule->command('telemetry:rollup --prune')->dailyAt('01:30')->withoutOverlapping();

        /*
        | An API surface snapshot, weekly.
        |
        | Four surfaces are wired to render deprecations and a change log — the portal screen, the
        | OpenAPI flag, the Postman annotation and the Monitoring panel — and the snapshot service,
        | diff engine and breaking-change classifier were all built and never run, so all four had
        | nothing to compare against and showed nothing on every install. A snapshot nobody takes is
        | a change log that can never exist.
        */
        $schedule->command('api:snapshot')->weeklyOn(1, '04:10')->withoutOverlapping();
    }
}