{{-- Whose books a row belongs to: the platform's (seller 0) or one seller's. Expects $seller (int). --}}
@use('App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope')
{{ SellerScope::label((int) $seller) }}
