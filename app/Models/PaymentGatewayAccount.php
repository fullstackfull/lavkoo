<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's own account at one payment gateway, in one environment (D2-07). One row for each storefront country, gateway and environment; a payment
 * is stamped with the row it was started against and keeps that stamp when the row is later edited or disabled.
 *
 * `credentials` and `webhook_secret` hold ciphertext only and are read through App\Services\Payments\GatewayAccountSecrets, which is the one place that
 * decrypts them. They are hidden here as well, so an account that reaches an array, a JSON response or a log carries no ciphertext either.
 */
class PaymentGatewayAccount extends Model
{
    protected $table = 'payment_gateway_accounts';

    protected $fillable = [
        'storefront_country',
        'gateway',
        'environment',
        'legal_entity_id',
        'label',
        'webhook_path_token',
        'webhook_events',
        'status',
    ];

    /** Never rendered, never logged, never serialised: the secrets are read only through GatewayAccountSecrets. */
    protected $hidden = ['credentials', 'webhook_secret'];

    protected $casts = [
        'status' => 'boolean',
        'legal_entity_id' => 'integer',
    ];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }
}
