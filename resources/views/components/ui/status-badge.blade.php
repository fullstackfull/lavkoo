{{--
    Boolean / status badge.

    The brief calls out that boolean columns should read as meaningful badges rather than repeated
    "Yes / No" text. This renders state as colour + label, and stays RTL-safe (no directional
    margins — spacing comes from the parent's flex/grid gap).

    Usage:
        <x-ui.status-badge :state="$product->status" />
        <x-ui.status-badge :state="$o->is_paid" on="paid" off="unpaid" />
        <x-ui.status-badge state="pending" tone="warning" />
--}}
@props([
    'state' => false,
    'on'    => 'active',
    'off'   => 'inactive',
    'tone'  => null,   // success | warning | danger | info | secondary — overrides the boolean tone
])

@php
    $isOn = filter_var($state, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    // Non-boolean values (e.g. "pending") render as a labelled state rather than being coerced.
    $isLiteral = $isOn === null;
    $resolvedTone = $tone ?: ($isOn ? 'success' : 'secondary');
    $label = $isLiteral ? (string) $state : ($isOn ? $on : $off);
@endphp

<span {{ $attributes->merge(['class' => 'badge badge-soft-' . $resolvedTone]) }}>
    {{ translate($label) }}
</span>
