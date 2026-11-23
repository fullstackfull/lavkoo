<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What a prune of the audit trail leaves of the lines it removed: the id and the hash of the last one, how many there were, when, and who ran it. */
class PlatformAdminAuditCheckpoint extends Model
{
    public $timestamps = false;

    protected $fillable = ['last_line_id', 'last_hash', 'lines_removed', 'days', 'operator', 'pruned_at'];

    protected $casts = [
        'last_line_id' => 'integer',
        'lines_removed' => 'integer',
        'days' => 'integer',
        'pruned_at' => 'datetime',
    ];
}
