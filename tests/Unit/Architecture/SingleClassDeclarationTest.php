<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every class, interface, trait and enum the application declares is declared by exactly one file.
 *
 * A second file under the same name is dead code at best: it is only loaded when something includes it by path, and then PHP refuses
 * to declare the class twice. The repository had two such pairs: App\Services\PaymentRoutingService, a copy of the Marketplace service,
 * and App\Http\Controllers\Payment_Methods\PaymeraController, whose copy under Payment/ carries debug dd() calls.
 */
class SingleClassDeclarationTest extends TestCase
{
    public function test_every_declared_type_is_declared_by_exactly_one_file(): void
    {
        $declaredIn = [];
        foreach ($this->phpFilesIn(['app', 'Modules']) as $file) {
            foreach ($this->typesDeclaredIn($file) as $type) {
                $declaredIn[$type][] = $file;
            }
        }

        $duplicates = array_filter($declaredIn, static fn (array $files): bool => count($files) > 1);

        $this->assertSame([], $duplicates);
    }

    /** @return list<string> */
    private function phpFilesIn(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /** @return list<string> fully qualified names declared in the file */
    private function typesDeclaredIn(string $file): array
    {
        $source = file_get_contents($file);
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $match) === 1 ? trim($match[1]) : '';

        if (preg_match_all('/^(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $matches) === false) {
            return [];
        }

        return array_map(static fn (string $name): string => ltrim($namespace . '\\' . $name, '\\'), $matches[1]);
    }
}
