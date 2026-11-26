<?php

namespace App\Models;

use App\Models\Builders\AuditedBuilder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class YourModel
 *
 * @property int $id Primary
 * @property string $name
 * @property string $module_access
 * @property bool $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */

class AdminRole extends Model
{
    protected $casts = [
        'id' => 'integer',
        'name' => 'string',
        'module_access' => 'string',
        'status' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'name',
        'module_access',
        'status',
    ];

    /**
     * A role is rewritten, switched off and deleted by a MASS statement -- `AdminRoleRepository` uses `where('id', $id)->update(...)` and
     * `where($params)->delete()` -- which instantiates no model and so raises no `updated` or `deleted` event. The observer on this model
     * therefore recorded a role being CREATED and nothing else: a role's `module_access` is the whole of what its holders may do, and it
     * could be rewritten, switched off or deleted with the trail saying nothing at all (G-14, G-15).
     *
     * The audited builder is what makes those three leave a line, for the same reason the settings models carry it, and without touching a
     * call site. `Admin` needs none: its repository updates a model it has found, so the event fires.
     */
    public function newEloquentBuilder($query): AuditedBuilder
    {
        return new AuditedBuilder($query);
    }
}
