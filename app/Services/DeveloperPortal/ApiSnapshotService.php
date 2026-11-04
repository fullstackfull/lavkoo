<?php

namespace App\Services\DeveloperPortal;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers what the API used to look like, so it can say what changed and who it broke.
 *
 * Every other part of this portal derives from the code as it is now. This is the one part that
 * cannot: "you removed a required field" is not a fact about the current code, it is a fact about
 * the difference between two versions of it. So a snapshot is taken at each release, and the diff
 * between two snapshots is the changelog.
 *
 * The severities are chosen from the caller's point of view, not ours. Anything that makes a
 * request that used to succeed start failing is BREAKING — a removed route, a removed field, a
 * newly required field, a tightened type, an endpoint that suddenly needs a token. Anything that
 * changes behaviour a careful client would notice is a WARNING. Everything else is additive, and
 * additive changes are the ones nobody needs to be woken up about.
 */
class ApiSnapshotService
{
    public function __construct(private readonly ApiManifest $manifest)
    {
    }

    /**
     * Freeze the current API surface.
     *
     * @return array{id: int, label: string, endpoints: int}
     */
    public function capture(string $label, ?int $userId = null): array
    {
        if (!$this->ready()) {
            return ['id' => 0, 'label' => $label, 'endpoints' => 0, 'unavailable' => true];
        }

        $manifest = $this->manifest->get();
        $comparable = $this->comparable($manifest['endpoints'] ?? []);

        $id = (int) DB::table('api_snapshots')->insertGetId([
            'label' => mb_substr($label, 0, 96),
            'app_version' => $manifest['app_version'] ?? null,
            'fingerprint' => $manifest['fingerprint'],
            'endpoint_count' => count($comparable),
            'payload' => (string) json_encode($comparable),
            'captured_by' => $userId,
            'captured_at' => Carbon::now(),
        ]);

        return ['id' => $id, 'label' => $label, 'endpoints' => count($comparable)];
    }

    /**
     * @return array<int, object>
     *
     * Every read here tolerates the tables being absent. A deployment that ships code before it
     * runs migrations is normal, and the documentation portal going down in that window is a
     * self-inflicted outage on a screen whose whole purpose is to explain the system.
     */
    public function snapshots(int $limit = 25): array
    {
        if (!$this->ready()) {
            return [];
        }

        try {
            return DB::table('api_snapshots')
                ->orderByDesc('captured_at')
                ->limit($limit)
                ->get(['id', 'label', 'app_version', 'endpoint_count', 'captured_at'])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function latest(): ?object
    {
        if (!$this->ready()) {
            return null;
        }

        try {
            return DB::table('api_snapshots')->orderByDesc('captured_at')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Have the portal's own migrations been run on this installation? */
    public function ready(): bool
    {
        try {
            return Schema::hasTable('api_snapshots') && Schema::hasTable('api_changes');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Compare a stored snapshot against the live API, or against another snapshot.
     *
     * @return array<string, mixed>
     */
    public function diff(int $fromId, ?int $toId = null): array
    {
        $from = $this->payload($fromId);

        if ($from === null) {
            return ['error' => 'That snapshot no longer exists.', 'changes' => []];
        }

        $to = $toId !== null
            ? $this->payload($toId)
            : $this->comparable($this->manifest->get()['endpoints'] ?? []);

        if ($to === null) {
            return ['error' => 'The snapshot being compared against no longer exists.', 'changes' => []];
        }

        $changes = array_merge(
            $this->removed($from, $to),
            $this->added($from, $to),
            $this->modified($from, $to),
        );

        // usort wants an int. An array is truthy, so every comparison returned 1 and the list came
        // back in reverse insertion order — the breaking changes this whole service exists to
        // surface were wherever they happened to fall.
        $rank = ['breaking' => 0, 'warning' => 1, 'none' => 2];

        usort($changes, static fn (array $a, array $b) => [$rank[$a['severity']] ?? 3, $a['endpoint']]
            <=> [$rank[$b['severity']] ?? 3, $b['endpoint']]);

        return [
            'from' => $fromId,
            'to' => $toId,
            'changes' => $changes,
            'summary' => [
                'total' => count($changes),
                'breaking' => count(array_filter($changes, static fn (array $c) => $c['severity'] === 'breaking')),
                'warning' => count(array_filter($changes, static fn (array $c) => $c['severity'] === 'warning')),
                'added' => count(array_filter($changes, static fn (array $c) => $c['change_type'] === 'added')),
                'removed' => count(array_filter($changes, static fn (array $c) => $c['change_type'] === 'removed')),
            ],
        ];
    }

    /**
     * Take a snapshot AND record the diff against the previous one as changelog entries.
     *
     * This is what a deployment calls: one command, and the API changelog writes itself.
     *
     * @return array<string, mixed>
     */
    public function captureAndRecord(string $label, ?int $userId = null): array
    {
        $previous = $this->latest();
        $captured = $this->capture($label, $userId);

        // 'first' means "there was nothing to compare against", which is a success. A capture that
        // could not happen at all is not: reporting both the same way told an operator "first
        // snapshot captured" over a table that does not exist.
        if ($captured['unavailable'] ?? false) {
            return $captured + ['changes' => 0, 'breaking' => 0, 'first' => false];
        }

        if ($previous === null) {
            return $captured + ['changes' => 0, 'breaking' => 0, 'first' => true];
        }

        $diff = $this->diff((int) $previous->id, $captured['id']);
        $rows = [];
        $now = Carbon::now();

        foreach ($diff['changes'] as $change) {
            $rows[] = [
                'snapshot_id' => $captured['id'],
                'endpoint_id' => $change['endpoint_id'],
                'endpoint' => mb_substr($change['endpoint'], 0, 191),
                'change_type' => $change['change_type'],
                'detail_type' => $change['detail_type'],
                'detail' => $change['detail'],
                'severity' => $change['severity'],
                'audience' => $change['audience'],
                'version' => $change['version'],
                'detected_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('api_changes')->insert($chunk);
        }

        return $captured + [
            'changes' => count($rows),
            'breaking' => $diff['summary']['breaking'],
            'first' => false,
        ];
    }

    /**
     * The generated changelog.
     *
     * @return array<int, object>
     */
    public function changelog(int $limit = 200, ?string $severity = null): array
    {
        if (!$this->ready()) {
            return [];
        }

        try {
            return DB::table('api_changes')
            ->when($severity !== null, fn ($query) => $query->where('severity', $severity))
            ->orderByDesc('detected_at')
                ->orderByRaw("FIELD(severity, 'breaking', 'warning', 'none')")
                ->limit($limit)
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The parts of an endpoint that constitute its contract.
     *
     * Deliberately narrow: a changed summary or a renamed controller is not a change a caller can
     * observe, and putting them in here would bury the removals under noise nobody needs.
     *
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<string, array<string, mixed>>
     */
    private function comparable(array $endpoints): array
    {
        $comparable = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['surface'] !== 'api') {
                continue;
            }

            $fields = [];

            foreach ($endpoint['body'] as $field) {
                $fields[$field['name']] = [
                    'type' => $field['type'] ?? 'string',
                    'required' => (bool) ($field['required'] ?? false),
                    'enum' => $field['enum'] ?? null,
                ];
            }

            ksort($fields);

            $comparable[$endpoint['id']] = [
                'endpoint' => implode('|', $endpoint['methods']) . ' ' . $endpoint['path'],
                'methods' => $endpoint['methods'],
                'path' => $endpoint['path'],
                'audience' => $endpoint['audience'],
                'version' => $endpoint['version'],
                'auth_required' => (bool) ($endpoint['auth']['required'] ?? false),
                'auth_mechanism' => $endpoint['auth']['mechanism'] ?? 'public',
                'permissions' => $endpoint['permissions'],
                'rate_limit' => $endpoint['rate_limit']['requests'] ?? null,
                'deprecated' => (bool) $endpoint['deprecated'],
                'fields' => $fields,
            ];
        }

        return $comparable;
    }

    /**
     * @param  array<string, array<string, mixed>>  $from
     * @param  array<string, array<string, mixed>>  $to
     * @return array<int, array<string, mixed>>
     */
    private function removed(array $from, array $to): array
    {
        $changes = [];

        $byPath = $this->indexByPath($to);

        foreach ($from as $id => $endpoint) {
            // Still served at the same path under a different method set: that is a narrowing,
            // reported as method_removed, not a removal that 404s.
            if (isset($to[$id]) || isset($byPath[$endpoint['path']])) {
                continue;
            }

            // A removed endpoint that was already deprecated was announced; one that was not is
            // the single most damaging change an API can ship.
            $changes[] = $this->change($id, $endpoint, 'removed', 'endpoint_removed',
                $endpoint['deprecated']
                    ? 'Removed after being marked deprecated.'
                    : 'Removed without ever being marked deprecated. Any client still calling it now gets a 404.',
                'breaking',
            );
        }

        return $changes;
    }

    /**
     * @param  array<string, array<string, mixed>>  $from
     * @param  array<string, array<string, mixed>>  $to
     * @return array<int, array<string, mixed>>
     */
    private function added(array $from, array $to): array
    {
        $changes = [];
        $byPath = $this->indexByPath($from);

        foreach ($to as $id => $endpoint) {
            // A path that already existed under a different method set is not a new endpoint.
            if (!isset($from[$id]) && !isset($byPath[$endpoint['path']])) {
                $changes[] = $this->change($id, $endpoint, 'added', 'endpoint_added', 'New endpoint.', 'none');
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, array<string, mixed>>  $endpoints
     * @return array<string, array<string, mixed>>
     */
    private function indexByPath(array $endpoints): array
    {
        $byPath = [];

        foreach ($endpoints as $endpoint) {
            $byPath[$endpoint['path']] ??= $endpoint;
        }

        return $byPath;
    }

    /**
     * @param  array<string, array<string, mixed>>  $from
     * @param  array<string, array<string, mixed>>  $to
     * @return array<int, array<string, mixed>>
     */
    private function modified(array $from, array $to): array
    {
        $changes = [];

        // Matched by PATH, not by id. The id is a hash of the methods AND the uri, so narrowing a
        // route from any-verb to GET produced a different id — the endpoint read as removed and a
        // new one added, method_removed below could never fire, and the removal's detail said
        // callers "now get a 404", which was simply untrue.
        $byPath = $this->indexByPath($from);

        foreach ($to as $id => $now) {
            $before = $from[$id] ?? ($byPath[$now['path']] ?? null);

            if ($before === null) {
                continue;
            }

            foreach ($this->compareOne($before, $now) as [$detailType, $detail, $severity]) {
                $changes[] = $this->change($id, $now, 'changed', $detailType, $detail, $severity);
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $now
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function compareOne(array $before, array $now): array
    {
        $found = [];

        $droppedMethods = array_diff($before['methods'], $now['methods']);
        if ($droppedMethods !== []) {
            $found[] = ['method_removed', 'No longer accepts ' . implode(', ', $droppedMethods) . '.', 'breaking'];
        }

        if (!$before['auth_required'] && $now['auth_required']) {
            $found[] = ['auth_added', 'Now requires authentication (' . $now['auth_mechanism'] . '). Unauthenticated callers that worked before will get a 401.', 'breaking'];
        }

        if ($before['auth_required'] && !$now['auth_required']) {
            // Not breaking for callers, but a security-relevant change somebody should look at.
            $found[] = ['auth_removed', 'No longer requires authentication. Confirm this is intended.', 'warning'];
        }

        if ($before['auth_mechanism'] !== $now['auth_mechanism'] && $before['auth_required'] && $now['auth_required']) {
            $found[] = ['auth_mechanism_changed', "Authentication changed from {$before['auth_mechanism']} to {$now['auth_mechanism']}. Existing tokens will not work.", 'breaking'];
        }

        $newPermissions = array_diff($now['permissions'], $before['permissions']);
        if ($newPermissions !== []) {
            $found[] = ['permission_added', 'Now also requires: ' . implode(', ', $newPermissions) . '.', 'breaking'];
        }

        if ($now['rate_limit'] !== null && $before['rate_limit'] !== null && $now['rate_limit'] < $before['rate_limit']) {
            $found[] = ['rate_limit_lowered', "Rate limit lowered from {$before['rate_limit']} to {$now['rate_limit']} per window.", 'warning'];
        }

        if (!$before['deprecated'] && $now['deprecated']) {
            $found[] = ['deprecated', 'Marked deprecated.', 'warning'];
        }

        return array_merge($found, $this->compareFields($before['fields'], $now['fields']));
    }

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $now
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function compareFields(array $before, array $now): array
    {
        $found = [];

        foreach ($before as $name => $field) {
            if (!isset($now[$name])) {
                // Warning, not breaking, by this service's own definition: a request that used to
                // succeed still succeeds — the parameter is ignored. Grading it breaking buried the
                // changes that genuinely reject a previously-valid call.
                $found[] = ['param_removed', "The `{$name}` parameter is gone. A client still sending it is now ignored; one reading it back will not find it.", 'warning'];
            }
        }

        foreach ($now as $name => $field) {
            $was = $before[$name] ?? null;

            if ($was === null) {
                // A NEW REQUIRED FIELD is the quiet killer: every existing client suddenly fails
                // validation on a call that has worked for a year.
                $found[] = $field['required']
                    ? ['required_param_added', "A new REQUIRED parameter `{$name}` was added. Every existing caller now fails validation.", 'breaking']
                    : ['param_added', "New optional parameter `{$name}`.", 'none'];

                continue;
            }

            if (!$was['required'] && $field['required']) {
                $found[] = ['param_became_required', "`{$name}` is now required.", 'breaking'];
            }

            if ($was['type'] !== $field['type']) {
                $found[] = ['param_type_changed', "`{$name}` changed type from {$was['type']} to {$field['type']}.", 'breaking'];
            }

            $wasEnum = $was['enum'] ?? [];
            $nowEnum = $field['enum'] ?? [];

            $lostValues = array_diff($wasEnum, $nowEnum);
            if ($wasEnum !== [] && $lostValues !== []) {
                $found[] = ['enum_value_removed', "`{$name}` no longer accepts: " . implode(', ', $lostValues) . '.', 'breaking'];
            }

            // Unconstrained to a fixed list is the same breakage with none of the evidence: every
            // value outside the new list used to be accepted and is now rejected, and nothing here
            // used to say so at all.
            if ($wasEnum === [] && $nowEnum !== []) {
                $found[] = ['enum_added', "`{$name}` now accepts only: " . implode(', ', $nowEnum)
                    . '. Any other value a caller was sending is now rejected.', 'breaking'];
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function change(string $id, array $endpoint, string $type, string $detailType, string $detail, string $severity): array
    {
        return [
            'endpoint_id' => $id,
            'endpoint' => $endpoint['endpoint'],
            'change_type' => $type,
            'detail_type' => $detailType,
            'detail' => $detail,
            'severity' => $severity,
            'audience' => $endpoint['audience'],
            'version' => $endpoint['version'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function payload(int $id): ?array
    {
        // Guarded like every other read of these tables. Code reaches a server before its migration
        // does — that is exactly how /admin/developer returned a 500 in production — and this one
        // is reachable from `php artisan api:snapshot --diff=N` as well as from the page.
        if (!$this->ready()) {
            return null;
        }

        $row = DB::table('api_snapshots')->where('id', $id)->first(['payload']);

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row->payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
