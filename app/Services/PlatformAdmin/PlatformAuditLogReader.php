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
            'lines' => $lines->take($pagination->perPage)->map(fn (PlatformAdminAuditLog $line): array => $this->presented(line: $line, principal: $principal))->values()->all(),
            'has_more' => $lines->count() > $pagination->perPage,
        ];
    }

    /** @return array<string, mixed> */
    private function presented(PlatformAdminAuditLog $line, PlatformAdminPrincipal $principal): array
    {
        return [
            'id' => (int) $line->getKey(),
            'admin_id' => $line->admin_id,
            'action' => $line->action,
            'market' => $line->market,
            'token_id' => $line->token_id,
            'ip' => $line->ip,
            'user_agent' => $line->user_agent,
            'context' => $this->contextShownTo(principal: $principal, context: $line->context),
            'created_at' => PlatformAdminPresenter::timestamp(moment: $line->created_at),
        ];
    }

    /**
     * What a line says, less what the reader may not see: the operator of a console command (the user and the host it ran as) is for the platform owner,
     * not for a restricted admin who is reading their own lines. Only the member at the top is taken away.
     *
     * @param array<string, mixed>|null $context
     *
     * @return array<string, mixed>|null
     */
    private function contextShownTo(PlatformAdminPrincipal $principal, ?array $context): ?array
    {
        if ($context === null || $principal->isPlatformOwner()) {
            return $context;
        }

        unset($context['operator']);

        return $context === [] ? null : $context;
    }
}
