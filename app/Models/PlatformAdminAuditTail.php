<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The one row that says which line of the audit trail was written last and what its hash is. The writer holds it while it writes, so that two writers take
 * turns, and verification compares it with the end of the trail.
 */
class PlatformAdminAuditTail extends Model
{
    /** The id of the one row. */
    public const ROW = 1;

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'platform_admin_audit_tail';

    protected $fillable = ['last_line_id', 'last_hash'];

    protected $casts = ['last_line_id' => 'integer'];
}
