<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Banner;
use App\Models\BusinessSetting;
use App\Models\Coupon;
use App\Models\DealOfTheDay;
use App\Models\FeatureDeal;
use App\Models\FlashDeal;
use App\Models\MostDemanded;
use App\Models\OfflinePaymentMethod;
use App\Models\Setting;
use App\Models\StockClearanceSetup;
use App\Models\WithdrawalMethod;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Database\Eloquent\Model;

/**
 * Which records are worth an audit line, and what of them may be written down.
 *
 * The audit log already covered the marketplace, campaigns and the theme engine. It did not reach
 * the changes that decide what a customer pays, which gateway takes the money, who may sign in to
 * the panel, or what an integration is pointed at — the six areas named in the platform control
 * audit. Those are exactly the changes somebody eventually has to reconstruct, and until now they
 * left no actor, no timestamp and no previous value.
 *
 * Two rules shape what is recorded:
 *
 * Everything that changed, not a chosen list. A trail that records the columns somebody thought of
 * is a trail that misses the column added next year. The deny-list below is noise only — timestamps
 * and the values a redactor would blank anyway.
 *
 * And never the secret itself. Half of what settings rows hold is a gateway key, an SMTP password
 * or an API token. The audit log answers "who changed the payment credentials and when", which
 * needs no copy of them; storing one would turn the log into the softest place to steal them from.
 * Every value passes the monitoring redactor on the way in, JSON included, so a key nested inside a
 * settings blob is masked as surely as a column named `password`.
 */
final class AuditTrail
{
    /**
     * How long a single recorded value may be.
     *
     * Settings rows hold whole theme palettes and banner arrays. The trail wants enough to see what
     * changed, not a second copy of the configuration table.
     */
    private const MAX_VALUE_LENGTH = 500;

    /** Attributes never worth a line: they change on every write and carry nothing a reader wants. */
    private const IGNORED = ['updated_at', 'created_at', 'remember_token', 'password', 'auth_token'];

    /**
     * The records that earn a line, and the name their action is filed under.
     *
     * The module is the part before the dot — the audit viewer builds its filter from it — so these
     * group as `promotion`, `payment`, `access` and `settings` beside the modules already there.
     *
     * @var array<class-string, array{module: string, subject: string}>
     */
    private const SUBJECTS = [
        Coupon::class              => ['module' => 'promotion', 'subject' => 'coupon'],
        FlashDeal::class           => ['module' => 'promotion', 'subject' => 'flash_deal'],
        DealOfTheDay::class        => ['module' => 'promotion', 'subject' => 'deal_of_the_day'],
        FeatureDeal::class         => ['module' => 'promotion', 'subject' => 'featured_deal'],
        Banner::class              => ['module' => 'promotion', 'subject' => 'banner'],
        MostDemanded::class        => ['module' => 'promotion', 'subject' => 'most_demanded'],
        StockClearanceSetup::class => ['module' => 'promotion', 'subject' => 'clearance_sale'],

        OfflinePaymentMethod::class => ['module' => 'payment', 'subject' => 'offline_method'],
        WithdrawalMethod::class     => ['module' => 'payment', 'subject' => 'withdrawal_method'],

        AdminRole::class => ['module' => 'access', 'subject' => 'role'],
        Admin::class     => ['module' => 'access', 'subject' => 'employee'],

        BusinessSetting::class => ['module' => 'settings', 'subject' => 'business'],
        Setting::class        => ['module' => 'settings', 'subject' => 'integration'],
    ];

    /**
     * The identifying column for rows whose id means nothing to a reader.
     *
     * "settings row 412 changed" is not an audit line anybody can act on; "mail_config changed" is.
     *
     * @var array<class-string, string>
     */
    private const NAMED_BY = [
        BusinessSetting::class => 'type',
        Setting::class         => 'key_name',
    ];

    /** The action name for an event on this model, or null if the model is not audited. */
    public static function action(Model|string $model, string $event): ?string
    {
        $entry = self::SUBJECTS[is_string($model) ? $model : get_class($model)] ?? null;

        return $entry ? $entry['module'] . '.' . $entry['subject'] . '_' . $event : null;
    }

    public static function isAudited(Model|string $model): bool
    {
        return isset(self::SUBJECTS[is_string($model) ? $model : get_class($model)]);
    }

    /**
     * Reduce a set of attributes to what the trail may keep: no noise, no secrets, nothing huge.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function summarise(array $attributes): array
    {
        $redactor = Redactor::make();
        $summary = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }

            $summary[$key] = $value;
        }

        return $summary === [] ? [] : self::shorten($redactor->array($summary));
    }

    /**
     * What identifies this row to somebody reading the log a year from now.
     *
     * @return array<string, mixed>
     */
    public static function context(Model $model): array
    {
        $column = self::NAMED_BY[get_class($model)] ?? null;

        return $column && $model->{$column} !== null ? [$column => (string) $model->{$column}] : [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function shorten(array $values): array
    {
        foreach ($values as $key => $value) {
            // A settings value is usually a JSON blob, and its secrets are nested inside it rather
            // than named by the column. Decoding it is the only way the redactor can see them.
            if (is_string($value) && str_starts_with(ltrim($value), '{')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = json_encode(Redactor::make()->array($decoded), JSON_UNESCAPED_UNICODE);
                }
            }

            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $value = is_scalar($value) || $value === null ? $value : (string) $value;

            if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
            }

            $values[$key] = $value;
        }

        return $values;
    }
}
