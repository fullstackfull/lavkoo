<?php

namespace App\Observers;

use App\Services\AuditLogger;
use App\Services\AuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Records who changed a promotion, a payment method or a panel account, whoever changed it.
 *
 * Written as an observer for the same reason the price history and the seller webhooks are: a
 * coupon is edited from the admin panel and from the vendor panel, a payment method from two
 * settings screens, an employee's role from three, and the next writer will be added by somebody
 * who never heard of the audit log. A call at each site is a list that goes stale; the model event
 * is the one thing every writer has to pass through.
 *
 * Mass updates are the exception — `where(...)->update(...)` raises no model event at all — and are
 * caught by AuditedBuilder instead, which is why the settings models carry it.
 */
class AuditTrailObserver
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function created(Model $model): void
    {
        $this->write($model, 'created', after: AuditTrail::summarise($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changed = AuditTrail::summarise($model->getChanges());

        // A save that touched only a timestamp is not a change anybody is looking for.
        if ($changed === []) {
            return;
        }

        $this->write(
            $model,
            'updated',
            before: AuditTrail::summarise(array_intersect_key($model->getRawOriginal(), $changed)),
            after: $changed,
        );
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', before: AuditTrail::summarise($model->getAttributes()));
    }

    private function write(Model $model, string $event, ?array $before = null, ?array $after = null): void
    {
        $action = AuditTrail::action($model, $event);

        if ($action === null) {
            return;
        }

        $context = AuditTrail::context($model);

        $this->audit->record(
            action: $action,
            subject: $model,
            before: $before,
            after: $after,
            context: $context === [] ? null : $context,
        );
    }
}
