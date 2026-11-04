@props(['label' => null])
{{--
    Sticky unsaved-changes bar for a form.

    Opt in by adding data-k-save-bar to the form itself and dropping this inside
    it. Nothing is restructured, so a page keeps its own submit button and its own
    server-side handling; this only makes the way to save reachable from wherever
    the person is on the page.
--}}
<div class="k-savebar" data-k-savebar role="status" aria-live="polite">
    <span class="k-savebar__dot" aria-hidden="true"></span>
    <span class="k-savebar__text">{{ $label ?? translate('you_have_unsaved_changes') }}</span>
    <span class="k-savebar__actions">
        <x-k.button variant="ghost" size="sm" data-k-savebar-discard>{{ translate('discard') }}</x-k.button>
        <x-k.button variant="primary" size="sm" data-k-savebar-save>{{ translate('save_changes') }}</x-k.button>
    </span>
</div>
