{{-- What the customer is told about customs duty before they pay (H1-09).

     Nothing at all on an order that crosses no border, and nothing while duty is off: the controller hands null, and a line about duty on
     an order that never approaches a border is noise. A route nobody has written a rule for says so rather than claiming nothing is owed --
     a zero would read as "these goods cross free", which is a claim the shop has no authority to make. --}}
@if (!empty($customsDuty))
    <div class="alert alert-info d-flex align-items-start gap-2 mb-3" role="note" data-customs-duty-notice>
        <i class="tio-info-outined mt-1" aria-hidden="true"></i>
        <div class="fs-13">
            @if ($customsDuty['quote']->isPayable())
                <strong>{{ translate('customs_duty') }}:</strong>
                {{ webCurrencyConverter(amount: $customsDuty['quote']->amount) }}
            @endif
            @if ($customsDuty['notice'])
                <span>{{ $customsDuty['notice'] }}</span>
            @endif
        </div>
    </div>
@endif
