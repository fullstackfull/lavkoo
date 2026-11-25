<?php

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every place the CUSTOMER-FACING code starts a query of `products`, and whether that query reaches the storefront narrowing (B-01).
 *
 * The narrowing itself is proved by behaviour elsewhere (`StorefrontCatalogTest`, `StorefrontByIdReadsTest`): with storefronts on, the
 * Emirati storefront lists only Emirati products. What no test could say was whether a NEW read bypasses it -- the discovery audit counted
 * 142 static query starts and 67 raw `products` queries across the application, and a storefront narrowing that one of them skips is a
 * product of another market on a customer's screen. This reads text, as the project's other source guards do, and it is the guard: a read
 * added without the narrowing, and without a line in the register beside this class, fails by the name of its file.
 *
 * Comments are stripped before anything is matched, and that is not tidiness: `ProductSearchIndexer`'s docblock names
 * `Product::getNameAttribute()` to explain what it reads, which a regex cannot tell from a query, so the file was reported as an unnarrowed
 * read of the catalogue. (`PushTopicNamesTest`'s own register of FCM senders had the same fault and was fixed the same way.)
 *
 * The unit of judgement is the FUNCTION, not the statement: these files build a query over several statements
 * (`$query = Product::where(...); ... $query->active();`), so a statement-level reading calls almost everything a bypass. What it cannot
 * follow is a builder handed to another function as an argument; the two cases of that in this codebase are named in the register.
 */
final class CustomerProductReadScanner
{
    /**
     * The trees a customer's own request is served from. The admin and vendor panels are deliberately NOT here: they belong to no
     * storefront on purpose, which is what keeps a central row reachable there, and their narrowing is the staff country scope's
     * question (GCC-14c), not this one. The seller APIs are not here either: a seller reads their own products (B-09).
     */
    public const TREES = [
        'app/Http/Controllers/RestAPI/v1',
        'app/Http/Controllers/Web',
        'app/Utils/ProductManager.php',
        'app/Utils/CartManager.php',
        'app/Utils/CategoryManager.php',
        'app/Services/Web',
        'app/Services/Search',
    ];

    /**
     * A query START of `products`: the model statically (but not `Product::class`, which is a name and not a query), the container's
     * model, a repository property, or the query builder on the table.
     */
    private const STARTS = '/(?:\bProduct::(?!class\b)|\bapp\(Product::class\)|\$this->product\b|\bDB::table\(\s*[\'"]products[\'"]\s*\))/';

    /**
     * What narrows a product read to the storefront. `active()` ends with `inCurrentStorefront()`, which is `StorefrontCatalog::restrict`;
     * the three `restrict*` helpers narrow a builder, a joined query and a list of ids; and `offers()` is the gate for a row the caller
     * already holds, which DECISIONS records must never be turned into a query narrowing (a row loaded without the column would be
     * refused, and a Saudi installation would stop selling its own products).
     */
    private const NARROWING = '/(?:->|::)\s*(?:active|inCurrentStorefront)\s*\(|StorefrontCatalog::(?:restrict|restrictJoinedProducts|restrictProductIds|offers)\s*\(/';

    /** Where one function ends and the next begins, for a text reading. */
    private const FUNCTIONS = '/(?=\n\s*(?:public |private |protected |static |final |abstract )*function\s)/';

    /**
     * @return array{starts: int, narrowed: int, open: array<string, int>} how many query starts were read, how many are in a function that
     *                                                                    narrows, and how many are not, by file
     */
    public function analyse(string $basePath): array
    {
        $starts = 0;
        $narrowed = 0;
        $open = [];

        foreach ($this->filesUnder($basePath) as $file => $code) {
            foreach (preg_split(self::FUNCTIONS, self::withoutComments($code)) as $chunk) {
                $found = preg_match_all(self::STARTS, (string) $chunk);

                if ($found === 0) {
                    continue;
                }

                $starts += $found;

                if (preg_match(self::NARROWING, (string) $chunk) === 1) {
                    $narrowed += $found;

                    continue;
                }

                $open[$file] = ($open[$file] ?? 0) + $found;
            }
        }

        ksort($open);

        return ['starts' => $starts, 'narrowed' => $narrowed, 'open' => $open];
    }

    /** @return array<string, string> the source of every PHP file of the trees, by path relative to the project */
    private function filesUnder(string $basePath): array
    {
        $files = [];

        foreach (self::TREES as $relative) {
            $path = $basePath . '/' . $relative;

            if (is_file($path)) {
                $files[$relative] = (string) file_get_contents($path);

                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $files[substr($file->getPathname(), strlen($basePath) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        ksort($files);

        return $files;
    }

    /** The code with its comments replaced by a space, so no two tokens are joined and nothing a comment SAYS is read as code. */
    public static function withoutComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            $out .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }

        return $out;
    }
}
