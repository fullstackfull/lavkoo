<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One approver's decision on an approval request (Phase 3 — spec item 82).
 *
 * The unique key on (request, actor) is what makes "one person cannot approve twice" a database
 * guarantee rather than a code check.
 */
class ApprovalDecision extends Model
{
    public const APPROVE = 'approve';
    public const REJECT = 'reject';

    protected $fillable = [
        'approval_request_id', 'decision', 'actor_type', 'actor_id', 'actor_name', 'note',
    ];
}
