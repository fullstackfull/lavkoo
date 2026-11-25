<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One customer's acceptance of one legal text in one storefront. A row is written once and never updated, so a later version of the
 * text does not change what was accepted.
 *
 * That sentence was a comment and nothing else: every column was fillable and nothing stopped an update or a delete, so the ONE record of
 * what a customer agreed to -- the version, and the hash of the text they were shown -- could be rewritten afterwards by any code that held
 * the row. A receipt that can be changed is not a receipt, and this one is what a regulator or a court would be shown.
 *
 * So the model refuses both, in the shape the other write-once records of this platform already use ({@see CustomerMarketingConsent},
 * {@see InventoryCostConsumption}, {@see OperationalCostEntry}): a mistake is corrected by recording the acceptance again, which is also
 * the only correction that keeps the first answer readable.
 */
class StorefrontLegalAcceptance extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'storefront_legal_acceptances';

    protected $fillable = [
        'storefront_country',
        'legal_entity',
        'document_type',
        'locale',
        'legal_document_id',
        'document_version',
        'document_hash',
        'customer_id',
        'guest_hash',
        'order_id',
        'accepted_at',
    ];

    protected $casts = [
        'legal_document_id' => 'integer',
        'document_version' => 'integer',
        'customer_id' => 'integer',
        'order_id' => 'integer',
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $acceptance): void {
            throw new RuntimeException('A legal acceptance cannot be changed: it records what one customer was shown and agreed to. Record the acceptance again instead.');
        });

        static::deleting(static function (self $acceptance): void {
            throw new RuntimeException('A legal acceptance cannot be deleted: it is the only record that a customer agreed to a text, and of which version of it.');
        });
    }
}
