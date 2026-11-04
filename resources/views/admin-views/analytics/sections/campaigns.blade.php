{{-- Campaign links: build the URL, get a short link and a QR, then see what each one returned.
     The value over a third-party shortener is the last part — a shortener knows how many people
     clicked and nothing else, because it never sees the shop. --}}

@if (!$data['ready'])
    <x-k.card>
        <x-k.empty :title="translate('analytics_is_not_installed')" :text="translate('run_php_artisan_migrate_to_create_the_campaign_tables')" />
    </x-k.card>
@else
<x-k.card :title="translate('build_a_campaign_link')">
    <form method="POST" action="{{ route('admin.analytics.campaign.store') }}" class="ana-form">
        @csrf
        <div class="ana-form__row">
            <label>
                <span>{{ translate('campaign_name') }} *</span>
                <input type="text" name="name" required maxlength="191" placeholder="{{ translate('eg_ramadan_whatsapp_broadcast') }}">
            </label>
            <label>
                <span>{{ translate('destination') }} *</span>
                <input type="url" name="destination_url" required placeholder="https://…">
            </label>
        </div>

        <p class="ana-note">
            {{ translate('the_destination_must_be_on_this_shop') }}:
            @foreach ($data['allowed_hosts'] as $host)<code>{{ $host }}</code>@if (!$loop->last), @endif @endforeach.
            {{ translate('this_is_checked_when_the_link_is_created_and_again_on_every_click_so_a_short_link_can_never_send_a_customer_somewhere_else') }}
        </p>

        <div class="ana-form__row">
            <label>
                <span>utm_source *</span>
                <input type="text" name="utm_source" required maxlength="96" placeholder="whatsapp" list="ana-sources">
                <datalist id="ana-sources">
                    <option value="whatsapp"><option value="instagram"><option value="facebook">
                    <option value="telegram"><option value="google"><option value="email"><option value="sms">
                    <option value="print"><option value="influencer">
                </datalist>
            </label>
            <label>
                <span>utm_medium *</span>
                <input type="text" name="utm_medium" required maxlength="64" placeholder="social" list="ana-mediums">
                <datalist id="ana-mediums">
                    <option value="social"><option value="cpc"><option value="email"><option value="sms">
                    <option value="referral"><option value="banner"><option value="qr">
                </datalist>
            </label>
            <label>
                <span>utm_campaign *</span>
                <input type="text" name="utm_campaign" required maxlength="96" placeholder="ramadan_2026">
            </label>
        </div>

        <div class="ana-form__row">
            <label><span>utm_content</span><input type="text" name="utm_content" maxlength="96" placeholder="{{ translate('optional') }}"></label>
            <label><span>utm_term</span><input type="text" name="utm_term" maxlength="96" placeholder="{{ translate('optional') }}"></label>
            <label><span>{{ translate('expires_on') }}</span><input type="date" name="expires_at"></label>
        </div>

        <button type="submit" class="k-btn">{{ translate('create_the_link') }}</button>
    </form>
</x-k.card>

{{-- Whether a short link opens the app. A merchant printing a QR code has no way to find this
     out from the link itself, and the answer lives in a different section of the admin. --}}
@php($appLinks = $data['app_links'])
<x-k.card :title="translate('on_a_phone_with_the_app')">
    @if (!$appLinks['configured'])
        <p class="ana-note">
            {{ translate('the_mobile_app_is_not_set_up_so_every_short_link_opens_the_browser') }}
            <a href="{{ $appLinks['setup_url'] }}">{{ translate('set_up_deep_links') }}</a>.
        </p>
    @elseif (!$appLinks['campaign_path_is_published'])
        <p class="ana-note ana-note--warning">
            {{ translate('the_app_is_set_up_but_short_links_are_not_on_its_published_path_list_so_they_open_the_browser') }}
            <a href="{{ $appLinks['setup_url'] }}">{{ translate('review_the_published_paths') }}</a>.
        </p>
    @elseif (!$appLinks['published_claims_an_app'])
        <p class="ana-note ana-note--warning">
            {{ translate('the_app_is_set_up_but_nothing_has_been_published_for_the_phone_to_read_so_no_link_opens_the_app_yet') }}
            <code>php artisan deeplinks:publish</code>
        </p>
    @elseif (!$appLinks['files_are_current'])
        <p class="ana-note ana-note--warning">
            {{ translate('short_links_are_on_the_published_path_list_but_the_file_the_phone_reads_is_older_than_that_list') }}
            <code>php artisan deeplinks:publish</code>
        </p>
    @else
        <p class="ana-note">
            {{ translate('a_short_link_opens_the_app_when_it_is_installed_and_the_visit_is_still_counted_against_its_campaign') }}
            {{ translate('an_install_that_follows_one_carries_the_campaign_into_the_store_so_it_is_attributed_too') }}
            <a href="{{ $appLinks['setup_url'] }}">{{ translate('deep_link_setup') }}</a>.
        </p>
    @endif
</x-k.card>

<x-k.card :title="translate('campaign_links')">
    @if ($data['campaigns'] === [])
        <x-k.empty
            :title="translate('no_campaign_link_yet')"
            :text="translate('build_one_above_every_visit_that_arrives_through_it_carries_its_tagging_so_the_orders_it_produced_can_be_counted')" />
    @else
        @foreach ($data['campaigns'] as $item)
            @php($campaign = $item['row'])
            <div class="ana-campaign {{ $campaign->is_active && !$item['expired'] ? '' : 'is-inactive' }}">
                <div class="ana-campaign__head">
                    <div>
                        <strong>{{ $campaign->name }}</strong>
                        <small class="ana-muted">
                            {{ $campaign->utm_source }} / {{ $campaign->utm_medium }} / {{ $campaign->utm_campaign }}
                        </small>
                    </div>
                    <div class="ana-campaign__state">
                        @if ($item['expired'])
                            <x-k.badge tone="warning">{{ translate('expired') }}</x-k.badge>
                        @elseif (!$campaign->is_active)
                            <x-k.badge tone="neutral">{{ translate('paused') }}</x-k.badge>
                        @else
                            <x-k.badge tone="success">{{ translate('live') }}</x-k.badge>
                        @endif
                        <form method="POST" action="{{ route('admin.analytics.campaign.toggle', ['id' => $campaign->id]) }}">
                            @csrf
                            <input type="hidden" name="active" value="{{ $campaign->is_active ? 0 : 1 }}">
                            <button type="submit" class="k-btn k-btn--ghost k-btn--sm">
                                {{ $campaign->is_active ? translate('pause') : translate('resume') }}
                            </button>
                        </form>
                    </div>
                </div>

                <div class="ana-campaign__body">
                    <div class="ana-campaign__links">
                        <label>
                            <span>{{ translate('share_this') }}</span>
                            <input type="text" readonly value="{{ $item['short_url'] }}" onclick="this.select()">
                        </label>
                        <label>
                            <span>{{ translate('it_leads_to') }}</span>
                            <input type="text" readonly value="{{ $item['tagged_url'] }}" onclick="this.select()">
                        </label>
                    </div>

                    {{-- The QR is generated from the short link, not the tagged one: a printed code
                         that carries the whole UTM string is larger, harder to scan and impossible
                         to retarget once it is on a poster. --}}
                    <a class="ana-campaign__qr" href="{{ route('admin.analytics.campaign.qr', ['id' => $campaign->id]) }}"
                       target="_blank" rel="noopener" title="{{ translate('open_the_qr_at_full_size') }}">
                        {!! app(\App\Services\Analytics\CampaignService::class)->qrSvg($campaign->code, 96) !!}
                    </a>
                </div>

                <div class="ana-campaign__stats">
                    <div><span class="k-num">{{ number_format($campaign->clicks) }}</span><small>{{ translate('clicks') }}</small></div>
                    <div><span class="k-num">{{ number_format($campaign->sessions) }}</span><small>{{ translate('visits') }}</small></div>
                    <div><span class="k-num">{{ number_format($campaign->orders) }}</span><small>{{ translate('orders') }}</small></div>
                    <div><span class="k-num">{{ number_format((float) $campaign->revenue, 2) }}</span><small>{{ translate('revenue') }}</small></div>
                    <div>
                        <span class="k-num">{{ $item['conversion_rate'] !== null ? $item['conversion_rate'] . '%' : '—' }}</span>
                        <small>{{ translate('conversion') }}</small>
                    </div>
                    {{-- Not shown as 0 when the surface was never recorded: rows written before
                         short links became app links genuinely do not know where they opened. --}}
                    <div>
                        <span class="k-num">{{ $item['app_clicks'] === null ? '—' : number_format($item['app_clicks']) }}</span>
                        <small>{{ translate('opened_in_the_app') }}</small>
                    </div>
                </div>

                @if ($item['unconverted_clicks'] > 0)
                    {{-- Clicks that never became a visit: a link checker, a preview fetch, an ad
                         network's own crawler. A large gap is exactly what a click count from a
                         shortener would have hidden. --}}
                    <p class="ana-note">
                        {{ number_format($item['unconverted_clicks']) }}
                        {{ translate('clicks_never_became_a_visit_link_previews_and_crawlers_usually_account_for_this') }}
                    </p>
                @endif
            </div>
        @endforeach
    @endif
</x-k.card>
@endif

{{-- What each short link did over the selected window.

     The rollup has written a `campaign_link` dimension every day since it was built and no section
     asked for it, so the day-by-day series was reachable only by guessing the export URL — while
     this page read lifetime counters and could not answer "which day did it work". --}}
@php($perDay = $data['per_day'] ?? [])

@include('admin-views.analytics.sections._breakdown', [
    'breakdown' => $perDay,
    'title' => translate('what_each_link_did_in_this_window'),
    'label' => translate('short_link'),
])
