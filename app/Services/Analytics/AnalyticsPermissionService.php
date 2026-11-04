<?php

namespace App\Services\Analytics;

/**
 * Who may see which analytics.
 *
 * Analytics says more about a business than almost any other screen — what sells, what does not,
 * where the customers come from, what they searched for and could not find. So it is not one
 * permission. It follows the pattern MonitoringPermissionService established, for the practical
 * reason that an administrator should not have to learn two different permission models in the
 * same panel.
 *
 * The split is by what the data would reveal if it left the building: the reports themselves,
 * versus individual visitor journeys, versus the campaign links that can be created and pointed
 * anywhere on the allow-list, versus the settings that change what is collected at all.
 */
class AnalyticsPermissionService
{
    /** Read the reports: traffic, sources, products, funnels, revenue. */
    public const VIEW = 'analytics_view';

    /**
     * Follow one visitor through their sessions.
     *
     * Separate from VIEW because it is the one screen that is about a person rather than a
     * population, even though the identifier is a random cookie value rather than a name.
     */
    public const JOURNEYS = 'analytics_journeys';

    /** Create and retire campaign links. Creating one issues a public URL on this domain. */
    public const CAMPAIGNS = 'analytics_campaigns';

    /** Export a report as a file that then lives outside the panel entirely. */
    public const EXPORT = 'analytics_export';

    /** Change what is collected, what is excluded, and how long it is kept. */
    public const SETTINGS = 'analytics_settings';

    /** The coarse module key an existing role may already hold. */
    private const MODULE = 'reports';

    /**
     * What holding the coarse module grants on its own.
     *
     * Only reading. A role that could already open Reports keeps working exactly as it did; the
     * capabilities that expose an individual, create a public link, or take data out of the panel
     * have to be granted deliberately.
     */
    private const IMPLIED_BY_MODULE = [self::VIEW];

    /**
     * Dotted spellings, accepted as aliases.
     *
     * The convention in this project is analytics_view; a specification or an import may say
     * analytics.view. Both resolve, so a role stored either way works.
     */
    private const ALIASES = [
        'analytics.view' => self::VIEW,
        'analytics.journeys' => self::JOURNEYS,
        'analytics.campaigns' => self::CAMPAIGNS,
        'analytics.export' => self::EXPORT,
        'analytics.settings' => self::SETTINGS,
    ];

    /**
     * Every capability, for the role editor, ordered least to most sensitive.
     *
     * @return array<string, string>  capability => translation key
     */
    public static function all(): array
    {
        return [
            self::VIEW => 'analytics_view_reports',
            self::EXPORT => 'analytics_export_reports',
            self::CAMPAIGNS => 'analytics_manage_campaign_links',
            self::JOURNEYS => 'analytics_follow_individual_visitors',
            self::SETTINGS => 'analytics_change_collection_settings',
        ];
    }

    public function can(?string $capability): bool
    {
        if ($capability === null) {
            return false;
        }

        $admin = auth('admin')->user();

        if ($admin === null) {
            return false;
        }

        // The master administrator holds everything, including capabilities added after their role
        // was created — otherwise every new capability would silently lock out the one account
        // that is supposed to be able to grant it.
        if ((int) ($admin->admin_role_id ?? 0) === 1) {
            return true;
        }

        $granted = $this->grantedKeys($admin);

        if (in_array($capability, $granted, true)) {
            return true;
        }

        return in_array(self::MODULE, $granted, true)
            && in_array($capability, self::IMPLIED_BY_MODULE, true);
    }

    /** Which capability a section of the Analytics area requires. */
    public function capabilityForSection(string $section): string
    {
        return match ($section) {
            'journeys' => self::JOURNEYS,
            'campaigns' => self::CAMPAIGNS,
            'settings' => self::SETTINGS,
            default => self::VIEW,
        };
    }

    /**
     * @return array<int, string>
     */
    private function grantedKeys(object $admin): array
    {
        $decoded = json_decode((string) ($admin->role->module_access ?? ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        $keys = [];

        foreach ($decoded as $entry) {
            if (is_string($entry)) {
                $keys[] = self::ALIASES[$entry] ?? $entry;
            }
        }

        return $keys;
    }
}
