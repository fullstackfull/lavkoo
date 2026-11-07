<?php

namespace Tests\Unit\SalesChannels;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * "Nothing leaves the server except through ChannelHttp" is the safety model of the sales-channel modules (every switch, the allowed-operations list, the audit log and the
 * redaction live there), so it is checked in the source: no other file of the modules may open a connection of its own.
 */
class SingleHttpExitTest extends TestCase
{
    private const TRANSPORT = 'Services/SalesChannels/Support/ChannelHttp.php';

    private const OUTBOUND = '/\bHttp::|Facades\\\\Http\b|GuzzleHttp|\bcurl_\w+\(|file_get_contents\(\s*[\'"]https?:|\bfopen\(\s*[\'"]https?:|\bfsockopen\(|\bstream_socket_client\(|\bnew\s+\\\\?Client\(/';

    public function test_the_transport_is_the_only_code_of_the_modules_that_reaches_out_of_the_server(): void
    {
        $offenders = [];
        foreach ($this->moduleFiles() as $file) {
            if (!str_ends_with($file, self::TRANSPORT) && preg_match(self::OUTBOUND, (string) file_get_contents($file)) === 1) {
                $offenders[] = str_replace(base_path() . '/', '', $file);
            }
        }

        $this->assertSame([], $offenders, 'These files open a connection of their own; it has to go through ChannelHttp.');
    }

    public function test_the_check_sees_the_transport_itself_and_reads_the_modules_it_claims_to(): void
    {
        $files = $this->moduleFiles();

        $this->assertSame(1, preg_match(self::OUTBOUND, (string) file_get_contents(app_path(self::TRANSPORT))), 'A guard that cannot see the transport proves nothing.');
        $this->assertContains(app_path('Services/SalesChannels/Amazon/AmazonConnector.php'), $files);
        $this->assertContains(app_path('Services/SalesChannels/Trendyol/TrendyolConnector.php'), $files);
        $this->assertContains(app_path('Services/OperationalCosts/CostLedger.php'), $files);
        $this->assertContains(app_path('Console/Commands/SalesChannelsSyncCommand.php'), $files);
        $this->assertContains(app_path('Http/Controllers/Admin/SalesChannels/ChannelAccountOperationsController.php'), $files);
        $this->assertContains(app_path('Http/Controllers/Api/TrendyolWebhookController.php'), $files);
        $this->assertGreaterThan(150, count($files));
    }

    /** @return list<string> */
    private function moduleFiles(): array
    {
        $files = [];
        foreach (['Services/SalesChannels', 'Services/OperationalCosts', 'Http/Controllers/Admin/SalesChannels', 'Http/Controllers/Admin/OperationalCosts', 'Http/Requests/Admin/OperationalCosts'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($directory), FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return array_values(array_unique([
            ...$files,
            app_path('Http/Controllers/Api/TrendyolWebhookController.php'),
            ...glob(app_path('Console/Commands/SalesChannels*Command.php')),
            ...glob(app_path('Console/Commands/Costs*Command.php')),
        ]));
    }
}
