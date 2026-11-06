<?php

namespace App\Services\Fulfillment\Readiness\Staging;

use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use Illuminate\Database\QueryException;

/**
 * Which outbound channels of the platform a restored database still arms. It only COUNTS rows, through the read-only probe:
 * no credential, token, e-mail address or phone number is read into the report, so its output is safe to share.
 */
final class OutboundAudit
{
    private const SECTION = 'outbound';

    /** Columns that hold free text or a URL the production host may be written into; counted, never read. */
    private const URL_COLUMNS = [
        'redirects' => 'to_path', 'business_pages' => 'description', 'email_templates' => 'body', 'products' => 'details',
        'banners' => 'url', 'social_medias' => 'link', 'seo_meta_info' => 'canonical', 'robots_meta_contents' => 'canonical', 'business_settings' => 'value',
    ];

    /**
     * @param list<string> $allowedChannels channel keys the operator knowingly leaves armed (reported, not failed)
     * @param list<OutboundChannel>|null $channels the channels to audit (all of them by default)
     * @return list<CompatibilityCheck>
     */
    public function checks(ReadOnlyProbe $probe, array $allowedChannels = [], ?string $productionHost = null, ?array $channels = null): array
    {
        $sql = SqlDialect::forDriver($probe->driver());
        $checks = array_map(
            fn (OutboundChannel $channel): CompatibilityCheck => $this->channelCheck($probe, $sql, $channel, in_array($channel->key, $allowedChannels, true)),
            $channels ?? StagingChannels::all(),
        );

        return $productionHost === null ? $checks : [...$checks, $this->productionHostCheck($probe, $productionHost)];
    }

    private function channelCheck(ReadOnlyProbe $probe, SqlDialect $sql, OutboundChannel $channel, bool $allowed): CompatibilityCheck
    {
        $armed = [];
        $absent = [];
        $unreadable = [];

        foreach ($channel->targets as $target) {
            $reason = $target->unavailableReason(array_keys($probe->columns($target->table)));
            if ($reason !== null) {
                $absent[] = $reason;

                continue;
            }

            try {
                $rows = $probe->countWhere($target->table, $target->armedCondition($sql));
            } catch (QueryException $exception) {
                $unreadable[] = "{$target->table} (database error {$exception->getCode()})";

                continue;
            }
            if ($rows > 0) {
                $armed[] = ['table' => $target->table, 'armed_rows' => $rows];
            }
        }

        return new CompatibilityCheck(
            section: self::SECTION,
            name: $channel->label,
            status: $this->status($channel, $armed, $unreadable, $absent, $allowed),
            detail: $this->detail($channel, $armed, $unreadable, $absent, $allowed),
            facts: ['channel' => $channel->key, 'risk' => $channel->risk, 'armed' => $armed, 'not_present' => $absent, 'could_not_be_evaluated' => $unreadable],
        );
    }

    /** @param list<array{table: string, armed_rows: int}> $armed @param list<string> $unreadable @param list<string> $absent */
    private function status(OutboundChannel $channel, array $armed, array $unreadable, array $absent, bool $allowed): string
    {
        return match (true) {
            $armed !== [] && $allowed => CompatibilityCheck::INFO,
            $armed !== [] => in_array($channel->risk, [OutboundChannel::CRITICAL, OutboundChannel::HIGH], true) ? CompatibilityCheck::FAIL : CompatibilityCheck::WARN,
            $unreadable !== [] => CompatibilityCheck::WARN,
            count($absent) === count($channel->targets) => CompatibilityCheck::INFO,
            default => CompatibilityCheck::PASS,
        };
    }

    /** @param list<array{table: string, armed_rows: int}> $armed @param list<string> $unreadable @param list<string> $absent */
    private function detail(OutboundChannel $channel, array $armed, array $unreadable, array $absent, bool $allowed): string
    {
        $rows = implode(', ', array_map(static fn (array $entry): string => "{$entry['table']}: {$entry['armed_rows']}", $armed));

        return match (true) {
            $armed !== [] && $allowed => "still armed, and allowed by --allow-channel ({$rows})",
            $armed !== [] => "{$rows} row(s) can still act. {$channel->why}" . ($channel->manual !== null ? " Manual step: {$channel->manual}" : ' Run the sanitization plan.'),
            $unreadable !== [] => 'could not be evaluated on this database: ' . implode(', ', $unreadable) . '. Check it by hand.',
            count($absent) === count($channel->targets) => 'no table of this channel exists in this database, so it cannot be armed from here',
            default => 'nothing here can act' . ($absent === [] ? '' : ' (not present in this database: ' . implode('; ', $absent) . ')'),
        };
    }

    private function productionHostCheck(ReadOnlyProbe $probe, string $host): CompatibilityCheck
    {
        $pattern = '%' . $host . '%';
        $found = [];
        foreach (self::URL_COLUMNS as $table => $column) {
            if (!isset($probe->columns($table)[$column])) {
                continue;
            }

            $rows = $probe->count($table, static fn ($query) => $query->where($column, 'like', $pattern));
            if ($rows > 0) {
                $found[] = ['table' => $table, 'column' => $column, 'rows' => $rows];
            }
        }

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'the production host written into the data',
            status: $found === [] ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            detail: $found === []
                ? 'no stored text or URL contains the production host'
                : 'stored text contains the production host in ' . implode(', ', array_map(static fn (array $entry): string => "{$entry['table']}.{$entry['column']} ({$entry['rows']})", $found)) . ': a redirect or a link there sends staging visitors to production. Rewrite or disable them.',
            facts: ['rows_containing_the_host' => $found],
        );
    }
}
