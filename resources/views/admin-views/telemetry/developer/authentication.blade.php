{{-- Read from the middleware each route runs, which matters here more than usual: this
     application authenticates the customer API and the vendor API by two entirely different
     mechanisms, and a developer told otherwise loses a day. --}}
<x-k.card :title="translate('how_this_api_authenticates')">
    <p class="dev-note">{{ translate('these_are_the_schemes_the_routes_actually_use_counted_from_the_live_middleware_not_from_configuration') }}</p>

    @foreach ($data['schemes'] as $middleware => $scheme)
        @php($count = $data['usage'][$scheme['mechanism']] ?? 0)
        @if ($count > 0)
            <div class="dev-scheme">
                <div class="dev-scheme__head">
                    <strong>{{ translate($scheme['mechanism']) }}</strong>
                    <x-k.badge tone="neutral">{{ $count }} {{ translate('endpoints') }}</x-k.badge>
                    <x-k.badge tone="info">{{ translate($scheme['actor']) }}</x-k.badge>
                </div>
                <pre class="dev-code"><code>{{ $scheme['header'] }}</code></pre>
                <p>{{ $scheme['note'] }}</p>
            </div>
        @endif
    @endforeach

    @if (($data['usage']['public'] ?? 0) > 0)
        <div class="dev-scheme">
            <div class="dev-scheme__head">
                <strong>{{ translate('public') }}</strong>
                <x-k.badge tone="neutral">{{ $data['usage']['public'] }} {{ translate('endpoints') }}</x-k.badge>
            </div>
            <p>{{ translate('no_credentials_required_some_of_these_still_behave_differently_when_a_customer_token_is_sent_and_say_so_on_their_own_page') }}</p>
        </div>
    @endif
</x-k.card>
