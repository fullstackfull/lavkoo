<?php

namespace App\Console\Commands;

use App\Models\BusinessSetting;
use App\Services\DeepLink\AppLinkService;
use App\Services\DeepLink\AssociationFileWriter;
use Illuminate\Console\Command;

/**
 * Republish the app association files from the stored setup.
 *
 * Needed because the published path list lives in config/deeplinks.php while the file that carries
 * it is written on disk. Deploying a change to that list — campaign short links becoming app links,
 * for instance — changes nothing on a running site until the file is rewritten, and the only thing
 * that used to rewrite it was an administrator pressing save on a form. This is the deployment step
 * that closes that gap; it is safe to run on every deploy.
 */
class DeepLinkPublish extends Command
{
    protected $signature = 'deeplinks:publish {--check : Report what would change without writing}';

    protected $description = 'Write .well-known/assetlinks.json and apple-app-site-association from the stored app deep-link setup';

    public function handle(AppLinkService $links, AssociationFileWriter $writer): int
    {
        // A deploy step runs before migrations as often as after them, and a command that fatals
        // on a half-built database turns a missing association file into a failed deployment.
        try {
            $stored = BusinessSetting::where('type', 'app_deep_link')->value('value');
        } catch (\Throwable $exception) {
            $this->error('The settings table could not be read: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $settings = json_decode((string) $stored, true) ?: [];

        if ($settings === []) {
            $this->warn('No app deep-link setup is stored. Nothing to publish.');
            $this->line('Set it in Admin → System Setup → App Deep Link first.');

            return self::SUCCESS;
        }

        $intended = $links->paths(AppLinkService::PLATFORM_IOS);
        $published = $writer->published();

        $this->line('Published paths: ' . ($published['paths'] === [] ? 'none' : implode(', ', $published['paths'])));
        $this->line('Configured paths: ' . implode(', ', $intended));

        if ($this->option('check')) {
            $stale = $published['exists'] && $published['paths'] !== $intended;
            $this->line($stale ? 'The files on disk are out of date.' : 'The files on disk match the configuration.');

            return $stale ? self::FAILURE : self::SUCCESS;
        }

        $failures = 0;

        foreach ($writer->publish($settings) as $result) {
            if ($result['written']) {
                $this->info('wrote ' . $result['path']);
                continue;
            }

            if ($result['reason'] === 'not_configured') {
                $this->line('skipped ' . $result['path'] . ' — that platform is not set up');
                continue;
            }

            $failures++;
            $this->error('failed ' . $result['path'] . ' — ' . $result['reason']);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
