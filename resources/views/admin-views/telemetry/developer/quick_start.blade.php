{{-- A real first hour, not a marketing page. Each step is the smallest thing that can be verified
     before moving to the next, because a developer whose third call fails needs to know which of
     the first two was wrong. --}}
<x-k.card :title="translate('quick_start')">
    <ol class="dev-steps">
        <li>
            <h4>{{ translate('point_at_this_installation') }}</h4>
            <p>{{ translate('every_path_in_this_portal_is_relative_to_this_base_url') }}</p>
            <pre class="dev-code"><code>{{ $data['base_url'] }}</code></pre>
        </li>
        <li>
            <h4>{{ translate('get_a_token') }}</h4>
            <p>{{ translate('customer_and_vendor_tokens_are_different_mechanisms_and_are_not_interchangeable_see_authentication') }}</p>
            <pre class="dev-code"><code>curl -X POST '{{ $data['base_url'] }}/api/v1/auth/login' \
  -H 'Content-Type: application/json' \
  -d '{"email_or_phone":"customer@example.com","password":"your-password","type":"email"}'</code></pre>
        </li>
        <li>
            <h4>{{ translate('make_an_authenticated_call') }}</h4>
            <pre class="dev-code"><code>curl '{{ $data['base_url'] }}/api/v1/customer/info' \
  -H 'Authorization: Bearer $TOKEN' \
  -H 'X-App-Version: 1.0.0'</code></pre>
            <p class="dev-note">{{ translate('send_x_app_version_from_the_start_it_is_what_lets_an_old_endpoint_be_retired_safely_later') }}</p>
        </li>
        <li>
            <h4>{{ translate('handle_the_errors_this_api_actually_returns') }}</h4>
            <p>{{ translate('validation_failures_come_back_as_403_not_422_and_the_body_is_always_the_same_shape') }}</p>
            <pre class="dev-code"><code>{"errors":[{"code":"password","message":"The password must be at least 6 characters."}]}</code></pre>
        </li>
        <li>
            <h4>{{ translate('keep_the_request_id') }}</h4>
            <p>{{ translate('every_response_carries_x_request_id_quote_it_when_reporting_a_problem_and_the_exact_request_can_be_found_in_monitoring') }}</p>
        </li>
    </ol>
</x-k.card>
