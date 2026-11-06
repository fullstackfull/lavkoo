<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\Admin;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lays an account's capabilities out as the ladder an administrator climbs, and says for each step what stands in the way.
 *
 * The blockers repeat the checks of ChannelAccountService::enableCapability, in the same order, so that a step is not offered when the service would refuse it; the service
 * stays the authority (a refusal it still gives is shown as it is worded), and a test compares the two.
 */
final class CapabilityLadder
{
    /** The order the steps are climbed in: a step needs the ones before it that it requires. */
    private const ORDER = [
        IntegrationCapability::ConnectionTest,
        IntegrationCapability::ReadOnlySync,
        IntegrationCapability::ProductSync,
        IntegrationCapability::OrderImport,
        IntegrationCapability::FinancialReconciliation,
        IntegrationCapability::InventoryPublishing,
        IntegrationCapability::PricePublishing,
        IntegrationCapability::FulfillmentDispatch,
    ];

    /** @return list<LadderRung> */
    public function rungs(SalesChannelAccount $account): array
    {
        $held = array_values(array_map('strval', (array) ($account->capabilities ?? [])));
        $approvals = (array) ($account->capability_approvals ?? []);
        $names = $this->adminNames($approvals);

        return array_map(function (IntegrationCapability $capability) use ($account, $held, $approvals, $names): LadderRung {
            [$blocker, $detail] = $this->blocker($account, $capability, $held);

            return new LadderRung(
                capability: $capability,
                held: in_array($capability->value, $held, true),
                requires: array_map(static fn (IntegrationCapability $prerequisite): array => ['capability' => $prerequisite, 'held' => in_array($prerequisite->value, $held, true)], $capability->requires()),
                blocker: $blocker,
                blockerDetail: $detail,
                approval: $capability->needsApproval() ? $this->recorded($approvals[$capability->value] ?? null, $names) : null,
                dependents: $this->dependentsOf($capability),
            );
        }, self::ORDER);
    }

    /** @return list<string> the kinds of write a person approves: the write capabilities and the extra scopes */
    public static function approvalScopes(): array
    {
        $capabilities = array_map(
            static fn (IntegrationCapability $capability): string => $capability->value,
            array_values(array_filter(IntegrationCapability::cases(), static fn (IntegrationCapability $capability): bool => $capability->needsApproval())),
        );

        return [...ChannelAccountService::EXTRA_SCOPES, ...$capabilities];
    }

    /**
     * The approvals recorded for the extra scopes (product writes, webhook management, notification management), with who gave them.
     *
     * @return array<string, array{by: string|null, at: CarbonInterface|null, reason: string|null}|null> scope => approval, null when none is on record
     */
    public function extraScopeApprovals(SalesChannelAccount $account): array
    {
        $approvals = (array) ($account->capability_approvals ?? []);
        $names = $this->adminNames($approvals);

        return collect(ChannelAccountService::EXTRA_SCOPES)->mapWithKeys(fn (string $scope): array => [$scope => $this->recorded($approvals[$scope] ?? null, $names)])->all();
    }

    /**
     * @param list<string> $held
     * @return array{0: string|null, 1: string|null} the blocker's code and its detail
     */
    private function blocker(SalesChannelAccount $account, IntegrationCapability $capability, array $held): array
    {
        foreach ($capability->requires() as $prerequisite) {
            if (!in_array($prerequisite->value, $held, true)) {
                return ['requires', translate($prerequisite->labelKey())];
            }
        }
        if ($capability !== IntegrationCapability::ConnectionTest && !$account->connectionStatus()->isUsable()) {
            return ['connection_not_proven', null];
        }
        if ($capability->needsApproval()) {
            $checkedAt = $account->connection_checked_at;
            if ($checkedAt === null || $checkedAt->lt(CarbonImmutable::now()->subDays(ChannelAccountService::CONNECTION_FRESH_DAYS))) {
                return ['connection_stale', (string) ChannelAccountService::CONNECTION_FRESH_DAYS];
            }
            if ($account->missing_permissions !== null && $account->missing_permissions !== []) {
                return ['missing_permissions', implode(', ', $account->missing_permissions)];
            }
        }

        return [null, null];
    }

    /**
     * @param array<string, mixed> $approvals
     * @return Collection<int, string> administrator id => name
     */
    private function adminNames(array $approvals): Collection
    {
        return Admin::whereIn('id', collect($approvals)->pluck('by')->filter()->unique()->all())->pluck('name', 'id');
    }

    /**
     * @param Collection<int, string> $names
     * @return array{by: string|null, at: CarbonInterface|null, reason: string|null}|null
     */
    private function recorded(mixed $approval, Collection $names): ?array
    {
        if (!is_array($approval) || $approval === []) {
            return null;
        }

        return [
            'by' => isset($approval['by']) ? (string) ($names->get($approval['by']) ?? '#' . $approval['by']) : null,
            'at' => isset($approval['at']) ? CarbonImmutable::parse($approval['at']) : null,
            'reason' => isset($approval['reason']) ? (string) $approval['reason'] : null,
        ];
    }

    /** @return list<IntegrationCapability> every step that needs this one, directly or through another: they go off with it */
    public function dependentsOf(IntegrationCapability $capability): array
    {
        $off = [$capability->value];
        do {
            $grew = false;
            foreach (self::ORDER as $candidate) {
                $needs = array_map(static fn (IntegrationCapability $item): string => $item->value, $candidate->requires());
                if (!in_array($candidate->value, $off, true) && array_intersect($needs, $off) !== []) {
                    $off[] = $candidate->value;
                    $grew = true;
                }
            }
        } while ($grew);

        return array_values(array_filter(self::ORDER, static fn (IntegrationCapability $candidate): bool => $candidate !== $capability && in_array($candidate->value, $off, true)));
    }
}
