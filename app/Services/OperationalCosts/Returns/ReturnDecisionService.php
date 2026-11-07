<?php

namespace App\Services\OperationalCosts\Returns;

use App\Models\ExternalOrderLine;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\OperationalCostReturnDecision;
use App\Services\AuditLogger;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Sales\SaleLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What becomes of the units of a channel order line that came back: each unit is put back on the shelf at the cost it left at (a new cost layer, and that part of the line's
 * cost of goods reversed in the ledger), or written off as damaged (no layer, and the cost stays in the profit). A deliberate decision, made once per line and return event.
 *
 * A return event is a claim the channel accepted (`claim:<id>`, with the units the channel says came back, never more than were sold: all of them are decided together) or, for a
 * line the channel reports no returned units for (Amazon), a return a person recorded. Deciding the same event again changes nothing.
 *
 * A claim can GROW after it was decided (the channel accepts a second item of it later): the units it gained are decided as a follow-up, `claim:<id>+<units decided so far>`,
 * so each growth step has an event of its own and a repeated submit of it is still one decision. A recorded return is `manual:<units decided so far on the line>`: the position
 * is the same for every form opened at the same moment, so two people recording the one parcel that came back decide it once, and a form opened before another decision was
 * made does nothing (it finds its event decided) or is refused as stale.
 *
 * The caps are the ones that keep the stock and the profit honest: a claim is decided for exactly the units it has not had decided; a recorded return never exceeds what was
 * sold less what was already decided; and units put back never exceed what was taken from the cost layers for the line, over all of its returns. Every decision is audited, with who made it, or that it
 * was automatic. Writing a decision needs no switch (a person is deciding), but putting units back needs a cost of goods that was recognised: with OPERATIONAL_COSTS_ENABLED
 * off none was, and there is nothing to put back.
 */
class ReturnDecisionService
{
    public const CLAIM_PREFIX = 'claim:';
    public const MANUAL_PREFIX = 'manual:';

    public function __construct(
        private readonly SaleDocumentReader $documents,
        private readonly SaleCostService $sales,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @throws InvalidReturnDecision
     */
    public function decide(ExternalOrderLine $line, string $returnEvent, int $restock, int $writeOff, ?int $locationId, ?int $adminId, ?string $note = null, bool $automatic = false): ReturnDecisionResult
    {
        if ($restock < 0 || $writeOff < 0 || $restock + $writeOff < 1) {
            throw new InvalidReturnDecision(InvalidReturnDecision::NOTHING_TO_DECIDE);
        }
        if (mb_strlen($returnEvent) > OperationalCostReturnDecision::RETURN_EVENT_LENGTH) {
            throw new InvalidReturnDecision(InvalidReturnDecision::UNKNOWN_RETURN_EVENT);
        }

        $result = DB::transaction(function () use ($line, $returnEvent, $restock, $writeOff, $locationId, $adminId, $note, $automatic): ReturnDecisionResult {
            $locked = ExternalOrderLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $existing = OperationalCostReturnDecision::where('target_type', OperationalCostReturnDecision::TARGET_CHANNEL_ORDER_LINE)->where('target_id', $locked->id)->where('return_event', $returnEvent)->first();
            if ($existing !== null) {
                return new ReturnDecisionResult($existing, alreadyDecided: true);
            }

            $document = $this->documents->forChannelOrder((int) $locked->mapping_id) ?? throw new InvalidReturnDecision(InvalidReturnDecision::UNKNOWN_RETURN_EVENT);
            $saleLine = $this->saleLineOf($document->lines, $locked);
            $decisions = $locked->returnDecisions()->orderBy('id')->get();
            $this->checkTheUnits($locked, $saleLine, $returnEvent, $restock + $writeOff, $decisions);

            $layerId = null;
            $entryId = null;
            if ($restock > 0) {
                $locationId = $this->checkTheRestock($locked, $saleLine, $restock, $locationId, $document->sellerId, (int) $decisions->sum('units_restocked'));
                $entryId = $this->sales->restock($document, $saleLine, $restock, $returnEvent, $locationId, alreadyRestocked: (int) $decisions->sum('units_restocked'))?->entry->id;
                $layerId = InventoryCostLayer::where('source_type', 'return')->where('source_id', $locked->id)->where('source_ref', $returnEvent)->value('id');
            }

            $decision = OperationalCostReturnDecision::create([
                'seller_id' => $document->sellerId,
                'target_type' => OperationalCostReturnDecision::TARGET_CHANNEL_ORDER_LINE,
                'target_id' => $locked->id,
                'return_event' => $returnEvent,
                'units_restocked' => $restock,
                'units_written_off' => $writeOff,
                'inventory_location_id' => $restock > 0 ? $locationId : null,
                'layer_id' => $layerId,
                'entry_id' => $entryId,
                'automatic' => $automatic,
                'decided_by' => $adminId,
                'note' => $note === null ? null : mb_substr($note, 0, 500),
                'decided_at' => now(),
            ]);

            return new ReturnDecisionResult($decision, alreadyDecided: false);
        }, attempts: 3);

        if (!$result->alreadyDecided) {
            $this->audit->record(
                action: 'operational_cost.return_decided',
                subject: $line,
                context: [
                    'line_id' => (int) $line->id, 'return_event' => $returnEvent, 'restocked' => $restock, 'written_off' => $writeOff, 'location_id' => $result->decision->inventory_location_id,
                    'automatic' => $automatic, 'by' => $adminId,
                ],
            );
        }

        return $result;
    }

    /**
     * What a line's returns stand at: the claims (or the units of a claim) nobody decided about (event => units), the decisions made, the units decided so far (the position a
     * recorded return is made at), and whether a person may record a return of their own for it.
     *
     * @return array{awaiting: array<string, int>, decisions: Collection<int, OperationalCostReturnDecision>, decidedUnits: int, canRecordAReturn: bool, restockable: int}
     */
    public function standing(ExternalOrderLine $line): array
    {
        $decisions = $line->returnDecisions()->orderBy('id')->get();

        return [
            'awaiting' => $this->awaitingClaims($line, $decisions),
            'decisions' => $decisions,
            'decidedUnits' => $this->decidedUnits($decisions),
            'canRecordAReturn' => $line->quantity_returned < 1 && $line->product_id !== null,
            'restockable' => max(0, $this->takenFromStock($line) - (int) $decisions->sum('units_restocked')),
        ];
    }

    /**
     * The next decision a claim waits for: its event and the units, or null when everything the channel accepted of it has been decided. A claim that grew since it was decided
     * waits for a follow-up decision about the units it gained.
     *
     * @return array{event: string, units: int}|null
     */
    public function nextClaimDecision(ExternalOrderLine $line, string $claimId): ?array
    {
        foreach ($this->awaitingClaims($line, $line->returnDecisions()->orderBy('id')->get()) as $event => $units) {
            if ($this->belongsToClaim($event, $claimId)) {
                return ['event' => $event, 'units' => $units];
            }
        }

        return null;
    }

    /**
     * The units of each claim of the line that nobody decided about, by the event that decides them: never more than the channel accepted of the claim, and never more than
     * the units of the line that are still undecided.
     *
     * @param Collection<int, OperationalCostReturnDecision> $decisions
     * @return array<string, int>
     */
    private function awaitingClaims(ExternalOrderLine $line, Collection $decisions): array
    {
        $undecided = $line->activeQuantity() - $this->decidedUnits($decisions);
        $awaiting = [];
        foreach ((array) ($line->snapshot['returned_by_claim'] ?? []) as $claimId => $accepted) {
            $decidedOfTheClaim = (int) $decisions->filter(fn (OperationalCostReturnDecision $decision): bool => $this->belongsToClaim($decision->return_event, (string) $claimId))
                ->sum(static fn (OperationalCostReturnDecision $decision): int => $decision->units());
            $units = min((int) $accepted - $decidedOfTheClaim, $undecided);
            if ($units > 0) {
                $awaiting[self::claimEvent((string) $claimId, $decidedOfTheClaim)] = $units;
                $undecided -= $units;
            }
        }

        return $awaiting;
    }

    /** The event that decides a claim: the claim itself the first time, and `claim:<id>+<units decided so far>` for the units it gained since. */
    private static function claimEvent(string $claimId, int $decidedSoFar): string
    {
        return self::CLAIM_PREFIX . $claimId . ($decidedSoFar > 0 ? '+' . $decidedSoFar : '');
    }

    private function belongsToClaim(string $event, string $claimId): bool
    {
        $own = self::CLAIM_PREFIX . $claimId;

        return $event === $own || (str_starts_with($event, $own . '+') && ctype_digit(substr($event, strlen($own) + 1)));
    }

    /** @param Collection<int, OperationalCostReturnDecision> $decisions */
    private function decidedUnits(Collection $decisions): int
    {
        return (int) $decisions->sum(static fn (OperationalCostReturnDecision $decision): int => $decision->units());
    }

    /** @param list<SaleLine> $lines */
    private function saleLineOf(array $lines, ExternalOrderLine $line): SaleLine
    {
        foreach ($lines as $saleLine) {
            if ($saleLine->targetId === (int) $line->id) {
                return $saleLine;
            }
        }

        throw new InvalidReturnDecision(InvalidReturnDecision::UNKNOWN_RETURN_EVENT);
    }

    /** @param Collection<int, OperationalCostReturnDecision> $decisions */
    private function checkTheUnits(ExternalOrderLine $line, SaleLine $saleLine, string $returnEvent, int $units, Collection $decisions): void
    {
        if (str_starts_with($returnEvent, self::CLAIM_PREFIX)) {
            $decidable = $this->awaitingClaims($line, $decisions)[$returnEvent] ?? 0;
            if ($decidable < 1) {
                throw new InvalidReturnDecision(InvalidReturnDecision::UNKNOWN_RETURN_EVENT);
            }
            if ($units !== $decidable) {
                throw new InvalidReturnDecision(InvalidReturnDecision::CLAIM_UNITS_MUST_ALL_BE_DECIDED);
            }

            return;
        }
        $position = str_starts_with($returnEvent, self::MANUAL_PREFIX) ? substr($returnEvent, strlen(self::MANUAL_PREFIX)) : '';
        if ($position === '' || !ctype_digit($position)) {
            throw new InvalidReturnDecision(InvalidReturnDecision::UNKNOWN_RETURN_EVENT);
        }
        if ($line->quantity_returned > 0) {
            throw new InvalidReturnDecision(InvalidReturnDecision::USE_THE_CLAIM);
        }
        $decided = $this->decidedUnits($decisions);
        if ((int) $position !== $decided) {
            throw new InvalidReturnDecision(InvalidReturnDecision::STALE_FORM);
        }
        if ($units > $saleLine->quantity - $decided) {
            throw new InvalidReturnDecision(InvalidReturnDecision::MORE_THAN_SOLD);
        }
    }

    /** @return int the location the units go to */
    private function checkTheRestock(ExternalOrderLine $line, SaleLine $saleLine, int $restock, ?int $locationId, int $sellerId, int $alreadyRestocked): int
    {
        if ($saleLine->productId === null) {
            throw new InvalidReturnDecision(InvalidReturnDecision::NOT_LINKED);
        }
        if ($locationId === null) {
            throw new InvalidReturnDecision(InvalidReturnDecision::NO_LOCATION);
        }
        if (!InventoryLocation::whereKey($locationId)->where('is_active', true)->where('seller_id', $sellerId)->exists()) {
            throw new InvalidReturnDecision(InvalidReturnDecision::LOCATION_UNKNOWN);
        }
        $taken = $this->takenFromStock($line);
        if ($taken < 1) {
            throw new InvalidReturnDecision(InvalidReturnDecision::NO_COST_RECOGNISED);
        }
        if ($restock > $taken - $alreadyRestocked) {
            throw new InvalidReturnDecision(InvalidReturnDecision::MORE_THAN_TAKEN_FROM_STOCK);
        }

        return $locationId;
    }

    /** The units of the line that were taken from the cost layers when its cost of goods was recognised (and not given back since). */
    private function takenFromStock(ExternalOrderLine $line): int
    {
        return (int) InventoryCostConsumption::where('consumer_type', 'channel_order_line')->where('consumer_id', $line->id)->whereNull('reversed_at')->sum('quantity');
    }
}
