<x-k.card :title="translate('openapi')">
    <p class="dev-note">{{ translate('generated_from_the_live_route_table_and_the_validation_rules_each_endpoint_enforces_it_cannot_describe_an_endpoint_this_application_does_not_serve') }}</p>
    <div class="dev-downloads">
        <a class="k-btn" href="{{ route('admin.developer.openapi', ['format' => 'json']) }}">JSON</a>
        <a class="k-btn k-btn--ghost" href="{{ route('admin.developer.openapi', ['format' => 'yaml']) }}">YAML</a>
    </div>
    <p class="dev-note">{{ translate('narrow_the_spec_by_client') }}:</p>
    <div class="dev-downloads">
        @foreach (['customer_app', 'vendor_app', 'delivery_app', 'partner'] as $audience)
            <a class="k-btn k-btn--ghost k-btn--sm" href="{{ route('admin.developer.openapi', ['audience' => $audience]) }}">
                {{ translate($audience) }}
            </a>
        @endforeach
    </div>
</x-k.card>
