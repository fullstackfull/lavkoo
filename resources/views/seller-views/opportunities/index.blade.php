@extends('layouts.seller.app')

@section('title', translate('nav_opportunities'))

@php
    use App\Services\SellerCenter\Copy;
    use App\Services\SellerCenter\Shell;
@endphp

@section('content')
    {{-- Opportunities are improvements, issues are problems. Nothing on this screen carries a
         severity colour, a due date or an escalation (handoff 08 A5). --}}
    <x-sc.page-header :eyebrow="translate('nav_growth')" :title="translate('nav_opportunities')"
                      :sub="Copy::line('detected_from_the_last_n_days_of_your_own_shop_data', ['days' => $windowDays])">
        <x-slot:actions>
            @if ($issuesUrl = Shell::route('seller.issues.index'))
                <x-sc.button variant="secondary" icon="warning" :href="$issuesUrl">{{ translate('nav_issue_center') }}</x-sc.button>
            @endif
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @if ($state === 'empty')
                {{-- Nothing found is a real answer, not a failure — and it says what was looked at,
                     so a seller knows the screen ran rather than broke. --}}
                <x-sc.empty glyph="lightbulb" :title="translate('no_opportunities_detected')"
                            :text="Copy::line('nothing_in_the_last_n_days_of_views_sales_and_prices_suggests_a_change_worth_making', ['days' => $windowDays])" />
            @else
                <div class="sc-grid-cards">
                    @foreach ($opportunities as $opportunity)
                        <x-sc.card :title="$opportunity['title']">
                            <div class="sc-num" style="font-size:20px;font-family:var(--font-heading);color:var(--color-accent)">
                                {{ Copy::choice('one_product', 'n_products', $opportunity['count']) }}
                            </div>
                            {{-- The evidence sentence is not optional: a card that says only "you
                                 could do better" is an advertisement. --}}
                            <p class="sc-dim" style="font-size:12px;margin:6px 0 12px">{{ $opportunity['evidence'] }}</p>

                            @if ($opportunity['action'] && $opportunity['action']['href'])
                                <x-sc.button variant="secondary" size="sm" :href="$opportunity['action']['href']">
                                    {{ $opportunity['action']['label'] }}
                                </x-sc.button>
                            @endif
                        </x-sc.card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
