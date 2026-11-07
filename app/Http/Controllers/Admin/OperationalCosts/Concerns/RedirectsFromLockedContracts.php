<?php

namespace App\Http\Controllers\Admin\OperationalCosts\Concerns;

use App\Models\OperationalCostContract;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;

/** A contract that is in force can not be edited: the way to change it is a new version. A page that would edit one sends the person to the contract instead, and says why. */
trait RedirectsFromLockedContracts
{
    protected function lockedContract(OperationalCostContract $contract): RedirectResponse
    {
        ToastMagic::warning(translate('contract_not_editable_start_a_new_version'));

        return redirect()->route('admin.operational-costs.contracts.show', $contract->id);
    }
}
