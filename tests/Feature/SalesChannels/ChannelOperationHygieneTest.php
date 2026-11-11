<?php

namespace Tests\Feature\SalesChannels;

use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Amazon\AmazonFeedSubmitter;
use App\Services\SalesChannels\Support\UncalledOperations;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * ONE guard over every channel, on the tree where all five exist together.
 *
 * ## Why this had to be one test and not five
 *
 * The two defects the trial integration branch found were each caught by a guard that already existed — **on another
 * branch**. The scope `return_decisions` was demanded by two Trendyol operations and absent from the list a person can be
 * offered, so the approval could never be granted and no return could ever be sent; the guard that noticed lives in the Odoo
 * work. Nineteen green jobs on each branch separately said nothing about either.
 *
 * So the guards are gathered here, over the whole registry, and the registry spans every channel. A branch cannot be green
 * past this file and wrong in the merge for the same class of reason again.
 *
 * ## And the honest part
 *
 * A registration is a promise: a path, a method, the capabilities needed, the approval relied on. Ten of the hundred and
 * eight are never called by anything. `UncalledOperations` lists each with its reason and its kind, and this file checks the
 * list against a scan of the code **in both directions** — an operation that quietly stops being called fails, and one that
 * starts being called while still listed as a gap fails too. That is what keeps the gap matrix from drifting back into
 * saying an integration does something it merely could.
 */
class ChannelOperationHygieneTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        ChannelOperations::flush();
    }

    public function test_every_registered_operation_is_either_called_or_declared_a_gap_with_a_reason(): void
    {
        $uncalled = $this->operationsNothingCalls();
        $declared = UncalledOperations::names();

        sort($uncalled);
        $expected = $declared;
        sort($expected);

        $this->assertSame(
            $expected,
            $uncalled,
            "The registry and the code disagree about what is actually called.\n"
            . "Nothing calls, and not declared in UncalledOperations: " . implode(', ', array_diff($uncalled, $declared)) . "\n"
            . 'Declared a gap, but something calls it now: ' . implode(', ', array_diff($declared, $uncalled)),
        );
    }

    public function test_every_declared_gap_names_a_kind_and_a_reason(): void
    {
        // "Not built" and "decided against" and "needs a real account" are not interchangeable, and the matrix blurred them.
        $kinds = [UncalledOperations::DECIDED, UncalledOperations::NOT_BUILT, UncalledOperations::NEEDS_AN_ACCOUNT, UncalledOperations::PARTLY_BUILT];

        foreach (UncalledOperations::all() as $operation => $entry) {
            $this->assertContains($entry['kind'], $kinds, "'{$operation}' has no recognised kind.");
            $this->assertNotSame('', trim($entry['why']), "'{$operation}' is a gap with no reason given.");
            $this->assertTrue(ChannelOperations::isRegistered($operation), "'{$operation}' is declared a gap and is not registered at all.");
        }
    }

    public function test_the_amazon_feed_is_claimed_as_built_only_because_every_call_is_actually_made(): void
    {
        // **This test used to assert the opposite**, and that was right at the time: the submission document and the result
        // reader existed, nothing sent one, and calling that "built" was the blurring these guards exist to stop. The cycle
        // is built now -- so the guard inverts rather than disappearing, and it checks the thing that was missing: that every
        // call is really made by code, and not merely registered.
        //
        // `test_every_registered_operation_is_called_or_declared_a_gap` is what proves "made by code": an operation nothing
        // calls must appear in UncalledOperations, so a feed operation absent from both lists would fail there. What is
        // asserted here is that none of them is declared a gap any more, which is the claim this test's name makes.
        foreach ([
            'amazon.feeds_create_document', 'amazon.feeds_upload', 'amazon.feeds_create',
            'amazon.feeds_get', 'amazon.feeds_document', 'amazon.feeds_list', 'amazon.feed_report_download',
        ] as $operation) {
            $this->assertTrue(ChannelOperations::isRegistered($operation), "'{$operation}' is not registered at all.");
            $this->assertNull(UncalledOperations::kindOf($operation), "'{$operation}' is still declared an unfinished gap.");
        }

        // And being built is NOT being on: the path carries its own switch and it ships off. A built operation behind a
        // switch is a configuration decision, which is why it is asserted here and not recorded as an unbuilt one.
        $shipped = require base_path('config/sales_channels.php');
        $this->assertFalse((bool) $shipped['amazon']['bulk_feeds_enabled'], 'the feed path is built AND shipped switched on');
        $this->assertFalse(AmazonFeedSubmitter::enabled(), 'the live config has the feed path on while nothing asked for it');

        // Nothing else in the registry is left in the state the feed was in. If something is, it is new work that needs its
        // own reason, not this one.
        $this->assertSame([], array_keys(UncalledOperations::ofKind(UncalledOperations::PARTLY_BUILT)), 'something is partly built and borrowing the feed\'s old explanation');
    }

    public function test_every_write_names_a_capability_and_an_approval_a_person_can_be_asked_for(): void
    {
        // The exact shape of the defect the integration branch found: an operation demanding an approval that nobody could
        // grant. Over the whole registry now, not one channel's worth of it.
        foreach (ChannelOperations::operationsOfKind(ChannelOperations::WRITE) as $operation) {
            $this->assertNotEmpty(ChannelOperations::capabilities($operation), "the write '{$operation}' names no capability.");
            foreach (ChannelOperations::scopes($operation) as $scope) {
                $this->assertTrue(
                    in_array($scope, ChannelAccountService::EXTRA_SCOPES, true) || $this->isACapabilityScope($scope),
                    "the write '{$operation}' demands the approval '{$scope}', which nobody can be asked for: it is neither an "
                    . 'approval a person is offered nor the name of a capability.',
                );
            }
        }
    }

    public function test_no_operation_is_registered_twice_or_named_for_a_channel_that_does_not_exist(): void
    {
        $registered = ChannelOperations::registered();
        $this->assertSame(array_values(array_unique($registered)), array_values($registered), 'an operation is registered twice.');

        $families = ['amazon', 'trendyol', 'noon', 'odoo', 'torod', 'm5azn', 'myfatoorah', 'internal'];
        foreach ($registered as $operation) {
            $family = explode('.', $operation)[0];
            $this->assertContains($family, $families, "'{$operation}' belongs to no known integration.");
        }
    }

    // ---- the scan ------------------------------------------------------------------------------------------------

    /**
     * The registered operations that no line of `app/` names a second time.
     *
     * A registration names the operation once, in the registry. Anything calling it names it again. So a single occurrence
     * means nothing calls it -- a coarse rule, and the right kind of coarse: it cannot miss a call, it can only be fooled by
     * an operation whose name is written twice in the registry, which the duplicate test above forbids.
     *
     * @return list<string>
     */
    private function operationsNothingCalls(): array
    {
        $haystack = '';
        foreach ($this->filesThatCanActuallyCall() as $source) {
            $haystack .= $source;
        }

        $uncalled = [];
        foreach (ChannelOperations::registered() as $operation) {
            if (!str_contains($haystack, "'" . $operation . "'")) {
                $uncalled[] = $operation;
            }
        }

        return $uncalled;
    }

    /**
     * Every file of `app/` whose mention of an operation is evidence that the APPLICATION calls it.
     *
     * The old scan concatenated every file outside the five registries and asked whether the operation's quoted name
     * appeared once. That counted two things it should never have counted, and both were found in the closure review:
     *
     *  1. **A mention inside a comment.** `NoonOperations` and `NoonConnector` discuss `NoonProductGroups` in prose, and
     *     prose is not a call. Comments are stripped here with `php_strip_whitespace()`, so only code is read.
     *  2. **A mention inside a class nothing reaches.** `TrendyolLookups` named all four of its operations from its own
     *     body while no controller, command, job or service constructed it, and `NoonProductGroups` was the same. The
     *     operations were written and tested, reachable by nobody, and the guard called them called.
     *
     * So a file counts only when something can arrive at it: either the framework enters it (a controller, a command, a
     * job, a listener, middleware, a provider) or some OTHER file names its class. That is reachability one edge deep,
     * not a call graph -- a class reached only by another orphan still counts -- and it is deliberately the cheap half:
     * it catches the orphan, it cannot be fooled by a comment, and it has no false positives to argue about.
     *
     * @return array<string, string> path => source with its comments removed
     */
    private function filesThatCanActuallyCall(): array
    {
        $registries = ['UncalledOperations.php', 'AmazonOperations.php', 'TrendyolOperations.php', 'NoonOperations.php', 'OdooOperations.php'];
        $entryPoints = ['/Http/Controllers/', '/Console/', '/Jobs/', '/Listeners/', '/Http/Middleware/', '/Providers/'];

        $sources = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && !in_array($file->getFilename(), $registries, true)) {
                $sources[$file->getPathname()] = php_strip_whitespace($file->getPathname());
            }
        }

        // How many files name each class. One means only its own file does, so nothing reaches it.
        //
        // **`config/` is counted here and deliberately not in the haystack.** A connector is never named by class in
        // `app/`: `ChannelConnectorRegistry::for()` reads `config('sales_channels.connectors.<channel>')` and resolves
        // that string through the container, so `AmazonConnector` is named exactly once in the whole of `app/` -- by
        // itself. Reading only `app/` therefore called every Amazon and noon operation unreachable, which is how this
        // check first failed: forty-two of them, including `amazon.orders_search`. The config is how the application
        // wires those classes, so it is part of the answer to "does anything reach this". It calls no operation itself,
        // so it stays out of the evidence that one is CALLED.
        $naming = $sources;
        foreach (glob(base_path('config') . '/*.php') ?: [] as $config) {
            $naming['config:' . $config] = php_strip_whitespace($config);
        }

        $filesNaming = [];
        foreach ($naming as $source) {
            foreach (array_unique($this->classNamesIn($source)) as $name) {
                $filesNaming[$name] = ($filesNaming[$name] ?? 0) + 1;
            }
        }

        return array_filter($sources, function (string $source, string $path) use ($entryPoints, $filesNaming): bool {
            foreach ($entryPoints as $entry) {
                if (str_contains($path, $entry)) {
                    return true;
                }
            }

            return ($filesNaming[basename($path, '.php')] ?? 0) > 1;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The class-shaped words of a source: enough to see whether anything names a given class.
     *
     * @return list<string>
     */
    private function classNamesIn(string $source): array
    {
        preg_match_all('/\b[A-Z][A-Za-z0-9_]{2,}\b/', $source, $matches);

        return $matches[0];
    }

    private function isACapabilityScope(string $scope): bool
    {
        foreach (\App\Enums\SalesChannels\IntegrationCapability::values() as $capability) {
            if ($scope === $capability) {
                return true;
            }
        }

        return false;
    }
}
