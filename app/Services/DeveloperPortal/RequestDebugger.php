<?php

namespace App\Services\DeveloperPortal;

use App\Services\Monitoring\Support\Redactor;
use Illuminate\Support\Facades\DB;

/**
 * One request, from its id to everything the system recorded about it.
 *
 * The portal's own Errors advice tells developers to keep the `X-Request-Id` header because it is
 * what makes a failure findable — and the section that would find it was declared in the navigation
 * and never built, so the header was worth keeping and there was nowhere to take it. Monitoring
 * records the id on every error and shares it into every log line; nothing joined the two.
 *
 * The lookup is deliberately narrow. It answers "what happened to THIS request", not "show me
 * everything near this time": an id either matches or it does not, and a debugger that helpfully
 * widened its search would return a different request's stack trace with the confidence of an exact
 * match.
 *
 * Everything printed goes through the Redactor. A stack trace and a request context are the two most
 * reliable places in an application to find a token or a customer's address, and this page is one an
 * operator screenshots.
 */
class RequestDebugger
{
    /** An id is 16 hex characters; anything else never matched a row and is refused before a query. */
    private const ID_PATTERN = '/^[0-9a-f]{8,40}$/i';

    private const MAX_ERRORS = 20;
    private const MAX_SPANS = 200;

    public function __construct(private readonly Redactor $redactor)
    {
    }

    /**
     * @return array{state: string, id: ?string, note: ?string, errors: array<int, array<string, mixed>>,
     *               trace: ?array<string, mixed>, spans: array<int, array<string, mixed>>}
     */
    public function lookup(?string $rawId): array
    {
        $id = trim((string) $rawId);

        if ($id === '') {
            return $this->empty('waiting', 'paste_a_request_id_from_a_response_header_or_a_log_line');
        }

        if (!preg_match(self::ID_PATTERN, $id)) {
            return $this->empty('invalid', 'a_request_id_is_hexadecimal_this_one_cannot_have_come_from_this_system', $id);
        }

        try {
            $errors = $this->errorsFor($id);
            $trace = $this->traceFor($id, $errors);
            $spans = $trace === null ? [] : $this->spansFor((string) $trace['trace_id']);
        } catch (\Throwable $exception) {
            return $this->empty('failed', $this->redactor->text(mb_substr($exception->getMessage(), 0, 300)), $id);
        }

        if ($errors === [] && $trace === null) {
            // Said plainly, because the honest answer has two halves: either the request was fine and
            // was never sampled, or its rows are past retention. Neither is "nothing happened".
            return $this->empty('not_found', 'nothing_was_recorded_under_that_id_a_request_that_neither_failed_nor_was_sampled_leaves_no_row_and_rows_are_pruned_at_the_retention_window', $id);
        }

        return [
            'state' => 'ok',
            'id' => $id,
            'note' => null,
            'errors' => $errors,
            'trace' => $trace,
            'spans' => $spans,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function errorsFor(string $id): array
    {
        return DB::connection(config('monitoring.connection'))->table('monitoring_errors')
            ->where('request_id', $id)
            ->orderByDesc('id')
            ->limit(self::MAX_ERRORS)
            ->get(['id', 'group_id', 'trace_id', 'route', 'method', 'status', 'channel', 'release', 'context', 'stack_trace', 'created_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'group_id' => (int) $row->group_id,
                'trace_id' => $row->trace_id,
                'route' => $this->redactor->text((string) ($row->route ?? '')),
                'method' => $row->method,
                'status' => $row->status,
                'channel' => $row->channel,
                'release' => $row->release,
                'context' => $this->redactor->text(mb_substr((string) ($row->context ?? ''), 0, 4000)),
                'stack_trace' => $this->redactor->text(mb_substr((string) ($row->stack_trace ?? ''), 0, 8000)),
                'created_at' => (string) $row->created_at,
            ])->all();
    }

    /**
     * The trace, found through the error that names it.
     *
     * `monitoring_traces` carries a correlation id rather than a request id, so a request with no
     * error has no join to its trace. That is stated rather than papered over with a time-window
     * guess — see the class note about widening a search.
     *
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function traceFor(string $id, array $errors): ?array
    {
        $traceIds = array_values(array_filter(array_column($errors, 'trace_id')));

        $row = DB::connection(config('monitoring.connection'))->table('monitoring_traces')
            ->when($traceIds !== [], fn ($query) => $query->whereIn('trace_id', $traceIds))
            ->when($traceIds === [], fn ($query) => $query->where('correlation_id', $id))
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'trace_id' => (string) $row->trace_id,
            'route' => $this->redactor->text((string) ($row->route ?? '')),
            'method' => $row->method,
            'status' => $row->status,
            'duration_ms' => $row->duration_ms,
            'db_ms' => $row->db_ms,
            'db_queries' => $row->db_queries,
            'cache_ms' => $row->cache_ms,
            'external_ms' => $row->external_ms,
            'memory_peak_kb' => $row->memory_peak_kb,
            'captured_because' => $row->captured_because,
            'user_type' => $row->user_type,
            'platform' => $row->platform,
            'release' => $row->release,
            'started_at' => (string) $row->started_at,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function spansFor(string $traceId): array
    {
        return DB::connection(config('monitoring.connection'))->table('monitoring_spans')
            ->where('trace_id', $traceId)
            ->orderBy('start_offset_ms')
            ->limit(self::MAX_SPANS)
            ->get()
            ->map(fn (object $row): array => [
                'name' => $this->redactor->text((string) ($row->name ?? '')),
                'kind' => $row->kind ?? null,
                'start_offset_ms' => $row->start_offset_ms ?? null,
                'duration_ms' => $row->duration_ms ?? null,
            ])->all();
    }

    /**
     * @return array{state: string, id: ?string, note: ?string, errors: array<int, mixed>, trace: null, spans: array<int, mixed>}
     */
    private function empty(string $state, string $note, ?string $id = null): array
    {
        return ['state' => $state, 'id' => $id, 'note' => $note, 'errors' => [], 'trace' => null, 'spans' => []];
    }
}
