<form action="{{route('admin.push-notification.update',['type'=>'customer'])}}"
      class="text-start" method="post" enctype="multipart/form-data" id="push-notifications-form">
    @csrf
    @php
    $auctionCustomerLabels = [
        'auction_went_live'                      => translate('auction_went_live') . ' (Bidder)',
        'auction_outbid'                         => translate('auction_outbid') . ' (Bidder)',
        'auction_won'                            => translate('auction_won') . ' (Bidder)',
        'auction_participation_payment_verified'  => translate('auction_participation_payment_verified') . ' (Bidder)',
        'auction_claim_payment_verified'          => translate('auction_claim_payment_verified') . ' (Bidder)',
        'auction_commission_payment_verified'     => translate('auction_commission_payment_verified') . ' (Bidder)',
        'auction_delivery_ready'                  => translate('auction_delivery_ready') . ' (Bidder)',
        'auction_delivery_on_the_way'            => translate('auction_delivery_on_the_way') . ' (Bidder)',
        'auction_delivered'                       => translate('auction_delivered') . ' (Bidder)',
        'auction_expired_result'                  => translate('auction_expired_result') . ' (Owner)',
        'auction_new_participation'               => translate('auction_new_participation') . ' (Owner)',
        'auction_new_bid'                         => translate('auction_new_bid') . ' (Owner)',
        'auction_item_claimed'                    => translate('auction_item_claimed') . ' (Owner)',
        'auction_claim_payment_verified_owner'    => translate('auction_claim_payment_verified_owner') . ' (Owner)',
        'auction_withdrawal_approved'             => translate('auction_withdrawal_approved') . ' (Owner)',
        'auction_withdrawal_rejected'             => translate('auction_withdrawal_rejected') . ' (Owner)',
    ];
    @endphp
    <div class="row g-4 mb-4">
        @foreach ($customerMessages as $key=>$value )
            <div class="col-md-6 col-lg-4">
                <div class="form-group">
                    <div
                        class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-10">
                        <label for="customer{{$value['key']}}" class="form-label switcher_content form-label mb-0">
                            {{ $auctionCustomerLabels[$value['key']] ?? translate($value['key'] == 'out_for_delivery_message' ? 'order_out_for_delivery_message' : ($value['key'] == 'order_canceled' ? 'order_canceled_message' : $value['key'])) }}
                        </label>
                        <label class="switcher" for="customer{{$value['key']}}">
                            <input
                                class="switcher_input custom-modal-plugin"
                                type="checkbox" value="1" name="status{{$value['id']}}"
                                id="customer{{$value['key']}}"
                                {{$value['status']==1?'checked':''}}
                                data-modal-type="input-change"
                                data-modal-form="#push-notifications-form"
                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/notification-on.png') }}"
                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/notification-off.png') }}"
                                data-on-title="{{ translate('Want_to_Turn_ON_Push_Notification') }}"
                                data-off-title="{{ translate('Want_to_Turn_OFF_Push_Notification') }}"
                                data-on-message="<p>{{ translate('if_enabled_customers_will_receive_notifications_on_their_devices') }}</p>"
                                data-off-message="<p>{{ translate('if_disabled_customers_will_not_receive_notifications_on_their_devices') }}</p>"
                                data-on-button-text="{{ translate('turn_on') }}"
                                data-off-button-text="{{ translate('turn_off') }}">
                            <span class="switcher_control"></span>
                        </label>
                    </div>
                    @foreach (json_decode($language) as $lang)
                            <?php
                            if (count($value['translations'])) {
                                $translate = [];
                                foreach ($value['translations'] as $t) {
                                    if ($t->locale == $lang && $t->key == $value['key']) {
                                        $translate[$lang][$value['key']] = $t->value;
                                    }
                                }
                            }
                            ?>
                        <input type="hidden" name="lang{{$value['id']}}[]"
                               value="{{ $lang }}">
                        <textarea name="message{{$value['id']}}[]"
                                  class="form-control text-area-max-min {{ $lang != $default_lang ? 'd-none' : '' }} lang-form {{ $lang }}-form">{{$translate[$lang][$value['key']]??$value['message']}}</textarea>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    <div class="d-flex gap-3 flex-wrap justify-content-end">
        <button type="reset" class="btn btn-secondary px-4 {{ getDemoModeFormButton(type: 'class') }}">
            {{ translate('reset') }}
        </button>
        <button type="{{ getDemoModeFormButton(type: 'button') }}"
                class="btn btn-primary px-4 {{ getDemoModeFormButton(type: 'class') }}">{{ translate('submit') }}
        </button>
    </div>
</form>
