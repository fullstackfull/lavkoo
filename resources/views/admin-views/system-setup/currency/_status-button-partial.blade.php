<form action="{{route('admin.system-setup.currency.status')}}" method="post" data-from="currency"
      id="currency-status{{$currency['id']}}-form" class="currency_status_form no-reload-form">
    @csrf
    <input type="hidden" name="id" value="{{$currency['id']}}">
    <label class="switcher " for="currency-status{{$currency['id']}}">
        <input
            class="switcher_input custom-modal-plugin"
            type="checkbox" value="1" name="status"
            id="currency-status{{$currency['id']}}"
            {{$currency->status?'checked':''}}
            data-modal-type="input-change-form"
            data-modal-form="#currency-status{{$currency['id']}}-form"
            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/currency-on.png') }}"
            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/currency-off.png') }}"
            data-on-title = "{{translate('Want_to_Turn_ON_Currency_Status').'?'}}"
            data-off-title = "{{translate('Want_to_Turn_OFF_Currency_Status').'?'}}"
            data-on-message = "<p>{{translate('if_enabled_this_currency_will_be_available_throughout_the_entire_system')}}</p>"
            data-off-message = "<p>{{translate('if_disabled_this_currency_will_be_hidden_from_the_entire_system')}}</p>"
            data-on-button-text="{{ translate('turn_on') }}"
            data-off-button-text="{{ translate('turn_off') }}">
        <span class="switcher_control"></span>
    </label>
</form>
