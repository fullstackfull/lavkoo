@extends('layouts.seller.app')

@section('title', translate('preview_matches'))

@php
    use App\Services\SellerCenter\Copy;
    use App\Services\SellerCenter\Shell;

    $columns = [
        ['key' => 'subject', 'label' => translate('product')],
        ['key' => 'outcome', 'label' => translate('would_apply'), 'width' => 150],
        ['key' => 'before', 'label' => translate('before'), 'width' => 160, 'priority' => 'md'],
        ['key' => 'after', 'label' => translate('after'), 'width' => 160, 'priority' => 'md'],
    ];

    $flatten = fn (?array $values) => $values === null || $values === []
        ? '—'
        : implode(' · ', array_map(
            fn ($key, $value) => translate($key) . ' ' . (is_scalar($value) ? $value : '—'),
            array_keys($values),
            $values,
        ));
@endphp

@section('content')
    <x-sc.page-header :eyebrow="$rule['name']" :title="translate('preview_matches')"
                      :sub="translate('nothing_on_this_page_has_been_changed_this_is_what_the_rule_would_do_if_it_ran_now')">
        <x-slot:actions>
            @if ($editUrl = Shell::route('seller.automation.edit', $rule['id']))
                <x-sc.button variant="ghost" icon="arrow-left" :href="$editUrl">{{ translate('back_to_the_rule') }}</x-sc.button>
            @endif
            @if ($runUrl = Shell::route('seller.automation.run', $rule['id']))
                <form method="POST" action="{{ $runUrl }}" style="display:inline">
                    @csrf
                    <button type="submit" class="sc-btn sc-btn--primary sc-btn--sm">{{ translate('run_now') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page" style="padding-bottom:0">
            <p class="sc-dim" style="font-size:12.5px;margin:0 0 12px">{{ $rule['sentence'] }}</p>

            @if ($preview['capped'])
                {{-- Matched is never called applied. A capped run touches nothing at all, and saying
                     so plainly is the whole point of the cap (handoff 08 A2). --}}
                <x-sc.alert tone="high" :title="translate('nothing_would_be_applied')">
                    {{ Copy::line('this_would_match_n_products_more_than_the_n_allowed_per_run', [
                        'matched' => number_format($preview['matched']),
                        'cap' => number_format($rule['model']->max_actions_per_run),
                    ]) }}
                </x-sc.alert>
            @else
                <x-sc.stat :label="translate('would_match')" :value="number_format($preview['matched'])"
                           :note="Copy::line('at_most_n_per_run', ['count' => number_format($rule['model']->max_actions_per_run)])" />
            @endif
        </div>

        <x-sc.table :columns="$columns" :state="$preview['subjects'] === [] ? 'empty' : 'normal'">
            <x-slot:empty>
                <x-sc.empty glyph="magnifying-glass" :title="translate('nothing_matches_right_now')"
                            :text="translate('the_rule_is_written_correctly_it_simply_has_nothing_to_act_on_at_this_moment')" />
            </x-slot:empty>

            @foreach ($preview['subjects'] as $subject)
                <x-sc.tr>
                    <x-sc.td>{{ $subject['label'] ?: '#' . $subject['subject_id'] }}</x-sc.td>
                    <x-sc.td>
                        @if ($subject['will_apply'])
                            <x-sc.badge tone="good" glyph="check-circle" :label="translate('yes')" />
                        @else
                            {{-- A row the rule would decline names its reason. "No" on its own tells
                                 the seller nothing they can fix. --}}
                            <x-sc.badge tone="neutral" glyph="prohibit" :label="translate($subject['reason'] ?? 'automation_reason_skipped')" />
                        @endif
                    </x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ $flatten($subject['before']) }}</x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ $flatten($subject['after']) }}</x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($preview['subjects'] as $subject)
                    <x-sc.entity-card :title="$subject['label'] ?: '#' . $subject['subject_id']"
                                      :meta="$subject['will_apply'] ? translate('would_apply') : translate($subject['reason'] ?? 'automation_reason_skipped')">
                        <div class="sc-dim" style="font-size:12px">{{ $flatten($subject['before']) }} → {{ $flatten($subject['after']) }}</div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>
        </x-sc.table>
    </div>
@endsection
