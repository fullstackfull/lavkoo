@extends('layouts.seller.app')

@section('title', translate('help'))

@section('content')
    <x-sc.page-header :eyebrow="translate('support')" :title="translate('help')" />
    <div class="sc-scroll">
        <div class="sc-page">
            <div class="sc-grid-two">
                <x-sc.card :title="translate('keyboard_shortcuts')">
                    <div class="sc-stack--tight">
                        @foreach ([
                            '⌘K / Ctrl+K' => translate('open_search'),
                            '/' => translate('open_search'),
                            '⌘1…⌘9' => translate('jump_to_a_section'),
                            'Esc' => translate('close_the_top_overlay'),
                            '↑ ↓ ↵' => translate('move_and_open_in_search'),
                        ] as $keys => $meaning)
                            <div class="sc-row">
                                <kbd class="sc-kbd">{{ $keys }}</kbd>
                                <span class="sc-dim">{{ $meaning }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-sc.card>

                <x-sc.card :title="translate('how_this_panel_works')">
                    <p class="sc-dim" style="font-size:12.5px">
                        {{ translate('normal_operations_stay_quiet_problems_and_required_actions_become_prominent') }}
                    </p>
                    <p class="sc-dim" style="font-size:12.5px">
                        {{ translate('every_problem_carries_a_severity_an_affected_count_a_deadline_one_action_and_a_direct_drill_down') }}
                    </p>
                </x-sc.card>
            </div>
        </div>
    </div>
@endsection
