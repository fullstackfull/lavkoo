<?php
    // Status note card with colored banner (success/warning/danger).
    //   $variant  → 'danger' | 'warning' | 'success'
    //   $title, $message
    //   $actionButton → optional pre-rendered HTML for a trailing action button (e.g. delete auction)
    $variant = $variant ?? 'danger';
    $actionButton = $actionButton ?? null;
?>
<div class="p-15px card border-0 shadow-sm rounded">
    <div class="bg-{{ $variant }} bg-opacity-10 rounded text-center py-12px px-3{{ $actionButton ? ' mb-3' : '' }}">
        <h4 class="fs-16 fw-semibold text-{{ $variant }} mb-1">{{ $title }}</h4>
        <p class="fs-14 title-semidark mb-0">{{ $message }}</p>
    </div>
    @if($actionButton)
        {!! $actionButton !!}
    @endif
</div>
