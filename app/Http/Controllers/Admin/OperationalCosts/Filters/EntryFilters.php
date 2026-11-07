<?php

namespace App\Http\Controllers\Admin\OperationalCosts\Filters;

use App\Enums\OperationalCosts\CostClass;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Models\OperationalCostEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * What the cost entries page, and the exports of cost entries, are asked to show.
 *
 * The same filters drive both, so an export holds exactly the rows the page listed. Only effective entries are listed unless the history is asked for: an
 * estimate that the real charge replaced is still in the ledger, but it is no longer a cost.
 */
final readonly class EntryFilters
{
    public const VIEW_ALL = 'all';

    public const VIEW_MISSING = 'missing';

    /** The tabs of the entries page, in the order they are shown. */
    public const VIEWS = [self::VIEW_ALL, 'shipment', 'storage', 'marketplace', 'payment', self::VIEW_MISSING];

    /** The tabs of the entries page: the cost classes that have a tab of their own. */
    private const VIEW_CLASSES = [
        'shipment' => CostClass::Fulfillment,
        'storage' => CostClass::Storage,
        'marketplace' => CostClass::MarketplaceFee,
        'payment' => CostClass::PaymentFee,
    ];

    public function __construct(
        public string $view = self::VIEW_ALL,
        public ?CostComponent $component = null,
        public ?CostClass $costClass = null,
        public ?CostState $state = null,
        public ?string $provider = null,
        public ?string $salesChannel = null,
        public ?int $accountId = null,
        public ?string $subjectType = null,
        public ?string $missingReason = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?int $seller = SellerScope::PLATFORM,
        public ?string $search = null,
        public bool $includeHistory = false,
    ) {
    }

    /**
     * Without dates the entries of the current month are listed, except on the tab of missing costs, which is a work list and shows every one. A form that was
     * submitted with its dates emptied asks for no limit. An export of one tab names it as `$forcedView`.
     */
    public static function fromRequest(Request $request, ?string $forcedView = null): self
    {
        $view = $forcedView ?? QueryInput::text($request, 'view');
        $view = in_array($view, self::VIEWS, true) ? $view : self::VIEW_ALL;
        $datesSent = QueryInput::sent($request, 'from') || QueryInput::sent($request, 'to');
        $defaultPeriod = $datesSent || $view === self::VIEW_MISSING ? null : ReportPeriod::currentMonth();
        $subject = QueryInput::text($request, 'subject');

        return new self(
            view: $view,
            component: CostComponent::tryFrom((string) QueryInput::text($request, 'component')),
            costClass: CostClass::tryFrom((string) QueryInput::text($request, 'class')),
            state: $view === self::VIEW_MISSING ? null : CostState::tryFrom((string) QueryInput::text($request, 'state')),
            provider: QueryInput::text($request, 'provider'),
            salesChannel: SalesChannel::tryFrom((string) QueryInput::text($request, 'channel'))?->value,
            accountId: QueryInput::integer($request, 'account'),
            subjectType: in_array($subject, CostLabels::values('entry_subject'), true) ? $subject : null,
            missingReason: QueryInput::text($request, 'reason'),
            from: $datesSent ? QueryInput::date($request, 'from') : $defaultPeriod?->from,
            to: $datesSent ? QueryInput::date($request, 'to') : $defaultPeriod?->to,
            seller: SellerScope::resolve($request),
            search: QueryInput::text($request, 'q'),
            includeHistory: QueryInput::flag($request, 'history'),
        );
    }

    /** @return Builder<OperationalCostEntry> */
    public function query(): Builder
    {
        $query = OperationalCostEntry::query()
            ->when(!$this->includeHistory, static fn (Builder $query): Builder => $query->where('is_effective', true))
            ->when($this->view === self::VIEW_MISSING, static fn (Builder $query): Builder => $query->where('state', CostState::Missing->value))
            ->when(isset(self::VIEW_CLASSES[$this->view]), fn (Builder $query): Builder => $query->where('cost_class', self::VIEW_CLASSES[$this->view]->value))
            ->when($this->component !== null, fn (Builder $query): Builder => $query->where('cost_component', $this->component->value))
            ->when($this->costClass !== null, fn (Builder $query): Builder => $query->where('cost_class', $this->costClass->value))
            ->when($this->state !== null, fn (Builder $query): Builder => $query->where('state', $this->state->value))
            ->when($this->provider !== null, fn (Builder $query): Builder => $query->where('provider', $this->provider))
            ->when($this->salesChannel !== null, fn (Builder $query): Builder => $query->where('sales_channel', $this->salesChannel))
            ->when($this->accountId !== null, fn (Builder $query): Builder => $query->where('account_id', $this->accountId))
            ->when($this->subjectType !== null, fn (Builder $query): Builder => $query->where('subject_type', $this->subjectType))
            ->when($this->missingReason !== null, fn (Builder $query): Builder => $query->where('missing_reason', $this->missingReason))
            ->when($this->from !== null, fn (Builder $query): Builder => $query->whereDate('period_date', '>=', $this->from->toDateString()))
            ->when($this->to !== null, fn (Builder $query): Builder => $query->whereDate('period_date', '<=', $this->to->toDateString()))
            ->when($this->search !== null, fn (Builder $query): Builder => $query->where('external_reference', 'like', '%' . $this->search . '%'));

        return SellerScope::apply($query, $this->seller);
    }

    /**
     * The query-string form of these filters, for the links that keep them (the tabs, the pager). The dates are always spelled out, so that the month a first
     * visit defaulted to - or the "no limit" of an emptied form - stays what it was.
     *
     * @param array<string, mixed> $override
     * @return array<string, string>
     */
    public function parameters(array $override = []): array
    {
        $parameters = [
            'view' => $this->view,
            'component' => $this->component?->value,
            'class' => $this->costClass?->value,
            'state' => $this->state?->value,
            'provider' => $this->provider,
            'channel' => $this->salesChannel,
            'account' => $this->accountId,
            'subject' => $this->subjectType,
            'reason' => $this->missingReason,
            'from' => $this->from?->toDateString() ?? '',
            'to' => $this->to?->toDateString() ?? '',
            'seller' => SellerScope::parameter($this->seller),
            'q' => $this->search,
            'history' => $this->includeHistory ? '1' : null,
        ];

        return array_map('strval', array_filter($override + $parameters, static fn ($value): bool => $value !== null));
    }
}
