@extends('layouts.seller.app')

@section('title', translate('nav_automation_history'))

@php
    use App\Services\SellerCenter\Automation\HistoryList;
    use App\Services\SellerCenter\Copy;
    use App\Services\SellerCenter\Shell;

    $columns = [
        ['key' => 'time', 'label' => translate('time'), 'width' => 150],
        ['key' => 'automation', 'label' => translate('automation'), 'width' => 200],
        ['key' => 'result', 'label' => translate('result')],
        ['key' => 'matched', 'label' => translate('matched'), 'width' => 90, 'num' => true, 'priority' => 'md'],
        ['key' => 'applied', 'label' => translate('applied'), 'width' => 90, 'num' => true, 'priority' => 'md'],
        ['key' => 'duration', 'label' => translate('duration'), 'width' => 110, 'priority' => 'lg'],
    ];

    $views = collect(HistoryList::VIEWS)->map(fn ($view, $key) => [
        'key' => $key,
        'label' => translate($view['label']),
        'href' => $key === 'all' ? route('seller.automation.history') : route('seller.automation.history', ['view' => $key]),
        'tone' => $view['tone'],
    ])->values()->all();

    $rowUrl = fn ($run) => route('seller.automation.history', array_merge(request()->query(), ['run' => $run->id]));

    /* Values are rendered as `field value` pairs rather than raw JSON: a seller reading "discount 20"
       can act on it, and a seller reading `{"discount":20}` is reading the database. */
    $flatten = fn (?array $values) => empty($values)
        ? '—'
        : implode(' · ', array_map(
            fn ($key, $value) => translate($key) . ' ' . (is_scalar($value) ? $value : '—'),
            array_keys($values),
            $values,
        ));
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_operations')" :title="translate('nav_automation_history')"
                      :sub="translate('every_run_of_every_rule_including_the_ones_that_matched_nothing')">
        <x-slot:actions>
            @if ($rulesUrl = Shell::route('seller.automation.index'))
                <x-sc.button variant="secondary" icon="robot" :href="$rulesUrl">{{ translate('nav_automation_rules') }}</x-sc.button>
            @endif
        </x-slot:actions>
    </x-sc.page-header>

    <x-sc.tabs :tabs="$views" :current="$currentView" />

    <div class="sc-scroll">
        <x-sc.table :columns="$columns" :state="$state">
            <x-slot:empty>
                <x-sc.empty glyph="clock-counter-clockwise" :title="translate('nothing_has_run_yet')"
                            :text="translate('once_a_rule_is_active_every_run_it_makes_appears_here_with_what_it_touched')" />
            </x-slot:empty>
            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_runs_match_these_filters')"
                            :text="translate('adjust_or_clear_the_filters_to_see_more')" />
            </x-slot:noResults>

            @foreach ($runs as $run)
                <x-sc.tr :href="$rowUrl($run)" :id="$run->id">
                    <x-sc.td class="sc-muted sc-ts">{{ \App\Services\SellerCenter\Moment::stamp($run->started_at) }}</x-sc.td>
                    {{-- The runs outlive the rule deliberately; a deleted rule still names what it
                         did rather than leaving the seller with "rule 14 ran". --}}
                    <x-sc.td>{{ $ruleNames[$run->rule_id] ?? translate('a_deleted_rule') }}</x-sc.td>
                    <x-sc.td>
                        <x-sc.badge :tone="$list->outcomeTone($run)"
                                    :glyph="$run->outcome === 'applied' ? 'check-circle' : ($run->outcome === 'failed' ? 'x-circle' : ($run->outcome === 'capped' ? 'warning' : 'minus-circle'))"
                                    :label="translate('automation_outcome_' . $run->outcome)" />
                        <div class="sc-muted" style="font-size:11.5px">{{ $list->outcomeSentence($run) }}</div>
                    </x-sc.td>
                    <x-sc.td num drop="md" class="sc-muted">{{ number_format((int) $run->matched_count) }}</x-sc.td>
                    <x-sc.td num drop="md" class="sc-muted">{{ number_format((int) $run->applied_count) }}</x-sc.td>
                    <x-sc.td drop="lg" class="sc-muted">{{ $list->duration($run) ?? '—' }}</x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($runs as $run)
                    <x-sc.entity-card :title="$ruleNames[$run->rule_id] ?? translate('a_deleted_rule')"
                                      :href="$rowUrl($run)"
                                      :meta="\App\Services\SellerCenter\Moment::stamp($run->started_at)">
                        <div class="sc-dim" style="font-size:12px">{{ $list->outcomeSentence($run) }}</div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer><x-sc.pager :paginator="$runs" /></x-slot:footer>
        </x-sc.table>
    </div>

    @if ($openRun)
        @php($run = $openRun['run'])
        <x-sc.drawer id="sc-run" :title="$ruleNames[$run->rule_id] ?? translate('a_deleted_rule')">
            <x-slot:sub><span class="sc-ts">{{ \App\Services\SellerCenter\Moment::stamp($run->started_at, withYear: true) }}</span></x-slot:sub>
            <x-slot:badges>
                <x-sc.badge :tone="$list->outcomeTone($run)" :label="translate('automation_outcome_' . $run->outcome)" />
            </x-slot:badges>

            <div class="sc-stack">
                <div class="sc-info-grid">
                    <x-sc.info :label="translate('matched')" :value="number_format((int) $run->matched_count)" />
                    <x-sc.info :label="translate('applied')" :value="number_format((int) $run->applied_count)" />
                    <x-sc.info :label="translate('skipped')" :value="number_format((int) $run->skipped_count)" />
                    <x-sc.info :label="translate('failed')" :value="number_format((int) $run->failed_count)"
                               :tone="(int) $run->failed_count > 0 ? 'critical' : null" />
                </div>

                <p class="sc-dim" style="font-size:12.5px;margin:0">{{ $list->outcomeSentence($run) }}</p>

                @if ($openRun['records'] === [])
                    <x-sc.empty glyph="list" :title="translate('this_run_touched_nothing')"
                                :text="translate('the_rule_ran_and_found_nothing_to_act_on')" />
                @else
                    <div class="sc-stack--tight">
                        @foreach ($openRun['records'] as $record)
                            <div class="sc-panel-row">
                                <div style="flex:1 1 auto;min-width:0">
                                    <div style="font-size:12.5px">{{ $record->subject_label ?: '#' . $record->subject_id }}</div>
                                    <div class="sc-muted" style="font-size:11.5px">
                                        @if ($record->status === 'applied')
                                            {{ $flatten($record->before) }} → {{ $flatten($record->after) }}
                                        @else
                                            {{-- A skipped record shows why, and is never offered as
                                                 undoable — there is nothing to put back. --}}
                                            {{ translate($record->reason ?: 'automation_reason_skipped') }}
                                        @endif
                                    </div>
                                </div>
                                @if ($record->reverted_at)
                                    <span class="sc-muted" style="font-size:11px">{{ translate('undone') }}</span>
                                @elseif ($record->isRevertible() && ($revertUrl = Shell::route('seller.automation.revert', $record->id)))
                                    <form method="POST" action="{{ $revertUrl }}">
                                        @csrf
                                        <button type="submit" class="sc-btn sc-btn--ghost sc-btn--sm">{{ translate('undo') }}</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-sc.drawer>

        @push('script')
            <script>
                /* The drawer is server-rendered and the row that opens it is an ordinary link, so
                   the run is addressable and survives a reload. */
                document.addEventListener('DOMContentLoaded', function () {
                    var drawer = document.getElementById('sc-run');
                    if (drawer) { drawer.hidden = false; document.querySelector('[data-sc-drawer-scrim="sc-run"]').hidden = false; }
                });
            </script>
        @endpush
    @endif
@endsection
