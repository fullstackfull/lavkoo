<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminAuditLog;

/**
 * Reads the audit trail of the platform admin API for an admin who may read it: the platform owner reads every line, anyone else only their own.
 * Newest first, one page at a time, and never a count of the whole table.
 */
final class PlatformAuditLogReader
{
    /** @return array{lines: list<array<string, mixed>>, has_more: bool} */
    public function page(PlatformAdminPrincipal $principal, PlatformPagination $pagination): array
    {
        $query = PlatformAdminAuditLog::query()->orderByDesc('id')->offset($pagination->offset())->limit($pagination->perPage + 1);

        if (!$principal->isPlatformOwner()) {
            $query->where('admin_id', $principal->adminId());
        }

        $lines = $query->get();

        return [
            'lines' => $lines->take($pagination->perPage)->map(fn (PlatformAdminAuditLog $line): array => $this->presented(line: $line))->values()->all(),
            'has_more' => $lines->count() > $pagination->perPage,
        ];
    }

    /** @return array<string, mixed> */
    private function presented(PlatformAdminAuditLog $line): array
    {
        return [
            'id' => (int) $line->getKey(),
            'admin_id' => $line->admin_id,
            'action' => $line->action,
            'market' => $line->market,
            'token_id' => $line->token_id,
            'ip' => $line->ip,
            'user_agent' => $line->user_agent,
            'context' => $line->context,
            'created_at' => PlatformAdminPresenter::timestamp(moment: $line->created_at),
        ];
    }
}
