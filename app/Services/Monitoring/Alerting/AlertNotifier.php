<?php

namespace App\Services\Monitoring\Alerting;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells somebody, without ever being able to break the thing it is reporting on.
 *
 * Every send is best-effort and swallowed on failure by design: an alert about a database outage
 * must not throw because the mail server is on the same machine that is having the outage. The log
 * line is written first and always, so there is a record even when every channel fails.
 */
class AlertNotifier
{
    public function fired(object $rule, string $state, float $value, ?int $incidentId): void
    {
        $subject = sprintf(
            '[%s] %s is %s: %s = %s',
            strtoupper($state),
            config('app.name'),
            $state === 'critical' ? 'critical' : 'in warning',
            $rule->metric . ($rule->label !== '' ? "@{$rule->label}" : ''),
            $this->format($value),
        );

        $this->log($state === 'critical' ? 'critical' : 'warning', $subject, $rule, $value, $incidentId);
        $this->mail($rule, $subject, $this->body($rule, $state, $value, $incidentId));
    }

    public function recovered(object $rule, ?float $value): void
    {
        $subject = sprintf('[RECOVERED] %s: %s is back within range', config('app.name'), $rule->metric);

        $this->log('info', $subject, $rule, $value, null);
        $this->mail($rule, $subject, sprintf(
            "%s recovered at %s.\n\nMetric: %s\nCurrent value: %s\n",
            $rule->name,
            Clock::display()->toDayDateTimeString(),
            $rule->metric,
            $value === null ? 'no reading' : $this->format($value),
        ));
    }

    private function log(string $level, string $subject, object $rule, ?float $value, ?int $incidentId): void
    {
        try {
            Log::log($level, $subject, [
                'rule' => $rule->key,
                'metric' => $rule->metric,
                'label' => $rule->label,
                'value' => $value,
                'incident_id' => $incidentId,
                'channel' => 'monitoring-alert',
            ]);
        } catch (\Throwable) {
            // Nothing left to escalate to.
        }
    }

    private function mail(object $rule, string $subject, string $body): void
    {
        if (!$rule->notify_email) {
            return;
        }

        $recipients = $this->recipients($rule);

        if ($recipients === []) {
            return;
        }

        try {
            Mail::raw($body, function ($message) use ($recipients, $subject) {
                $message->to($recipients)->subject($subject);
            });
        } catch (\Throwable $exception) {
            // Report the failure to send rather than the alert disappearing silently.
            Log::warning('A monitoring alert could not be emailed.', [
                'subject' => $subject,
                'error' => Metric::describeFailure($exception),
            ]);
        }
    }

    /** @return array<int, string> */
    private function recipients(object $rule): array
    {
        $configured = (string) ($rule->notify_channels ?? '');
        $addresses = array_filter(
            array_map('trim', explode(',', $configured)),
            static fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        );

        if ($addresses !== []) {
            return array_values($addresses);
        }

        $fallback = config('mail.from.address');

        return is_string($fallback) && filter_var($fallback, FILTER_VALIDATE_EMAIL) !== false ? [$fallback] : [];
    }

    private function body(object $rule, string $state, float $value, ?int $incidentId): string
    {
        $threshold = $state === 'critical' ? $rule->critical_threshold : $rule->warning_threshold;

        return implode("\n", array_filter([
            $rule->name,
            $rule->description ?: null,
            '',
            'Metric:    ' . $rule->metric . ($rule->label !== '' ? '@' . $rule->label : ''),
            'Condition: ' . $rule->operator . ' ' . $this->format((float) $threshold),
            'Measured:  ' . $this->format($value),
            'Held for:  ' . $rule->for_seconds . ' seconds',
            'At:        ' . Clock::display()->toDayDateTimeString() . ' (' . Clock::displayTimezone() . ')',
            $incidentId !== null ? 'Incident:  #' . $incidentId : null,
            '',
            rtrim((string) config('app.url'), '/') . '/admin/monitoring/alerts',
        ], static fn ($line) => $line !== null));
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
