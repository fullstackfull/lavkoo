<?php

namespace App\Services\OperationalCosts;

use App\Enums\OperationalCosts\ContractStatus;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostContractRevision;
use App\Models\OperationalCostRule;
use App\Services\AuditLogger;
use App\Services\OperationalCosts\Calculation\RuleValidator;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates and changes contracts, and keeps their history honest.
 *
 * A contract is a draft while it is being typed in, and a draft can be edited freely. Activating it freezes it: from then on its rules
 * never change, and a new price list is a new VERSION with its own start date that takes over from that date. Costs that were already
 * computed keep pointing at the version they were computed under, so changing a contract never rewrites history. Every step leaves a
 * revision row (who, when, why, before, after) and an audit-log entry.
 */
class ContractService
{
    public const PARTY_TYPES = ['fulfillment_provider', 'carrier', 'marketplace', 'payment_gateway', 'supplier', 'other'];

    public function __construct(
        private readonly RuleValidator $validator,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $data party_type, provider_code, party_name, currency, effective_from, [effective_to, reference, notes, settings]
     * @throws InvalidContractChange
     */
    public function createDraft(array $data, ?int $adminId, int $sellerId = 0): OperationalCostContract
    {
        $clean = $this->contractAttributes($data);

        return DB::transaction(function () use ($clean, $adminId, $sellerId): OperationalCostContract {
            $contract = OperationalCostContract::create($clean + [
                'seller_id' => $sellerId,
                'version' => 1,
                'status' => ContractStatus::Draft,
                'created_by' => $adminId,
            ]);
            $contract->forceFill(['root_id' => $contract->id])->save();
            $this->revise($contract, 'created', null, $this->snapshot($contract), null, $adminId);

            return $contract;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidContractChange
     */
    public function updateDraft(OperationalCostContract $contract, array $data, ?int $adminId): OperationalCostContract
    {
        $this->assertDraft($contract);
        $before = $this->snapshot($contract);
        $contract->fill($this->contractAttributes($data + $contract->only(['party_type', 'provider_code', 'party_name', 'currency', 'reference', 'notes', 'settings'])
            + ['effective_from' => $contract->effective_from->toDateString(), 'effective_to' => $contract->effective_to?->toDateString()]))->save();
        $this->revise($contract, 'updated', $before, $this->snapshot($contract), null, $adminId);

        return $contract;
    }

    /**
     * @param array<string, mixed> $data service_type, calc_method, params, [label, conditions, currency, priority, valid_from, valid_to]
     * @throws InvalidContractChange
     */
    public function addRule(OperationalCostContract $contract, array $data, ?int $adminId): OperationalCostRule
    {
        $this->assertDraft($contract);
        $attributes = $this->ruleAttributes($contract, $data);
        $rule = $contract->rules()->create($attributes);
        $this->revise($contract, 'rule_added', null, $rule->only(['id', 'service_type', 'calc_method', 'params', 'conditions', 'priority']), null, $adminId);

        return $rule;
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidContractChange
     */
    public function updateRule(OperationalCostRule $rule, array $data, ?int $adminId): OperationalCostRule
    {
        $contract = $rule->contract()->firstOrFail();
        $this->assertDraft($contract);
        $before = $rule->only(['id', 'service_type', 'calc_method', 'params', 'conditions', 'priority']);
        $rule->fill($this->ruleAttributes($contract, $data + $rule->only(['service_type', 'calc_method', 'params', 'conditions', 'label', 'currency', 'priority', 'is_active'])
            + ['valid_from' => $rule->valid_from?->toDateString(), 'valid_to' => $rule->valid_to?->toDateString()]))->save();
        $this->revise($contract, 'rule_changed', $before, $rule->only(['id', 'service_type', 'calc_method', 'params', 'conditions', 'priority']), null, $adminId);

        return $rule;
    }

    /** @throws InvalidContractChange */
    public function removeRule(OperationalCostRule $rule, ?int $adminId): void
    {
        $contract = $rule->contract()->firstOrFail();
        $this->assertDraft($contract);
        $before = $rule->only(['id', 'service_type', 'calc_method', 'params', 'conditions', 'priority']);
        $rule->delete();
        $this->revise($contract, 'rule_removed', $before, null, null, $adminId);
    }

    /**
     * Puts a draft into force. Refused if it prices nothing, or if it would cover days that another contract of the same company and
     * currency already covers. A new version of a contract takes over from its start date: the version before it ends the day before.
     *
     * @throws InvalidContractChange
     */
    public function activate(OperationalCostContract $contract, ?int $adminId, string $reason): OperationalCostContract
    {
        $this->assertDraft($contract);
        if (trim($reason) === '') {
            throw new InvalidContractChange('Activating a contract needs a reason: it is kept in the contract history.');
        }
        if (!$contract->rules()->where('is_active', true)->exists()) {
            throw new InvalidContractChange('A contract without a single active price rule cannot be activated.');
        }

        return DB::transaction(function () use ($contract, $adminId, $reason): OperationalCostContract {
            $siblings = OperationalCostContract::query()
                ->inForce()
                ->where('seller_id', $contract->seller_id)
                ->where('provider_code', $contract->provider_code)
                ->where('currency', $contract->currency)
                ->lockForUpdate()
                ->get();

            foreach ($siblings as $other) {
                if (!$this->overlaps($contract, $other)) {
                    continue;
                }
                $isPredecessor = $other->root_id === $contract->root_id && $other->version < $contract->version;
                if (!$isPredecessor || $contract->effective_from->lte($other->effective_from)) {
                    throw new InvalidContractChange("The dates overlap contract #{$other->id} of the same company: end it, or make this a new version of it.");
                }
                $before = $this->snapshot($other);
                $other->forceFill([
                    'effective_to' => $contract->effective_from->subDay()->toDateString(),
                    'status' => ContractStatus::Superseded,
                ])->save();
                $this->revise($other, 'superseded', $before, $this->snapshot($other), "Superseded by version {$contract->version} from {$contract->effective_from->toDateString()}.", $adminId);
            }

            $before = $this->snapshot($contract);
            $contract->forceFill(['status' => ContractStatus::Active, 'activated_at' => now(), 'activated_by' => $adminId])->save();
            $this->revise($contract, 'activated', $before, $this->snapshot($contract), $reason, $adminId);

            return $contract;
        });
    }

    /**
     * Starts a new version of a contract as a draft holding a copy of the latest version's rules, ready to be edited. The version
     * before it is untouched until this one is activated.
     *
     * @throws InvalidContractChange
     */
    public function startNewVersion(OperationalCostContract $latest, string $effectiveFrom, ?int $adminId, string $reason): OperationalCostContract
    {
        $latestOfRoot = OperationalCostContract::where('root_id', $latest->root_id)->orderByDesc('version')->firstOrFail();
        if ($latestOfRoot->status === ContractStatus::Draft) {
            throw new InvalidContractChange('This contract already has a draft version: finish that one first.');
        }
        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();
        if ($from->lte($latestOfRoot->effective_from)) {
            throw new InvalidContractChange('A new version must start after the version it replaces.');
        }

        return DB::transaction(function () use ($latestOfRoot, $from, $adminId, $reason): OperationalCostContract {
            $draft = OperationalCostContract::create([
                'seller_id' => $latestOfRoot->seller_id,
                'party_type' => $latestOfRoot->party_type,
                'provider_code' => $latestOfRoot->provider_code,
                'party_name' => $latestOfRoot->party_name,
                'reference' => $latestOfRoot->reference,
                'root_id' => $latestOfRoot->root_id,
                'version' => $latestOfRoot->version + 1,
                'currency' => $latestOfRoot->currency,
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'status' => ContractStatus::Draft,
                'notes' => $latestOfRoot->notes,
                'settings' => $latestOfRoot->settings,
                'created_by' => $adminId,
            ]);
            foreach ($latestOfRoot->rules()->get() as $rule) {
                $draft->rules()->create($rule->only(['service_type', 'label', 'calc_method', 'params', 'conditions', 'currency', 'priority', 'is_active', 'valid_from', 'valid_to']));
            }
            $this->revise($draft, 'created', null, $this->snapshot($draft), "New version of #{$latestOfRoot->id}: {$reason}", $adminId);

            return $draft;
        });
    }

    /** @throws InvalidContractChange */
    public function archive(OperationalCostContract $contract, ?int $adminId, string $reason): OperationalCostContract
    {
        if ($contract->status !== ContractStatus::Draft) {
            throw new InvalidContractChange('Only a draft can be archived. An active contract ends by a successor taking over, or by its end date.');
        }
        $before = $this->snapshot($contract);
        $contract->forceFill(['status' => ContractStatus::Archived])->save();
        $this->revise($contract, 'archived', $before, $this->snapshot($contract), $reason, $adminId);

        return $contract;
    }

    /**
     * The contract that applies to a service of a company on a day. When a company has contracts in several currencies for the same
     * day the caller names the one it wants; otherwise the newest version covering the day is returned.
     */
    public function contractFor(string $providerCode, DateTimeInterface $on, int $sellerId = 0, ?string $currency = null): ?OperationalCostContract
    {
        $query = OperationalCostContract::query()
            ->inForce()
            ->where('seller_id', $sellerId)
            ->where('provider_code', $providerCode)
            ->whereDate('effective_from', '<=', $on->format('Y-m-d'))
            ->where(static function ($query) use ($on): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on->format('Y-m-d'));
            });
        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        return $query->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    private function assertDraft(OperationalCostContract $contract): void
    {
        if ($contract->status !== ContractStatus::Draft) {
            throw new InvalidContractChange('This contract is in force and cannot be edited: start a new version instead.');
        }
    }

    private function overlaps(OperationalCostContract $a, OperationalCostContract $b): bool
    {
        $aEnd = $a->effective_to?->toDateString() ?? '9999-12-31';
        $bEnd = $b->effective_to?->toDateString() ?? '9999-12-31';

        return $a->effective_from->toDateString() <= $bEnd && $b->effective_from->toDateString() <= $aEnd;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function contractAttributes(array $data): array
    {
        $partyType = (string) ($data['party_type'] ?? '');
        if (!in_array($partyType, self::PARTY_TYPES, true)) {
            throw new InvalidContractChange('Unknown kind of company.');
        }
        $providerCode = trim((string) ($data['provider_code'] ?? ''));
        $partyName = trim((string) ($data['party_name'] ?? ''));
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if ($providerCode === '' || $partyName === '' || preg_match('/^[A-Z]{3,8}$/', $currency) !== 1) {
            throw new InvalidContractChange('The company, its name and a currency are required.');
        }
        try {
            $from = CarbonImmutable::parse((string) ($data['effective_from'] ?? ''))->startOfDay();
            $to = ($data['effective_to'] ?? null) === null || $data['effective_to'] === '' ? null : CarbonImmutable::parse((string) $data['effective_to'])->startOfDay();
        } catch (\Throwable) {
            throw new InvalidContractChange('The start and end dates must be dates.');
        }
        if ($to !== null && $to->lt($from)) {
            throw new InvalidContractChange('The contract ends before it starts.');
        }

        return [
            'party_type' => $partyType,
            'provider_code' => $providerCode,
            'party_name' => $partyName,
            'reference' => ($data['reference'] ?? null) === null ? null : mb_substr(trim((string) $data['reference']), 0, 120),
            'currency' => $currency,
            'effective_from' => $from->toDateString(),
            'effective_to' => $to?->toDateString(),
            'notes' => ($data['notes'] ?? null) === null ? null : (string) $data['notes'],
            'settings' => $this->settings($data['settings'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     * @throws InvalidContractChange
     */
    private function settings(mixed $settings): ?array
    {
        if ($settings === null || $settings === []) {
            return null;
        }
        if (!is_array($settings)) {
            throw new InvalidContractChange('The contract settings are malformed.');
        }
        $clean = [];
        if (isset($settings['expected_components'])) {
            $clean['expected_components'] = array_values(array_unique(array_map('strval', (array) $settings['expected_components'])));
        }
        if (isset($settings['allocation_basis'])) {
            foreach ((array) $settings['allocation_basis'] as $component => $basis) {
                if (!in_array($basis, ['quantity', 'weight', 'value', 'equal'], true)) {
                    throw new InvalidContractChange("Unknown allocation basis '{$basis}'.");
                }
                $clean['allocation_basis'][(string) $component] = $basis;
            }
        }
        if (isset($settings['tolerance_percent']) && $settings['tolerance_percent'] !== '') {
            if (!is_numeric($settings['tolerance_percent']) || (float) $settings['tolerance_percent'] < 0) {
                throw new InvalidContractChange('The reconciliation tolerance must be a percentage.');
            }
            $clean['tolerance_percent'] = (string) $settings['tolerance_percent'];
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function ruleAttributes(OperationalCostContract $contract, array $data): array
    {
        $params = $this->validator->validate(
            serviceType: (string) ($data['service_type'] ?? ''),
            calcMethod: (string) ($data['calc_method'] ?? ''),
            params: (array) ($data['params'] ?? []),
            conditions: isset($data['conditions']) && is_array($data['conditions']) && $data['conditions'] !== [] ? $data['conditions'] : null,
        );
        $currency = strtoupper(trim((string) ($data['currency'] ?? ''))) ?: null;
        if ($currency !== null && $currency !== $contract->currency) {
            throw new InvalidContractChange('All prices of a contract are in the contract currency.');
        }

        return [
            'service_type' => (string) $data['service_type'],
            'label' => ($data['label'] ?? null) === null || $data['label'] === '' ? null : mb_substr((string) $data['label'], 0, 160),
            'calc_method' => (string) $data['calc_method'],
            'params' => $params,
            'conditions' => isset($data['conditions']) && is_array($data['conditions']) && $data['conditions'] !== [] ? $data['conditions'] : null,
            'currency' => $contract->currency,
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'valid_from' => ($data['valid_from'] ?? null) ?: null,
            'valid_to' => ($data['valid_to'] ?? null) ?: null,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(OperationalCostContract $contract): array
    {
        return $contract->only(['id', 'root_id', 'version', 'party_type', 'provider_code', 'party_name', 'reference', 'currency', 'status', 'notes', 'settings'])
            + [
                'effective_from' => $contract->effective_from?->toDateString(),
                'effective_to' => $contract->effective_to?->toDateString(),
                'rules' => $contract->rules()->get()->map(static fn (OperationalCostRule $rule): array => $rule->only(['id', 'service_type', 'calc_method', 'params', 'conditions', 'priority', 'is_active']))->all(),
            ];
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function revise(OperationalCostContract $contract, string $action, ?array $before, ?array $after, ?string $reason, ?int $adminId): void
    {
        OperationalCostContractRevision::create([
            'contract_id' => $contract->id,
            'action' => $action,
            'actor_type' => $adminId === null ? 'system' : 'admin',
            'actor_id' => $adminId,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
        $this->audit->record(action: 'operational_cost.contract_' . $action, subject: $contract, context: ['contract_id' => $contract->id, 'reason' => $reason]);
    }
}
