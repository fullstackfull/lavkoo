<?php

namespace App\Http\Controllers\Admin\OperationalCosts\Filters;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\SalesChannel;
use App\Services\OperationalCosts\Reports\ProfitFilters;
use App\Services\OperationalCosts\Reports\ProfitReportBuilder;
use Illuminate\Http\Request;

/** What the profitability page, and the export of it, are asked to show: a date range (the current month unless given), one dimension, and the optional filters. */
final readonly class ProfitInput
{
    public const SORT_REVENUE = 'revenue';

    public const SORT_ESTIMATED_PROFIT = 'estimated_profit';

    public const SORT_ACTUAL_PROFIT = 'actual_profit';

    public const SORTS = [self::SORT_REVENUE, self::SORT_ESTIMATED_PROFIT, self::SORT_ACTUAL_PROFIT];

    public function __construct(
        public ReportPeriod $period,
        public string $dimension,
        public ?string $channel,
        public ?int $accountId,
        public ?string $provider,
        public ?int $seller,
        public string $sort,
        public bool $ascending,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $dimension = QueryInput::text($request, 'dimension');
        $sort = QueryInput::text($request, 'sort');
        $provider = QueryInput::text($request, 'provider');

        return new self(
            period: ReportPeriod::fromRequest($request),
            dimension: in_array($dimension, ProfitReportBuilder::DIMENSIONS, true) ? $dimension : ProfitReportBuilder::DIMENSION_PRODUCT,
            channel: SalesChannel::tryFrom((string) QueryInput::text($request, 'channel'))?->value,
            accountId: QueryInput::integer($request, 'account'),
            provider: in_array($provider, [...ProviderCode::values(), ProfitFilters::NO_PROVIDER], true) ? $provider : null,
            seller: SellerScope::resolve($request),
            sort: in_array($sort, self::SORTS, true) ? $sort : self::SORT_REVENUE,
            ascending: $request->query('direction') === 'asc',
        );
    }

    public function filters(): ProfitFilters
    {
        return new ProfitFilters($this->period->from, $this->period->to, $this->channel, $this->accountId, $this->provider, $this->seller);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, string>
     */
    public function parameters(array $override = []): array
    {
        $parameters = [
            'from' => $this->period->from->toDateString(),
            'to' => $this->period->to->toDateString(),
            'dimension' => $this->dimension,
            'channel' => $this->channel,
            'account' => $this->accountId,
            'provider' => $this->provider,
            'seller' => SellerScope::parameter($this->seller),
            'sort' => $this->sort,
            'direction' => $this->ascending ? 'asc' : 'desc',
        ];

        return array_map('strval', array_filter($override + $parameters, static fn ($value): bool => $value !== null));
    }
}
