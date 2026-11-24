<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * What runs away from the request: every class under app/Jobs and app/Listeners that implements ShouldQueue, and every bulk route of the
 * admin panel (GCC-14c; G-11).
 *
 * It reads source text, which is what makes it a guard rather than a report: a job added to either directory, or a bulk route added to the
 * admin panel, appears here without anybody remembering to say so, and StaffJobInventoryTest then fails until somebody classifies it.
 */
final class StaffJobScanner
{
    private const ROOTS = ['app/Jobs', 'app/Listeners'];

    /** Where the platform's schedule is defined; bootstrap/app.php hands this class to withSchedule(). */
    private const SCHEDULE = 'app/Console/ScheduleDefinition.php';

    /** A trait or an interface is not a job: only a class that is itself queued. */
    private const QUEUED_CLASS = '/^\s*(?:final\s+)?class\s+(\w+)[^{]*\bimplements\b[^{]*\bShouldQueue\b/m';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> the fully qualified class => its path, relative to the project root, sorted */
    public function queuedClasses(): array
    {
        $found = [];

        foreach (self::ROOTS as $root) {
            $directory = self::root() . '/' . $root;
            if (!is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                if (preg_match(self::QUEUED_CLASS, $source, $class) !== 1 || preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                    continue;
                }

                $found[trim($namespace[1]) . '\\' . $class[1]] = substr($file->getPathname(), strlen(self::root()) + 1);
            }
        }

        ksort($found);

        return $found;
    }

    /** @return list<string> the directories the scan reads, so that one narrowed or renamed fails the test instead of making it pass by reading less */
    public function roots(): array
    {
        return self::ROOTS;
    }

    /**
     * The bulk routes of the admin panel, as the route table really holds them: a write whose path or action says it acts on a list.
     *
     * @return array<string, string> "POST uri" => the action, sorted
     */
    public function bulkRoutes(): array
    {
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (!$this->isAdminWrite($route) || preg_match('#(^|[/-])bulk([/-]|$)#i', $route->uri()) !== 1) {
                continue;
            }

            $found[implode('|', array_diff($route->methods(), ['HEAD'])) . ' ' . $route->uri()] = (string) $route->getActionName();
        }

        ksort($found);

        return $found;
    }

    /**
     * Every entry of the platform's schedule, as the schedule itself names it: a command by its signature, a closure by the name the
     * schedule gives it. A commented-out line is not an entry.
     *
     * @return list<string> sorted
     */
    public function scheduledEntries(): array
    {
        $source = (string) file_get_contents(self::root() . '/' . self::SCHEDULE);
        $entries = [];

        foreach (explode("\n", $source) as $line) {
            if (preg_match('/^\s*(?:\/\/|\*|#)/', $line) === 1) {
                continue;
            }
            if (preg_match('/\$schedule->command\(\s*[\'"]([^\'"]+)[\'"]/', $line, $command) === 1) {
                $entries[] = 'command: ' . $command[1];
            }
            if (preg_match('/\$schedule->(call|job|exec)\(/', $line, $other) === 1) {
                $entries[] = $other[1] . ': ' . (preg_match('/->name\(\s*[\'"]([^\'"]+)[\'"]/', $source, $name) === 1 ? $name[1] : 'unnamed');
            }
        }

        sort($entries);

        return $entries;
    }

    /** The source of the schedule, so a guard can read what it does as well as what it holds. */
    public function scheduleSource(): string
    {
        return (string) file_get_contents(self::root() . '/' . self::SCHEDULE);
    }

    private function isAdminWrite(RoutingRoute $route): bool
    {
        $middleware = [];
        foreach ((array) ($route->getAction('middleware') ?? []) as $entry) {
            $middleware[] = is_string($entry) ? explode(':', $entry)[0] : '';
        }

        return in_array('admin', $middleware, true) && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
    }
}
