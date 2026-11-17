<?php

namespace Tests\Unit\PlatformAdmin;

use App\Services\PlatformAdmin\PlatformAdminAuditChain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hash of a line of the audit trail. The canonical form is spelled out here, character by character, because changing it would make every chain that
 * exists fail its verification: a change of it must fail a test.
 */
class AuditChainHashTest extends TestCase
{
    private const ZEROS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function test_the_starting_value_is_sixty_four_zeros(): void
    {
        $this->assertSame(self::ZEROS, PlatformAdminAuditChain::GENESIS);
    }

    public function test_a_line_is_hashed_as_the_sha256_of_its_canonical_json(): void
    {
        $canonical = '{"admin_id":7,"action":"overview.view","market":"KW","token_id":12,"ip":"203.0.113.9","user_agent":"LavkooSeller/1.0",'
            . '"context":{"params":{"id":"3","market":"KW"},"status":200},"created_at":"2026-11-17 10:00:00","prev_hash":"' . self::ZEROS . '"}';

        $this->assertSame(hash('sha256', $canonical), $this->hashOf());
    }

    public function test_a_line_with_nothing_optional_has_nulls_in_their_places_and_no_context(): void
    {
        $canonical = '{"admin_id":null,"action":"auth.login_failed","market":null,"token_id":null,"ip":null,"user_agent":null,"context":null,"created_at":"2026-11-17 10:00:00","prev_hash":"' . self::ZEROS . '"}';

        $hash = PlatformAdminAuditChain::hashOf(adminId: null, action: 'auth.login_failed', market: null, tokenId: null, ip: null, userAgent: null, context: null, createdAt: '2026-11-17 10:00:00', previousHash: self::ZEROS);

        $this->assertSame(hash('sha256', $canonical), $hash);
    }

    public function test_text_is_not_escaped_so_that_an_engine_that_stores_it_either_way_gives_the_same_hash(): void
    {
        $canonical = '{"admin_id":1,"action":"a/b","market":null,"token_id":null,"ip":null,"user_agent":"é/١","context":{"device_name":"Téléphone/١"},"created_at":"2026-11-17 10:00:00","prev_hash":"' . self::ZEROS . '"}';

        $hash = PlatformAdminAuditChain::hashOf(adminId: 1, action: 'a/b', market: null, tokenId: null, ip: null, userAgent: 'é/١', context: ['device_name' => 'Téléphone/١'], createdAt: '2026-11-17 10:00:00', previousHash: self::ZEROS);

        $this->assertSame(hash('sha256', $canonical), $hash);
    }

    public function test_the_keys_of_a_context_are_sorted_at_every_depth_and_a_list_keeps_its_order(): void
    {
        $one = $this->hashOf(context: ['z' => 1, 'a' => ['y' => 2, 'b' => 3], 'list' => [['q' => 1, 'c' => 2], 'x']]);
        $other = $this->hashOf(context: ['list' => [['c' => 2, 'q' => 1], 'x'], 'a' => ['b' => 3, 'y' => 2], 'z' => 1]);
        $reordered = $this->hashOf(context: ['z' => 1, 'a' => ['y' => 2, 'b' => 3], 'list' => ['x', ['q' => 1, 'c' => 2]]]);

        $this->assertSame($one, $other);
        $this->assertNotSame($one, $reordered, 'the order of a list is part of what it says');
    }

    public function test_a_list_of_more_than_ten_is_hashed_as_a_list_in_its_own_order(): void
    {
        $canonical = '{"admin_id":1,"action":"a","market":null,"token_id":null,"ip":null,"user_agent":null,"context":{"list":[1,2,3,4,5,6,7,8,9,10,11,12]},"created_at":"2026-11-17 10:00:00","prev_hash":"' . self::ZEROS . '"}';

        $hash = PlatformAdminAuditChain::hashOf(adminId: 1, action: 'a', market: null, tokenId: null, ip: null, userAgent: null, context: ['list' => range(1, 12)], createdAt: '2026-11-17 10:00:00', previousHash: self::ZEROS);

        $this->assertSame(hash('sha256', $canonical), $hash);
    }

    /** @return array<string, array{string, mixed}> */
    public static function changes(): array
    {
        return [
            'the admin' => ['adminId', 8],
            'no admin' => ['adminId', null],
            'the action' => ['action', 'overview.views'],
            'the market' => ['market', 'AE'],
            'no market' => ['market', null],
            'the session' => ['tokenId', 13],
            'no session' => ['tokenId', null],
            'the address' => ['ip', '203.0.113.10'],
            'the user agent' => ['userAgent', 'LavkooSeller/1.1'],
            'a value in the context' => ['context', ['status' => 403, 'params' => ['market' => 'KW', 'id' => '3']]],
            'a value of another type in the context' => ['context', ['status' => '200', 'params' => ['market' => 'KW', 'id' => '3']]],
            'an extra member in the context' => ['context', 'extra'],
            'the time' => ['createdAt', '2026-11-17 10:00:01'],
            'the line before' => ['previousHash', '1000000000000000000000000000000000000000000000000000000000000000'],
        ];
    }

    #[DataProvider('changes')]
    public function test_a_change_to_any_part_of_a_line_changes_its_hash(string $field, mixed $value): void
    {
        if ($value === 'extra') {
            $value = ['status' => 200, 'params' => ['market' => 'KW', 'id' => '3'], 'more' => true];
            $field = 'context';
        }

        $this->assertNotSame($this->hashOf(), $this->hashOf(...[$field => $value]));
    }

    public function test_an_empty_context_and_no_context_are_not_the_same_line(): void
    {
        $this->assertNotSame($this->hashOf(context: null), $this->hashOf(context: []));
    }

    public function test_a_stored_row_is_hashed_as_the_line_it_was_written_as_even_when_the_engine_gives_its_numbers_as_text_and_its_context_in_another_order(): void
    {
        $row = (object) [
            'id' => '44', 'admin_id' => '7', 'action' => 'overview.view', 'market' => 'KW', 'token_id' => '12', 'ip' => '203.0.113.9', 'user_agent' => 'LavkooSeller/1.0',
            'context' => '{"status": 200, "params": {"market": "KW", "id": "3"}}', 'created_at' => '2026-11-17 10:00:00', 'prev_hash' => self::ZEROS,
        ];

        $this->assertSame($this->hashOf(), PlatformAdminAuditChain::hashOfRow(row: $row));
    }

    public function test_a_context_that_cannot_be_read_is_not_taken_for_no_context(): void
    {
        $row = (object) [
            'id' => 1, 'admin_id' => null, 'action' => 'auth.login_failed', 'market' => null, 'token_id' => null, 'ip' => null, 'user_agent' => null,
            'context' => null, 'created_at' => '2026-11-17 10:00:00', 'prev_hash' => self::ZEROS,
        ];
        $damaged = clone $row;
        $damaged->context = '{not json';

        $this->assertNotSame(PlatformAdminAuditChain::hashOfRow(row: $row), PlatformAdminAuditChain::hashOfRow(row: $damaged));
    }

    /** @param array<string, mixed> $context */
    private function hashOf(
        ?int $adminId = 7,
        string $action = 'overview.view',
        ?string $market = 'KW',
        ?int $tokenId = 12,
        ?string $ip = '203.0.113.9',
        ?string $userAgent = 'LavkooSeller/1.0',
        ?array $context = ['status' => 200, 'params' => ['market' => 'KW', 'id' => '3']],
        string $createdAt = '2026-11-17 10:00:00',
        string $previousHash = self::ZEROS,
    ): string {
        return PlatformAdminAuditChain::hashOf(adminId: $adminId, action: $action, market: $market, tokenId: $tokenId, ip: $ip, userAgent: $userAgent, context: $context, createdAt: $createdAt, previousHash: $previousHash);
    }
}
