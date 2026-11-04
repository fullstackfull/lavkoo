@extends('layouts.admin.app')

@section('title', translate('banner_placement_guide'))

@push('css_or_js')
    <style>
        /* Wireframes, not screenshots: plain boxes that show where a banner lands
           on the page. Deliberately abstract so they never go stale against the
           real design. */
        .bp-wire {
            --bp-line: var(--bs-border-color, #eff1f4);
            --bp-fill: #f6f7f9;
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding: 10px;
            border: 1px solid var(--bp-line);
            border-radius: 10px;
            background: #fff;
        }

        .bp-row { display: flex; gap: 6px; }
        .bp-box {
            flex: 1;
            border-radius: 5px;
            background: var(--bp-fill);
            border: 1px dashed var(--bp-line);
            min-height: 16px;
        }
        .bp-box--tall { min-height: 42px; }
        .bp-box--text { min-height: 10px; max-width: 45%; }
        .bp-box--chip { min-height: 14px; max-width: 64px; border-radius: 999px; }
        .bp-box--tile { min-height: 34px; }

        /* the banner being explained */
        .bp-banner {
            display: grid;
            place-items: center;
            min-height: 34px;
            border-radius: 5px;
            border: 1px solid rgba(var(--bs-primary-rgb, 20, 85, 172), 0.35);
            background: rgba(var(--bs-primary-rgb, 20, 85, 172), 0.10);
            color: var(--bs-primary, #1455ac);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .bp-banner--tall { min-height: 58px; }
        .bp-banner--half { flex: 1; }
        .bp-banner--phone { min-height: 46px; }

        .bp-phone {
            width: 118px;
            padding: 8px 6px;
            border: 2px solid var(--bp-line);
            border-radius: 14px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .bp-card { height: 100%; }
        .bp-card .card-body { display: flex; flex-direction: column; gap: 12px; }
        .bp-meta { display: flex; flex-wrap: wrap; gap: 6px; }
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <x-k.icon name="image" :size="20" />
                {{ translate('banner_placement_guide') }}
            </h2>
            <a href="{{ route('admin.banner.list') }}" class="k-btn k-btn--secondary text-capitalize">
                {{ translate('back_to_banners') }}
            </a>
        </div>

        <p class="text-muted max-w-720 mb-4">
            {{ translate('each_card_below_shows_where_that_banner_type_appears_and_what_shape_it_takes') }}
            {{ translate('the_highlighted_block_is_the_banner_itself') }}
        </p>

        @php
            $placements = [
                [
                    'type' => 'Main Banner',
                    'where' => translate('home_page_top_slideshow'),
                    'ratio' => '2400 x 996',
                    'wire' => 'main',
                ],
                [
                    'type' => 'Main Section Banner',
                    'where' => translate('a_wide_strip_between_the_home_page_sections'),
                    'ratio' => '2000 x 500',
                    'wire' => 'strip',
                ],
                [
                    'type' => 'Footer Banner',
                    'where' => translate('above_the_footer_on_the_home_page'),
                    'ratio' => '2000 x 1000',
                    'wire' => 'footer',
                ],
                [
                    'type' => 'Popup Banner',
                    'where' => translate('a_dialog_that_opens_over_the_home_page_on_the_first_visit'),
                    'ratio' => '1200 x 1200',
                    'wire' => 'popup',
                ],
                [
                    'type' => 'Home Promo Banner',
                    'where' => translate('a_promo_grid_on_the_home_page_that_belongs_to_no_category'),
                    'ratio' => '2000 x 500',
                    'wire' => 'promo',
                    'new' => true,
                ],
                [
                    'type' => 'Category Section Banner',
                    'where' => translate('directly_above_the_chosen_categorys_product_row_on_the_home_page'),
                    'ratio' => '2000 x 500',
                    'wire' => 'section',
                    'new' => true,
                ],
                [
                    'type' => 'Category Banner',
                    'where' => translate('the_top_of_the_chosen_categorys_own_page_inherited_by_its_sub_categories'),
                    'ratio' => '2000 x 500',
                    'wire' => 'category',
                    'new' => true,
                ],
                [
                    'type' => 'Brand Banner',
                    'where' => translate('the_top_of_the_chosen_brands_page_above_its_category_filters'),
                    'ratio' => '2000 x 500',
                    'wire' => 'brand',
                    'new' => true,
                ],
            ];
        @endphp

        {{-- Only what this theme actually offers: the type list differs per theme, and
             a card for a type the merchant cannot create is a promise the form breaks. --}}
        @php($placements = array_values(array_filter($placements, fn ($placement) => isset($bannerTypes[$placement['type']]))))

        <div class="row g-3">
            @foreach ($placements as $placement)
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="card bp-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <h3 class="fs-16 fw-semibold mb-0">
                                    {{ $bannerTypes[$placement['type']] ?? translate(str_replace(' ', '_', $placement['type'])) }}
                                </h3>
                                @if (!empty($placement['new']))
                                    <x-k.badge tone="accent">{{ translate('new') }}</x-k.badge>
                                @endif
                            </div>

                            @include('admin-views.banner.partials._placement-wireframe', ['wire' => $placement['wire']])

                            <p class="fs-12 text-muted mb-0">{{ $placement['where'] }}</p>
                            <div class="bp-meta">
                                <x-k.badge tone="neutral">{{ translate('ratio') }} {{ $placement['ratio'] }}</x-k.badge>
                                @if (in_array($placement['type'], \App\Services\BannerService::GRID_TYPES))
                                    <x-k.badge tone="info">{{ translate('supports_layouts') }}</x-k.badge>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <h2 class="h3 mt-5 mb-3">{{ translate('banner_layout') }}</h2>
        <p class="text-muted max-w-720">
            {{ translate('the_grid_banner_types_ask_how_wide_each_banner_should_sit_priority_orders_them') }}
        </p>

        <div class="row g-3">
            @foreach (['full', 'half', 'slider'] as $layout)
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="card bp-card">
                        <div class="card-body">
                            <h3 class="fs-16 fw-semibold mb-0">
                                {{ translate($layout == 'full' ? 'full_width_row' : ($layout == 'half' ? 'half_width_beside_another' : 'inside_the_rotating_slider')) }}
                            </h3>
                            @include('admin-views.banner.partials._placement-wireframe', ['wire' => 'layout_' . $layout])
                            <p class="fs-12 text-muted mb-0">
                                {{ translate($layout == 'full'
                                    ? 'the_banner_takes_the_whole_row'
                                    : ($layout == 'half'
                                        ? 'two_half_banners_share_one_row_a_lone_half_stays_half'
                                        : 'every_slider_banner_is_pooled_into_one_rotating_slot')) }}
                            </p>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="card bp-card">
                    <div class="card-body">
                        <h3 class="fs-16 fw-semibold mb-0">{{ translate('mobile_app_image') }}</h3>
                        @include('admin-views.banner.partials._placement-wireframe', ['wire' => 'mobile'])
                        <p class="fs-12 text-muted mb-0">
                            {{ translate('any_banner_can_carry_a_second_phone_shaped_image_the_apps_use_it_and_fall_back_to_the_wide_one') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
