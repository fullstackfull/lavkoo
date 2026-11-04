<?php

namespace App\Models\Builders;

use App\Services\AuditLogger;
use App\Services\AuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catches the settings writes that raise no model event.
 *
 * The observer beside this one is enough for records the application saves one at a time. Settings
 * are not written that way. `BusinessSetting::where('type', 'mail_config')->update([...])` is a
 * mass update: it never instantiates a model, so `updated` never fires, and an observer on the
 * settings models would record nothing while appearing to work. That pattern is used at roughly a
 * hundred sites — the whole `UpdateClass` trait is built on it — and rewriting them all to save
 * models individually would be a far larger change than the trail is worth.
 *
 * So the builder itself does the recording. Every settings write in the codebase already passes
 * through here, whatever call site issued it, and no call site had to be touched.
 *
 * Reading the rows before writing them costs one extra query per settings save. Settings are saved
 * by an administrator pressing a button, so that is a price nobody will ever notice; it is what
 * makes a previous value available at all.
 */
class AuditedBuilder extends Builder
{
    /**
     * How many affected rows are written out individually.
     *
     * A settings save touches one row, sometimes a handful. A statement that sweeps far more than
     * that is a bulk operation, and a hundred near-identical lines would bury the trail rather than
     * fill it — so those are recorded once, as what they were.
     */
    private const MAX_ROWS = 25;

    public function update(array $values): int
    {
        $before = $this->snapshot();
        $affected = parent::update($values);

        $this->record('updated', $before, $values);

        return $affected;
    }

    public function delete(): mixed
    {
        $before = $this->snapshot();
        $deleted = parent::delete();

        $this->record('deleted', $before, null);

        return $deleted;
    }

    /**
     * @param  array<mixed>  $values
     */
    public function insert(array $values): bool
    {
        $inserted = $this->toBase()->insert($values);

        // One row arrives as a flat map of columns, several as a list of them.
        $rows = array_is_list($values) ? $values : [$values];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $this->write('created', null, is_array($row) ? $row : [], $row);
        }

        return $inserted;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        $existing = $this->clone()->where($attributes)->first();
        $result = $this->toBase()->updateOrInsert($attributes, $values);

        $resolved = is_callable($values) ? [] : $values;

        $this->write(
            $existing ? 'updated' : 'created',
            $existing ? AuditTrail::summarise(array_intersect_key($existing->getRawOriginal(), $resolved)) : null,
            $resolved,
            $existing?->getAttributes() ?? $attributes,
            $existing?->getKey(),
        );

        return $result;
    }

    /**
     * The rows this statement is about to change, read before it changes them.
     *
     * @return \Illuminate\Support\Collection<int, Model>
     */
    private function snapshot(): \Illuminate\Support\Collection
    {
        try {
            return $this->clone()->limit(self::MAX_ROWS + 1)->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Model>  $before
     * @param  array<string, mixed>|null  $values
     */
    private function record(string $event, \Illuminate\Support\Collection $before, ?array $values): void
    {
        if ($before->isEmpty()) {
            return;
        }

        if ($before->count() > self::MAX_ROWS) {
            $this->write($event, null, $values ?? [], [], null, ['rows' => 'more than ' . self::MAX_ROWS]);

            return;
        }

        foreach ($before as $model) {
            $this->write(
                $event,
                AuditTrail::summarise($values === null ? $model->getAttributes() : array_intersect_key($model->getRawOriginal(), $values)),
                $event === 'deleted' ? [] : ($values ?? []),
                $model->getAttributes(),
                $model->getKey(),
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>|Model  $identity
     * @param  array<string, mixed>|null  $extraContext
     */
    private function write(
        string $event,
        ?array $before,
        array $after,
        array|Model $identity,
        int|string|null $key = null,
        ?array $extraContext = null,
    ): void {
        $model = $this->getModel();
        $action = AuditTrail::action($model, $event);

        if ($action === null) {
            return;
        }

        // Name the row by what a reader recognises — `mail_config`, not id 412. The identity comes
        // from the row itself where one was read, and from the values otherwise.
        $named = $identity instanceof Model ? $identity->getAttributes() : $identity;
        $context = AuditTrail::context($model->newInstance($named, true));

        app(AuditLogger::class)->record(
            action: $action,
            subject: $key === null ? null : ['type' => get_class($model), 'id' => $key],
            before: $before === [] ? null : $before,
            after: $after === [] ? null : AuditTrail::summarise($after),
            context: array_merge($context, $extraContext ?? []) ?: null,
        );
    }
}
