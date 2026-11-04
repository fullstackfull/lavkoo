{{--
    Table wrapper: horizontal scrolling is contained here so a wide table never makes the whole
    page scroll sideways (a recurring responsive complaint), with an optional sticky header.

    Usage:
        <x-ui.data-table>
            <thead>...</thead>
            <tbody>...</tbody>
        </x-ui.data-table>
--}}
@props(['sticky' => false])

<div class="table-responsive" style="overflow-x:auto">
    <table {{ $attributes->merge(['class' => 'table align-middle mb-0' . ($sticky ? ' table-sticky-head' : '')]) }}>
        {{ $slot }}
    </table>
</div>
