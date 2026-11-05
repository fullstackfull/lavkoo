<div class="alert alert-{{ $integrationMode === \App\Enums\Fulfillment\IntegrationMode::ReadOnly ? 'info' : 'warning' }} d-flex gap-2 align-items-start" role="alert">
    <i class="fi fi-rr-triangle-warning mt-1"></i>
    <span>
        <strong>{{ translate('integration_mode') }}:</strong> {{ translate($integrationMode->labelKey()) }}
        <span class="d-block fs-12">{{ translate('integration_mode_help_' . $integrationMode->value) }}</span>
    </span>
</div>
