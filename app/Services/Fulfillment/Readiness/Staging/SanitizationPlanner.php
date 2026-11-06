<?php

namespace App\Services\Fulfillment\Readiness\Staging;

use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;

/**
 * Writes the sanitization plan for the database it is pointed at. It only READS the schema (which tables and columns exist),
 * through the read-only probe, and prints statements: it never executes one. The statements are tailored to what is really
 * there, because most of the tables involved are created by the installation dump and are known here only from the code.
 */
final class SanitizationPlanner
{
    private const ADMIN_NOTE = 'Staging administrator: the plan empties every password, so nobody can sign in. After running it, set a password for ONE admin by hand, for example `php artisan tinker --execute="App\\Models\\Admin::query()->orderBy(\'id\')->first()?->forceFill([\'password\' => bcrypt(\'<a new password>\')])->save();"` — never reuse a production password.';

    private const URL_NOTE = 'Absolute production URLs stored in the data (redirects, page and product text, banners, canonical links, e-mail templates) send staging visitors to production: run `php artisan fulfillment:outbound-audit --production-host=<host>` to count them, then rewrite or disable the hits.';

    public function plan(ReadOnlyProbe $probe, string $expectedDatabase): SanitizationPlan
    {
        $sql = SqlDialect::forDriver($probe->driver());
        $steps = [];
        $skipped = [];
        $manual = [self::ADMIN_NOTE, self::URL_NOTE];

        foreach (StagingChannels::all() as $channel) {
            foreach ($channel->targets as $target) {
                $reason = $target->unavailableReason(array_keys($probe->columns($target->table)));
                if ($reason !== null) {
                    $skipped[] = "{$channel->key}: {$reason}";
                } elseif ($target->canSink()) {
                    $steps[] = [
                        'title' => "{$channel->label} ({$target->table})",
                        'why' => $channel->why,
                        'risk' => $channel->risk,
                        'statement' => $target->statement($sql),
                        'verification' => $target->verification($sql, "{$channel->key}: {$target->table}"),
                    ];
                }
            }
            if ($channel->manual !== null) {
                $manual[] = "{$channel->key}: {$channel->manual}";
            }
        }

        foreach (PersonalDataScrub::tables() as $scrub) {
            $present = array_keys($probe->columns($scrub->table));
            $statement = $scrub->statement($sql, $present);
            if ($statement === null) {
                $skipped[] = $present === [] ? "personal data: table {$scrub->table} is not present" : "personal data: table {$scrub->table} has none of the columns the scrub knows";

                continue;
            }

            $steps[] = [
                'title' => "Personal data in {$scrub->table}: " . implode(', ', $scrub->columnsPresent($present)),
                'why' => 'Defence in depth: if a channel above is missed, the people in this copy are still not reachable.',
                'risk' => 'personal data',
                'statement' => $statement,
                'verification' => (string) $scrub->verification($sql, $present),
            ];
        }

        return new SanitizationPlan(
            database: $expectedDatabase,
            driver: $probe->driver(),
            guard: $sql->guardDatabase($expectedDatabase),
            steps: $steps,
            skipped: $skipped,
            manual: $manual,
        );
    }
}
