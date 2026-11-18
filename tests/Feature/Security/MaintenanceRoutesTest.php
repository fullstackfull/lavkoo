<?php

namespace Tests\Feature\Security;

use FilesystemIterator;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A route file must never hold maintenance work that a web request can trigger: no closure may run commands, change permissions or
 * write files, or compare a request value with a key written in the code. This pins that the one-off maintenance routes that used to
 * sit in routes/web/routes.php are gone (with no route, the web server answers 404 whatever is sent), and fails if a route file ever
 * again runs commands, touches permissions or files, or holds such a key. Maintenance belongs in an artisan command or behind the
 * admin sign-in.
 */
class MaintenanceRoutesTest extends TestCase
{
    private const REMOVED_PATHS = ['diag-write-xk29', 'find-img-xk29', 'run-fix-xk29'];

    /** What no route file may contain: each pattern is a way to make a web request do the work of an operator. */
    private const FORBIDDEN_IN_ROUTE_FILES = [
        'a key written in the code' => '/hash_equals\(\s*[\'"]/',
        'a permission change' => '/\bchmod\s*\(/',
        'an artisan command' => '/Artisan::call\s*\(/',
        'a shell command' => '/\b(?:shell_exec|passthru|proc_open|popen|exec|system)\s*\(/',
        'a file written or deleted' => '/\b(?:file_put_contents|unlink)\s*\(/',
    ];

    public function test_the_maintenance_routes_are_not_registered(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => ltrim($route->uri(), '/'));

        foreach (self::REMOVED_PATHS as $path) {
            $this->assertFalse($registered->contains($path), "/{$path} is registered again: with no route the web server answers 404 whatever key is sent");
        }
    }

    public function test_no_route_file_runs_commands_changes_permissions_or_holds_a_key(): void
    {
        $offences = [];
        foreach ($this->routeFiles() as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::FORBIDDEN_IN_ROUTE_FILES as $what => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offences[] = str_replace(base_path() . '/', '', $file) . ' contains ' . $what;
                }
            }
        }

        $this->assertSame([], $offences, 'Maintenance belongs in an artisan command or behind the admin sign-in, never in a route closure.');
    }

    /** @return list<string> */
    private function routeFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('routes'), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $this->assertNotSame([], $files, 'no route file was found: the scan would pass on nothing');

        return $files;
    }
}
