{{-- Confirmation, a small create/edit, or one focused decision. Never an application screen and
     never a >4-field workflow (handoff 04 §21). --}}
@props(['title', 'id', 'form' => false, 'danger' => false])
<div class="sc-scrim sc-scrim--modal" data-sc-modal-scrim="{{ $id }}" hidden></div>
<div class="sc-modal" id="{{ $id }}" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" hidden>
    <div class="sc-modal__panel{{ $form ? ' sc-modal__panel--form' : '' }}">
        <h5 class="sc-modal__title" id="{{ $id }}-title">{{ $title }}</h5>
        <div class="sc-modal__body">{{ $slot }}</div>
        <div class="sc-modal__actions">{{ $actions ?? '' }}</div>
    </div>
</div>
