<?php

namespace App\Models;

use App\Models\Scopes\NewsletterMarketScope;
use App\Traits\DemoMaskingTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class Subscription
 *
 * @property int $id
 * @property string|null $email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class Subscription extends Model
{
    use HasFactory, DemoMaskingTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'email',
        'storefront_country',
    ];

    /**
     * The market a signup belongs to narrows the screens that read this table, once a request has said which markets it may reach
     * (I1-04). Silent for every request that bound none, which is every storefront page and every queue.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new NewsletterMarketScope());
    }

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email' => 'string',
    ];
}
