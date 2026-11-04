@php
    /**
     * The section nav, carrying its own numbers.
     *
     * Counts on the tabs rather than only on the overview: an operator who has
     * navigated away from the overview should still be able to see that three
     * rules are stopped without going back for it. A count is omitted rather
     * than shown as zero where the table does not exist, because "not installed"
     * and "none" call for different actions.
     */
    $summary = $summary ?? [];
    $countFor = fn (string $key) => ($summary[$key]['installed'] ?? false)
        ? ($summary[$key]['attention'] ?? 0)
        : null;
@endphp

<x-k.tabs class="mb-3" :items="[
    [
        'label' => translate('overview'),
        'href' => route('admin.marketplace.seller-operations.index'),
        'active' => Request::is('admin/marketplace/seller-operations'),
    ],
    [
        'label' => translate('issues'),
        'href' => route('admin.marketplace.seller-operations.issues'),
        'active' => Request::is('admin/marketplace/seller-operations/issues*'),
        'count' => $countFor('issues'),
    ],
    [
        'label' => translate('automation'),
        'href' => route('admin.marketplace.seller-operations.automation'),
        'active' => Request::is('admin/marketplace/seller-operations/automation*'),
        'count' => $countFor('automation'),
    ],
    [
        'label' => translate('keys_and_webhooks'),
        'href' => route('admin.marketplace.seller-operations.integrations'),
        'active' => Request::is('admin/marketplace/seller-operations/integrations*'),
        'count' => $countFor('webhooks'),
    ],
    [
        'label' => translate('seller_staff'),
        'href' => route('admin.marketplace.seller-operations.team'),
        'active' => Request::is('admin/marketplace/seller-operations/team*'),
        'count' => $countFor('staff'),
    ],
    [
        'label' => translate('bulk_operations'),
        'href' => route('admin.marketplace.seller-operations.bulk-jobs'),
        'active' => Request::is('admin/marketplace/seller-operations/bulk-jobs*'),
        'count' => $countFor('bulk_jobs'),
    ],
]" />
