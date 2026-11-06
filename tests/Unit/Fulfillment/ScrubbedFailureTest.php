<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\Support\ScrubbedFailure;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Work whose failure would be reported with its whole message lets out only where it failed and a scrubbed message. */
class ScrubbedFailureTest extends TestCase
{
    /** @param list<class-string<Throwable>> $expected */
    private function thrownBy(Closure $work, array $expected = []): Throwable
    {
        try {
            ScrubbedFailure::run($work, $expected);
        } catch (Throwable $caught) {
            return $caught;
        }

        $this->fail('nothing was thrown');
    }

    public function test_what_the_work_returns_is_returned(): void
    {
        $this->assertSame('done', ScrubbedFailure::run(static fn (): string => 'done'));
    }

    public function test_an_exception_nobody_expected_comes_out_as_its_class_its_place_and_a_scrubbed_message(): void
    {
        $caught = $this->thrownBy(static fn () => throw new PDOException('Duplicate entry for receiver 0551234567 sara@example.test'));

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringContainsString('PDOException at ScrubbedFailureTest.php:', $caught->getMessage());
        $this->assertStringNotContainsString('0551234567', $caught->getMessage());
        $this->assertStringNotContainsString('sara@example.test', $caught->getMessage());
        $this->assertNull($caught->getPrevious(), 'the original, with its whole message, is not chained');
    }

    public function test_an_expected_exception_passes_through_untouched(): void
    {
        $ours = new InvalidArgumentException('Nothing to send for this order');
        $unavailable = new ProviderUnavailableException('Torod Point is disabled');

        $this->assertSame($ours, $this->thrownBy(static fn () => throw $ours, [InvalidArgumentException::class, ProviderUnavailableException::class]));
        $this->assertSame($unavailable, $this->thrownBy(static fn () => throw $unavailable, [InvalidArgumentException::class, ProviderUnavailableException::class]));
    }

    public function test_a_subclass_of_an_expected_exception_is_not_ours_and_is_scrubbed(): void
    {
        $library = new class('Could not parse \'0551234567 oops\' as a date') extends InvalidArgumentException {
        };

        $caught = $this->thrownBy(static fn () => throw $library, [InvalidArgumentException::class]);

        $this->assertNotSame($library, $caught);
        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringNotContainsString('0551234567', $caught->getMessage());
    }

    public function test_an_exception_that_was_not_listed_is_scrubbed_even_when_it_is_ours(): void
    {
        $caught = $this->thrownBy(static fn () => throw new InvalidArgumentException('Receiver 0551234567 has no city'));

        $this->assertNotInstanceOf(InvalidArgumentException::class, $caught);
        $this->assertStringNotContainsString('0551234567', $caught->getMessage());
    }

    /** @return iterable<string, array{Throwable}> */
    public static function answersTheFrameworkRenders(): iterable
    {
        yield 'a missing model' => [new ModelNotFoundException()];
        yield 'a missing page' => [new NotFoundHttpException('gone')];
        yield 'a ready-made response' => [new HttpResponseException(new Response('redirect'))];
        yield 'invalid input' => [new ValidationException(new Validator(new Translator(new ArrayLoader(), 'en'), [], []))];
    }

    #[DataProvider('answersTheFrameworkRenders')]
    public function test_what_the_framework_turns_into_an_answer_of_its_own_is_not_rewritten_into_a_server_error(Throwable $answer): void
    {
        $this->assertSame($answer, $this->thrownBy(static fn () => throw $answer));
    }
}
