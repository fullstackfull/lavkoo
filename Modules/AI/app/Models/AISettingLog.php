<?php

namespace Modules\AI\app\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class AISettingLog
 *
 * @property int $id
 * @property int $seller_id
 * @property int $total_generated_count
 * @property int $total_image_generated_count
 * @property int $limit_at_time
 * @property string $action
 * @property array|null $section_usage
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AISettingLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_setting_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'seller_id',
        'total_generated_count',
        'total_image_generated_count',
        'limit_at_time',
        'action',
        'section_usage',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'section_usage' => 'array',
    ];
}
