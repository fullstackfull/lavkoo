<div class="inline-page-menu my-4">
    <ul class="list-unstyled">
        <li class="{{ Request::is('admin/third-party/payment-method') ?'active':'' }}">
            <a class="text-capitalize"
               href="{{ route('admin.third-party.payment-method.index') }}">
                {{ translate('digital_payment_methods') }}
            </a>
        </li>
        <li class="{{ Request::is('admin/third-party/offline-payment-method/*') ?'active':'' }}">
            <a class="text-capitalize"
               href="{{ route('admin.third-party.offline-payment-method.index') }}">
                {{ translate('offline_payment_methods') }}
            </a>
        </li>
    </ul>
</div>
