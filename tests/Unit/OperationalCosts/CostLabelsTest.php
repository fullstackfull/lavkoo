<?php

namespace Tests\Unit\OperationalCosts;

use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use PHPUnit\Framework\TestCase;

class CostLabelsTest extends TestCase
{
    public function test_a_sentence_has_its_placeholders_filled_in_wherever_the_translator_put_them(): void
    {
        $this->assertSame(
            'Only the first 200 of 350 lines are listed.',
            CostLabels::filled('Only the first :shown of :total lines are listed.', ['shown' => 200, 'total' => 350]),
        );
        $this->assertSame(
            'المعروض 200 من أصل 350',
            CostLabels::filled('المعروض :shown من أصل :total', ['total' => 350, 'shown' => 200]),
            'The order of the values does not matter, nor where the number lands in the sentence.',
        );
    }

    public function test_a_placeholder_used_twice_is_filled_twice_and_a_prefix_of_another_does_not_confuse_it(): void
    {
        $this->assertSame('3 of 30: 3', CostLabels::filled(':count of :counted: :count', ['count' => 3, 'counted' => 30]));
    }

    public function test_a_sentence_without_the_placeholder_is_left_as_it_is_and_a_missing_sentence_is_empty(): void
    {
        $this->assertSame('No numbers here.', CostLabels::filled('No numbers here.', ['count' => 3]));
        $this->assertSame('', CostLabels::filled(null, ['count' => 3]));
        $this->assertSame('Nothing to fill.', CostLabels::filled('Nothing to fill.', []));
    }

    public function test_a_value_that_looks_like_a_placeholder_is_not_filled_in_again(): void
    {
        $this->assertSame(':total of :total', CostLabels::filled(':shown of :total', ['shown' => ':total', 'total' => ':total']));
    }
}
