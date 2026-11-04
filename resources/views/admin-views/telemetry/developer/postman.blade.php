<x-k.card :title="translate('postman_collection')">
    <p class="dev-note">{{ translate('folders_mirror_this_portals_own_grouping_tokens_are_variables_you_fill_in_and_path_parameters_are_variables_rather_than_ids_from_this_shops_database') }}</p>
    <div class="dev-downloads">
        <a class="k-btn" href="{{ route('admin.developer.postman') }}">{{ translate('whole_api') }}</a>
        @foreach (['customer_app', 'vendor_app', 'delivery_app', 'partner'] as $audience)
            <a class="k-btn k-btn--ghost k-btn--sm" href="{{ route('admin.developer.postman', ['audience' => $audience]) }}">
                {{ translate($audience) }}
            </a>
        @endforeach
    </div>
</x-k.card>
