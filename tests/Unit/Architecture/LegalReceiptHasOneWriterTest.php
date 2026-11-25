<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * One place writes a legal acceptance receipt, and it is inside the order's own transaction (I2-05).
 *
 * `CheckoutLegalAcceptance` used to be called from the three `/api/v1` order endpoints the customer app posts to. There are SEVEN paths
 * that create an order on this platform -- three on the website, three in the v1 API and one in the point-of-sale helper -- so a customer
 * buying on the website left no receipt at all, and the gap was invisible because the three that were covered were the three with tests.
 *
 * The call now sits in `OrderManager::generateOrder`, which every one of those paths goes through. That is a claim about the SHAPE of the
 * code rather than about any one checkout, so it is checked here as a shape: exactly one file asks for a receipt, it is that one, and the
 * call is lexically inside the `DB::transaction(` that writes the order.
 *
 * Why the transaction matters enough to pin: a receipt written after `generateOrder` returned would outlive an order the transaction
 * refused, leaving a record that a customer accepted terms for a sale that never happened -- and it could not be written at all for an
 * order that was rolled back and retried. A later change that moves the call out to the callers for convenience would restore both
 * problems quietly, and this fails when it does.
 */
class LegalReceiptHasOneWriterTest extends TestCase
{
    private const WRITER = 'app/Utils/OrderManager.php';

    private const SERVICE = 'CheckoutLegalAcceptance';

    /** Every path that creates an order, so the count is a statement somebody has to update deliberately. */
    private const ORDER_CREATORS = [
        'app/Http/Controllers/Web/WebController.php' => 3,
        'app/Http/Controllers/RestAPI/v1/OrderController.php' => 3,
        'app/Utils/module-helper.php' => 1,
    ];

    /** The repository root, resolved the way the other architecture tests do: these run without a booted application. */
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function sourceOf(string $relative): string
    {
        $path = $this->root() . DIRECTORY_SEPARATOR . $relative;
        $this->assertFileExists($path, $relative . ' is where the order writers live');

        return (string) file_get_contents($path);
    }

    /** @return list<string> every PHP file under app/ */
    private function filesUnderApp(): array
    {
        $found = [];

        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/app'));

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    public function test_exactly_one_file_under_app_asks_for_a_legal_receipt(): void
    {
        $askers = [];

        foreach ($this->filesUnderApp() as $path) {
            $source = (string) file_get_contents($path);

            // The service's own file names itself; everything else naming it is a caller.
            if (str_contains($source, self::SERVICE . '::class') || str_contains($source, self::SERVICE . ' $')) {
                $askers[] = ltrim(str_replace($this->root(), '', $path), DIRECTORY_SEPARATOR);
            }
        }

        $this->assertSame(
            [self::WRITER],
            $askers,
            'A legal receipt is written in one place so that every checkout gets one. Adding a second caller means some orders get two '
            . 'receipts and the ones that are not covered get none -- which is the state this replaced.',
        );
    }

    public function test_the_receipt_is_asked_for_inside_the_transaction_that_writes_the_order(): void
    {
        $source = $this->sourceOf(self::WRITER);

        $callPosition = strpos($source, 'app(' . self::SERVICE . '::class)');
        $this->assertNotFalse($callPosition, 'the receipt is asked for in ' . self::WRITER);

        $before = substr($source, 0, $callPosition);

        // The transaction the order is written in is the LAST one opened before the call, and it has to still be open: every
        // `DB::transaction(` before the call must be matched by fewer `attempts: 3);` closes than opens.
        $opened = substr_count($before, 'DB::transaction(');
        $closed = substr_count($before, '}, attempts: 3);');

        $this->assertGreaterThan(
            $closed,
            $opened,
            'The receipt must be written INSIDE the order transaction, not after it: one written afterwards survives an order that was '
            . 'rolled back, and is never written for an order that was refused and retried.',
        );
    }

    /**
     * The seven paths are still seven, and they all reach the writer.
     *
     * If a new checkout is added it will call generateOrder like the others and inherit the receipt -- but if someone writes an order
     * WITHOUT generateOrder, this count moves and the new path has to be looked at.
     */
    public function test_every_path_that_creates_an_order_goes_through_the_writer(): void
    {
        foreach (self::ORDER_CREATORS as $relative => $expected) {
            $source = $this->sourceOf($relative);

            $this->assertSame(
                $expected,
                substr_count($source, 'OrderManager::generateOrder('),
                $relative . ' creates ' . $expected . ' orders through generateOrder. If that changed, the new path needs a receipt too.',
            );
        }

        $this->assertStringNotContainsString(
            self::SERVICE,
            $this->sourceOf('app/Http/Controllers/Web/WebController.php'),
            'the website gets its receipt from generateOrder and must not grow a second call of its own',
        );
    }
}
