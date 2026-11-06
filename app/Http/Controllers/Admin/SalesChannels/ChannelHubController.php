<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\Support\HubSummary;
use App\Http\Controllers\Controller;
use App\Models\SalesChannelAccount;
use Illuminate\View\View;

/** The hub: the switches that govern every channel call, one card per connected account, and what needs a person. */
class ChannelHubController extends Controller
{
    public function __construct(
        private readonly HubSummary $summary,
        private readonly ChannelLabels $labels,
    ) {
    }

    public function index(): View
    {
        $accounts = SalesChannelAccount::orderBy('id')->get();

        return view('admin-views.sales-channels.index', [
            'labels' => $this->labels,
            'globalEnabled' => (bool) config('sales_channels.enabled'),
            'externalWritesEnabled' => (bool) config('sales_channels.external_writes_enabled'),
            'managedDispatchEnabled' => (bool) config('sales_channels.managed_dispatch_enabled'),
            'cards' => $this->summary->cards($accounts),
            'attention' => $this->summary->attention($accounts),
        ]);
    }
}
