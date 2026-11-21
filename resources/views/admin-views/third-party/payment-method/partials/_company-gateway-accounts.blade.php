{{-- Each company's own gateway account: its market, its company, its own webhook address and WHETHER it holds a secret (D2-08, D2-09). Never what
     a secret is. Empty, and so renders nothing at all, while per-company payment accounts are off.

     The environment is a stored value, so it is matched against the two names the registry knows and translated by a fixed key, and anything else is
     printed as it stands. translate() WRITES a key it does not know into resources/lang/<locale>/new-messages.php, and that file is included on every
     single request: a database value handed to it turns a row an operator typed into a permanent edit of a shipped language file. --}}
@if(!empty($gatewayAccounts))
    <hr>
    <p class="mb-2">{{ translate('each_company_account_has_its_own_webhook_url_and_its_own_secret') }}:</p>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>{{ translate('market') }}</th>
                    <th>{{ translate('company') }}</th>
                    <th>{{ translate('mode') }}</th>
                    <th>{{ translate('webhook') }}</th>
                    <th>{{ translate('webhook_secret') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($gatewayAccounts as $gatewayAccount)
                    <tr>
                        <td>{{ $gatewayAccount['market'] }}</td>
                        <td>{{ $gatewayAccount['company'] ?? translate('not_registered') }}</td>
                        @php($environmentLabel = match ($gatewayAccount['environment']) {
                            'live' => translate('live'),
                            'test' => translate('test'),
                            default => $gatewayAccount['environment'],
                        })
                        <td>{{ $environmentLabel }}</td>
                        <td>
                            <input type="text" class="form-control form-control-sm" readonly
                                   id="mf-webhook-url-{{ $gatewayAccount['id'] }}"
                                   value="{{ $gatewayAccount['webhook_url'] ?? translate('no_webhook_address_yet') }}">
                        </td>
                        <td>{!! $gatewayAccount['has_webhook_secret'] ? '<span class="text-success">'.translate('saved').'</span>' : '<span class="text-danger">'.translate('missing').'</span>' !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
