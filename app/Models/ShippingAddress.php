<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Class ShippingAddress
 *
 * @property int $id
 * @property string|null $customer_id
 * @property bool $is_guest
 * @property string|null $contact_person_name
 * @property string|null $email
 * @property string $address_type
 * @property string|null $address
 * @property string|null $city
 * @property string|null $zip
 * @property string|null $phone
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string|null $state
 * @property string|null $country
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_billing
 * @property int|null $region_id
 * @property int|null $city_id
 * @property string|null $district
 * @property string|null $national_address
 *
 * @package App\Models
 */
class ShippingAddress extends Model
{
    protected $guarded = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'customer_id',
        'is_guest',
        'contact_person_name',
        'email',
        'address_type',
        'address',
        'city',
        'zip',
        'phone',
        'state',
        'country',
        'latitude',
        'longitude',
        'is_billing',
        'region_id',
        'city_id',
        'district',
        'national_address',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_guest' => 'boolean',
        'is_billing' => 'boolean',
        'region_id' => 'integer',
        'city_id' => 'integer',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(AddressRegion::class, 'region_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(AddressCity::class, 'city_id');
    }
}
