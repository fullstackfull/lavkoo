<?php

namespace App\Http\Controllers\Admin\OperationalCosts\Filters;

use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * What the invoices page, and the export of invoices, are asked to show. There is no default period: an invoice nobody has approved yet is work to do,
 * however old it is.
 */
final readonly class InvoiceFilters
{
    /** The status filter that means "not approved yet": a draft, or an invoice held for review. */
    public const UNAPPROVED = 'unapproved';

    public const STATUSES = [
        self::UNAPPROVED,
        OperationalCostInvoice::STATUS_DRAFT, OperationalCostInvoice::STATUS_UNDER_REVIEW, OperationalCostInvoice::STATUS_APPROVED,
        OperationalCostInvoice::STATUS_DISPUTED, OperationalCostInvoice::STATUS_VOID,
    ];

    public function __construct(
        public ?string $provider = null,
        public ?string $status = null,
        public ?string $kind = null,
        public bool $possibleDuplicates = false,
        public bool $openDifferences = false,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?int $seller = SellerScope::PLATFORM,
        public ?string $search = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $status = QueryInput::text($request, 'status');
        $kind = QueryInput::text($request, 'kind');

        return new self(
            provider: QueryInput::text($request, 'provider'),
            status: in_array($status, self::STATUSES, true) ? $status : null,
            kind: in_array($kind, InvoiceService::KINDS, true) ? $kind : null,
            possibleDuplicates: QueryInput::flag($request, 'duplicate'),
            openDifferences: QueryInput::flag($request, 'differences'),
            from: QueryInput::date($request, 'from'),
            to: QueryInput::date($request, 'to'),
            seller: SellerScope::resolve($request),
            search: QueryInput::text($request, 'q'),
        );
    }

    /** @return Builder<OperationalCostInvoice> */
    public function query(): Builder
    {
        $query = OperationalCostInvoice::query()
            ->when($this->provider !== null, fn (Builder $query): Builder => $query->where('provider_code', $this->provider))
            ->when($this->status === self::UNAPPROVED, static fn (Builder $query): Builder => $query->whereIn('status', [OperationalCostInvoice::STATUS_DRAFT, OperationalCostInvoice::STATUS_UNDER_REVIEW]))
            ->when($this->status !== null && $this->status !== self::UNAPPROVED, fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->kind !== null, fn (Builder $query): Builder => $query->where('kind', $this->kind))
            ->when($this->possibleDuplicates, static fn (Builder $query): Builder => $query->whereNotNull('duplicate_of_id'))
            ->when($this->openDifferences, static fn (Builder $query): Builder => $query->whereIn('id', OperationalCostReconciliation::query()
                ->whereIn('status', [OperationalCostReconciliation::VARIANCE, OperationalCostReconciliation::UNMATCHED_INVOICE])
                ->select('invoice_id')))
            ->when($this->from !== null, fn (Builder $query): Builder => $query->whereDate('invoice_date', '>=', $this->from->toDateString()))
            ->when($this->to !== null, fn (Builder $query): Builder => $query->whereDate('invoice_date', '<=', $this->to->toDateString()))
            ->when($this->search !== null, fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('invoice_number', 'like', '%' . $this->search . '%')->orWhere('party_name', 'like', '%' . $this->search . '%')));

        return SellerScope::apply($query, $this->seller);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, string>
     */
    public function parameters(array $override = []): array
    {
        $parameters = [
            'provider' => $this->provider,
            'status' => $this->status,
            'kind' => $this->kind,
            'duplicate' => $this->possibleDuplicates ? '1' : null,
            'differences' => $this->openDifferences ? '1' : null,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'seller' => SellerScope::parameter($this->seller),
            'q' => $this->search,
        ];

        return array_map('strval', array_filter($override + $parameters, static fn ($value): bool => $value !== null));
    }
}
