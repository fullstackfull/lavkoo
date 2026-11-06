{{-- The activation ladder: Disabled, then eight steps. For each: whether it is on, what it needs, what stops it, who approved it.
     Expects $account, $rungs (list<LadderRung>), $extraScopes, $labels. --}}
<x-k.card :title="translate('activation_ladder')" class="mb-3">
    <p class="fs-12 text-muted">{{ translate('activation_ladder_help') }}</p>
    @unless ($account->enabled)
        <div class="alert alert-warning fs-12 py-2" role="status">{{ translate('account_is_disabled_nothing_is_sent') }}</div>
    @endunless

    @foreach ($rungs as $rung)
        @php
            $capability = $rung->capability;
            $bag = 'capability_' . $capability->value;
            $approvalBag = 'approval_' . $capability->value;
            $reasonField = 'reason-' . $capability->value;
            $dependentNames = collect($rung->dependents)->map(fn ($dependent) => translate($dependent->labelKey()))->implode(', ');
            $switchOffQuestion = translate('switch_off_confirm') . ($dependentNames === '' ? '' : ' ' . translate('this_also_switches_off') . ': ' . $dependentNames);
        @endphp
        <div class="border-top py-3" id="capability-{{ $capability->value }}">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <div class="fw-semibold">{{ translate($capability->labelKey()) }}
                        @if ($rung->needsApproval())<x-k.badge tone="warning" :dot="false">{{ translate('changes_things_at_the_channel') }}</x-k.badge>@endif</div>
                    <div class="fs-12 text-muted">{{ $labels->capabilityHelp($capability) }}</div>
                </div>
                <div><x-k.badge :tone="$rung->held ? 'success' : 'neutral'">{{ $rung->held ? translate('switched_on') : translate('switched_off') }}</x-k.badge></div>
            </div>

            <div class="fs-12 mt-2">
                <span class="text-muted">{{ translate('requires') }}:</span>
                @forelse ($rung->requires as $need)
                    <x-k.badge :tone="$need['held'] ? 'success' : 'warning'" :dot="false">{{ translate($need['capability']->labelKey()) }}</x-k.badge>
                @empty
                    <span class="text-muted">{{ translate('nothing_else') }}</span>
                @endforelse
            </div>

            @if (!$rung->held && $rung->blocker)
                <div class="alert alert-warning fs-12 py-2 mt-2 mb-0" role="note">
                    <strong>{{ translate('cannot_be_switched_on_yet') }}:</strong> {{ $labels->ladderBlocker($rung->blocker) }}@if ($rung->blockerDetail) <span class="text-break">({{ $rung->blockerDetail }})</span>@endif
                </div>
            @endif

            @if ($rung->held && $rung->needsApproval())
                @if ($rung->approval)
                    <div class="fs-12 mt-2">
                        <x-k.badge tone="success" :dot="false">{{ translate('approved') }}</x-k.badge>
                        {{ $rung->approval['by'] ?? '—' }} · {{ $rung->approval['at']?->format('Y-m-d H:i') ?? '—' }}
                        @if ($rung->approval['reason'])<div class="text-muted text-break">{{ translate('reason') }}: {{ $rung->approval['reason'] }}</div>@endif
                    </div>
                @else
                    <div class="alert alert-danger fs-12 py-2 mt-2 mb-0" role="alert">{{ translate('approval_missing_writes_of_this_kind_are_refused') }}</div>
                @endif
            @endif

            <div class="d-flex flex-wrap gap-2 mt-2 align-items-start">
                @if (!$rung->held)
                    <form method="POST" action="{{ route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => $capability->value, 'action' => 'enable']) }}" class="d-flex flex-column gap-1 flex-grow-1">@csrf
                        @if ($rung->needsApproval())
                            <label class="form-label fs-12 mb-0" for="{{ $reasonField }}">{{ translate('approval_reason') }}</label>
                            <textarea class="form-control form-control-sm" id="{{ $reasonField }}" name="approval_reason" rows="2" minlength="5" maxlength="500" required>{{ $errors->hasBag($bag) ? old('approval_reason') : '' }}</textarea>
                            @if ($errors->getBag($bag)->has('approval_reason'))<div class="text-danger fs-12" role="alert">{{ $errors->getBag($bag)->first('approval_reason') }}</div>@endif
                        @endif
                        <div><button class="btn btn-primary btn-sm" type="submit" @disabled(!$rung->canSwitchOn())>{{ translate($rung->needsApproval() ? 'approve_and_switch_on' : 'switch_on') }}</button></div>
                    </form>
                @else
                    @if ($rung->needsApproval())
                        @if ($rung->approval)
                            <form method="POST" action="{{ route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => $capability->value, 'action' => 'revoke']) }}" onsubmit="return confirm(@js(translate('withdraw_approval_confirm')))">@csrf
                                <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('withdraw_approval') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => $capability->value, 'action' => 'approve']) }}" class="d-flex flex-column gap-1 flex-grow-1">@csrf
                                <label class="form-label fs-12 mb-0" for="{{ $reasonField }}-again">{{ translate('approval_reason') }}</label>
                                <textarea class="form-control form-control-sm" id="{{ $reasonField }}-again" name="approval_reason" rows="2" minlength="5" maxlength="500" required>{{ $errors->hasBag($approvalBag) ? old('approval_reason') : '' }}</textarea>
                                @if ($errors->getBag($approvalBag)->has('approval_reason'))<div class="text-danger fs-12" role="alert">{{ $errors->getBag($approvalBag)->first('approval_reason') }}</div>@endif
                                <div><button class="btn btn-primary btn-sm" type="submit">{{ translate('approve_again') }}</button></div>
                            </form>
                        @endif
                    @endif
                    <form method="POST" action="{{ route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => $capability->value, 'action' => 'disable']) }}"
                          onsubmit="return confirm(@js($switchOffQuestion))">@csrf
                        <button class="btn btn-outline-secondary btn-sm" type="submit">{{ translate('switch_off') }}</button>
                    </form>
                @endif
            </div>
            @if ($dependentNames !== '' && $rung->held)
                <div class="fs-12 text-muted mt-1">{{ translate('this_also_switches_off') }}: {{ $dependentNames }}</div>
            @endif
        </div>
    @endforeach
</x-k.card>

<x-k.card :title="translate('other_approvals')" class="mb-3">
    <p class="fs-12 text-muted">{{ translate('other_approvals_help') }}</p>
    @foreach ($extraScopes as $scope => $approval)
        <div class="border-top py-3">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <div class="fw-semibold">{{ $labels->approvalScope($scope) }}</div>
                    <div class="fs-12 text-muted">{{ $labels->approvalScopeHelp($scope) }}</div>
                </div>
                <div><x-k.badge :tone="$approval ? 'success' : 'neutral'">{{ $approval ? translate('approved') : translate('not_approved') }}</x-k.badge></div>
            </div>
            @if ($approval)
                <div class="fs-12 mt-1">{{ $approval['by'] ?? '—' }} · {{ $approval['at']?->format('Y-m-d H:i') ?? '—' }}
                    @if ($approval['reason'])<span class="text-muted text-break">· {{ $approval['reason'] }}</span>@endif</div>
                <form method="POST" action="{{ route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => $scope, 'action' => 'revoke']) }}" class="mt-2" onsubmit="return confirm(@js(translate('withdraw_approval_confirm')))">@csrf
                    <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('withdraw_approval') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => $scope, 'action' => 'approve']) }}" class="d-flex flex-column gap-1 mt-2">@csrf
                    <label class="form-label fs-12 mb-0" for="reason-{{ $scope }}">{{ translate('approval_reason') }}</label>
                    <textarea class="form-control form-control-sm" id="reason-{{ $scope }}" name="approval_reason" rows="2" minlength="5" maxlength="500" required>{{ $errors->hasBag('approval_' . $scope) ? old('approval_reason') : '' }}</textarea>
                    @if ($errors->getBag('approval_' . $scope)->has('approval_reason'))<div class="text-danger fs-12" role="alert">{{ $errors->getBag('approval_' . $scope)->first('approval_reason') }}</div>@endif
                    <div><button class="btn btn-primary btn-sm" type="submit">{{ translate('approve') }}</button></div>
                </form>
            @endif
        </div>
    @endforeach
</x-k.card>
