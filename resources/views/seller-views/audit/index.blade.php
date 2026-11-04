@extends('layouts.seller.app')

@section('title', translate('nav_audit'))

@php
    use App\Services\SellerCenter\Copy;
    use App\Services\SellerCenter\Moment;

    $columns = [
        ['key' => 'when', 'label' => translate('when'), 'width' => 150],
        ['key' => 'who', 'label' => translate('who'), 'width' => 170],
        ['key' => 'what', 'label' => translate('what_changed')],
        ['key' => 'record', 'label' => translate('record'), 'width' => 150, 'priority' => 'md'],
    ];

    // What actually changed, rather than the word "changed". The phone app drops these two columns
    // entirely, which is why a seller reading their history on a browser could never see what a
    // value was moved FROM.
    $changedFields = static function (array $entry): array {
        $before = is_array($entry['before'] ?? null) ? $entry['before'] : [];
        $after = is_array($entry['after'] ?? null) ? $entry['after'] : [];
        $changed = [];

        foreach (array_keys($before + $after) as $field) {
            if (($before[$field] ?? null) === ($after[$field] ?? null)) {
                continue;
            }

            $changed[$field] = ['before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
        }

        return $changed;
    };

    $show = static function ($value): string {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? translate('yes') : translate('no');
        }
        if (is_array($value)) {
            return \Illuminate\Support\Str::limit(json_encode($value, JSON_UNESCAPED_UNICODE), 80);
        }

        return \Illuminate\Support\Str::limit((string) $value, 80);
    };
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_security')" :title="translate('nav_audit')"
                      :sub="translate('everything_you_your_staff_and_your_api_keys_did_and_everything_the_platform_recorded_about_your_shop')" />

    <div class="sc-scroll">
        <div class="sc-page">
            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="shield" :title="translate('nothing_recorded_yet')"
                                :text="translate('actions_appear_here_as_they_happen_there_is_nothing_to_switch_on')" />
                </x-slot:empty>

                @foreach ($entries as $entry)
                    @php($diff = $changedFields($entry))
                    <x-sc.tr :id="$entry['id']">
                        <x-sc.td class="sc-muted sc-ts">{{ Moment::stamp($entry['created_at'], withYear: true) }}</x-sc.td>
                        <x-sc.td :sub="translate($entry['actor_type'] ?? 'system')">{{ $entry['actor_name'] ?: '—' }}</x-sc.td>
                        <x-sc.td>
                            <code>{{ $entry['action'] }}</code>
                            @if ($diff !== [])
                                <div class="sc-subline">
                                    @foreach ($diff as $field => $change)
                                        <div>{{ $field }}: <span class="sc-muted">{{ $show($change['before']) }}</span> → <strong>{{ $show($change['after']) }}</strong></div>
                                    @endforeach
                                </div>
                            @endif
                        </x-sc.td>
                        <x-sc.td drop="md" class="sc-muted">
                            @if ($entry['subject_type'])
                                {{ class_basename($entry['subject_type']) }} #{{ $entry['subject_id'] }}
                            @else
                                —
                            @endif
                        </x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>

            @if ($shown > 0 && $total > $shown)
                {{-- The cap is stated. A seller who cannot page further back needs to know that,
                     rather than believe their history begins where the list stops. --}}
                <p class="sc-muted" style="padding:0 var(--shell-gutter)">
                    {{ Copy::line('showing_n_of_m_recorded_actions', ['shown' => $shown, 'total' => $total]) }}
                </p>
            @endif
        </div>
    </div>
@endsection
