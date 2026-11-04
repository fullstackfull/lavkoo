{{-- THE table. Every list in the Seller Center is a configuration of this one; a module-local
     table implementation is a defect (handoff 05 A). Geometry, header, sorting, selection,
     pagination and all seven data states live here — screens supply cells only. --}}
@props([
    'columns' => [],
    'state' => 'normal',        // normal | loading | refetching | empty | no_results | error | permission
    'selectable' => false,
    'sort' => null,
    'dir' => 'asc',
    'sortUrls' => [],           // column key => url
    'skeletonRows' => 10,
    'cards' => true,            // render the mobile card slot instead of a shrunken table
    'note' => null,             // partial-failure note, shown above the table
    'hiddenByRole' => false,
])
@php
    $dropClass = fn ($priority) => $priority ? ' sc-col--' . $priority : '';
    $span = count($columns) + ($selectable ? 1 : 0);
@endphp

<div {{ $attributes->merge(['class' => 'sc-table-region' . ($state === 'refetching' ? ' is-refetching' : '')]) }}>
    @if ($state === 'refetching')<div class="sc-refetch-bar"></div>@endif
    @if ($note)<div class="sc-toolbar__note" style="padding:0 var(--shell-gutter) 6px">{{ $note }}</div>@endif

    @if ($state === 'empty')
        {{ $empty ?? '' }}
    @elseif ($state === 'no_results')
        {{ $noResults ?? '' }}
    @elseif ($state === 'error')
        {{ $error ?? '' }}
    @elseif ($state === 'permission')
        {{ $permission ?? '' }}
    @else
        <div class="sc-table-wrap{{ $cards ? ' sc-table-wrap--cards' : '' }}">
            <table class="sc-table">
                <thead>
                    <tr>
                        @if ($selectable)
                            <th class="sc-cell--select" style="width:26px">
                                <label class="sc-check"><input type="checkbox" data-sc-select-all aria-label="{{ translate('select_all_on_this_page') }}"></label>
                            </th>
                        @endif
                        @foreach ($columns as $column)
                            @php($key = $column['key'] ?? '')
                            <th class="{{ ($column['num'] ?? false) ? 'sc-cell--num' : '' }}{{ $dropClass($column['priority'] ?? null) }}"
                                @if (!empty($column['width'])) style="width:{{ $column['width'] }}px" @endif
                                scope="col"
                                @if ($sort === $key) aria-sort="{{ $dir === 'desc' ? 'descending' : 'ascending' }}" @endif>
                                @if (($column['sortable'] ?? false) && isset($sortUrls[$key]))
                                    <a href="{{ $sortUrls[$key] }}" class="sc-th-sort{{ $sort === $key ? ' is-active' : '' }}">
                                        <span>{{ $column['label'] ?? '' }}</span>
                                        <x-sc.icon :name="$sort === $key ? ($dir === 'desc' ? 'arrow-down' : 'arrow-up') : 'arrows-down-up'" :size="10" />
                                    </a>
                                @else
                                    {{ $column['label'] ?? '' }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @if ($state === 'loading')
                        @for ($row = 0; $row < $skeletonRows; $row++)
                            <tr>
                                @if ($selectable)<td class="sc-cell--select"><x-sc.skeleton :height="12" width="16" /></td>@endif
                                @foreach ($columns as $column)
                                    <td class="{{ $dropClass($column['priority'] ?? null) }}"><x-sc.skeleton :height="12" :width="($column['width'] ?? 90) - 20 . 'px'" /></td>
                                @endforeach
                            </tr>
                        @endfor
                    @else
                        {{ $slot }}
                    @endif
                </tbody>
            </table>
        </div>
        @isset($mobile)<div class="sc-cards">{{ $mobile }}</div>@endisset
    @endif

    @if ($hiddenByRole)
        <div class="sc-toolbar__note" style="padding:8px var(--shell-gutter)">{{ translate('some_columns_are_hidden_by_your_role') }}</div>
    @endif
    @isset($footer){{ $footer }}@endisset
</div>
