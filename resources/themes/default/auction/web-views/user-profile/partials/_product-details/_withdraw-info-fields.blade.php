<?php
    // Withdraw info field list — common to APPROVED / PENDING / REJECTED branches in
    // _commission-withdraw-panel. Only the request-status colour class changes per branch.
    //   $wr → \Modules\Auction\app\Models\AuctionCustomerWithdrawRequest (or similar)
    //   $statusClass → e.g. 'text-success', 'text-danger', or '' for default (pending)
    $statusClass = $statusClass ?? '';
?>
<div class="d-flex flex-column gap-2">
    <div class="d-flex gap-2">
        <span class="fs-12 title-semidark minmax-xs-120px">{{ translate('Request_Status') }}</span>
        <span class="fs-12 title-semidark">:</span>
        <span class="fs-12 {{ $statusClass ?: 'title-clr' }} fw-semibold text-capitalize">{{ translate(ucfirst($wr->status)) }}</span>
    </div>
    @if(!empty($wr->withdrawal_method_fields['method_name']))
        <div class="d-flex gap-2">
            <span class="fs-12 title-semidark minmax-xs-120px">{{ translate('Method_name') }}</span>
            <span class="fs-12 title-semidark">:</span>
            <span class="fs-12 title-clr">{{ $wr->withdrawal_method_fields['method_name'] }}</span>
        </div>
    @endif
    @foreach($wr->withdrawal_method_fields ?? [] as $key => $val)
        @if($key !== 'method_name')
            <div class="d-flex gap-2">
                <span class="fs-12 title-semidark minmax-xs-120px">{{ translate(str_replace('_', ' ', $key)) }}</span>
                <span class="fs-12 title-semidark">:</span>
                <span class="fs-12 title-clr">{{ $val }}</span>
            </div>
        @endif
    @endforeach
</div>
