<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\PlatformAdmin\PlatformAdminContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The floor the server can put under the shell's build (L-17), and the two halves of it that matter:
 *
 *  - it ships UNSET, so deploying it refuses nothing;
 *  - it can never be set above the level the server itself speaks, because that would refuse every request including the sign-in and leave no
 *    session to read the floor from. That is how the seller app's version gate once locked every seller out, and it is not repeated here.
 */
class ContractVersionTest extends PlatformAdminTestCase
{
    private const ME = self::PREFIX . '/me';

    private ?string $token = null;

    public function test_nothing_is_forced_by_default_so_a_build_from_before_the_header_is_answered(): void
    {
        $this->assertSame(0, (int) config('gcc.platform_admin.min_contract'), 'the floor ships unset');

        $this->me()->assertOk();
        $this->me(contract: '1')->assertOk();
        $this->assertNull($this->me()->json('data.contract.min_supported'), 'the answer says there is no floor rather than naming one');
        $this->assertSame(PlatformAdminContract::VERSION, $this->me()->json('data.contract.version'));
    }

    public function test_with_a_floor_set_a_shell_that_says_nothing_is_refused_and_told_both_numbers(): void
    {
        config(['gcc.platform_admin.min_contract' => 1]);

        $response = $this->me();

        $response->assertStatus(426)->assertJsonPath('errors.0.code', 'contract_too_old');
        $this->assertSame(
            ['claimed' => 0, 'version' => 1, 'min_supported' => 1],
            $response->json('errors.0.contract'),
            'the refusal is enough on its own: it can come back from the sign-in, where there is no session to read the floor from',
        );
    }

    public function test_a_shell_at_or_above_the_floor_is_answered_and_one_below_it_is_not(): void
    {
        config(['gcc.platform_admin.min_contract' => 1]);

        $this->me(contract: '1')->assertOk();
        $this->me(contract: '2')->assertOk();
        $this->me(contract: '0')->assertStatus(426);
    }

    public function test_the_sign_in_itself_is_refused_so_an_old_build_never_gets_a_session(): void
    {
        config(['gcc.platform_admin.min_contract' => 1]);
        $admin = $this->platformAdmin();

        $login = $this->postJson(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Pixel']);

        $login->assertStatus(426)->assertJsonPath('errors.0.code', 'contract_too_old');
        $this->assertSame(0, DB::table('platform_admin_tokens')->count(), 'no token was minted for a build that cannot read the answers');
    }

    public function test_a_floor_above_the_level_the_server_speaks_is_held_down_to_it_rather_than_locking_everybody_out(): void
    {
        config(['gcc.platform_admin.min_contract' => 99]);

        $this->assertSame(PlatformAdminContract::VERSION, app(PlatformAdminContract::class)->minSupported(), 'a typo cannot ask for a level this server does not speak');
        $this->me(contract: (string) PlatformAdminContract::VERSION)->assertOk();
        $this->assertSame(PlatformAdminContract::VERSION, $this->me(contract: (string) PlatformAdminContract::VERSION)->json('data.contract.min_supported'));
    }

    public function test_a_floor_that_is_not_a_positive_whole_number_is_no_floor_at_all(): void
    {
        foreach (['', '0', '-1', 'yes', 'one', '1.5'] as $configured) {
            config(['gcc.platform_admin.min_contract' => $configured]);

            $this->assertNull(app(PlatformAdminContract::class)->minSupported(), "[{$configured}] is not a level, so it forces nothing rather than forcing level 0 or throwing");
            $this->me()->assertOk();
        }
    }

    public function test_a_header_that_is_not_a_whole_number_is_read_as_a_build_from_before_the_header(): void
    {
        config(['gcc.platform_admin.min_contract' => 1]);

        foreach (['', ' ', 'one', '1.0', '-1', '1; drop table', 'v1'] as $claimed) {
            // Each claims no level, so it is level 0 and not "whatever is current": an older shell must not be given answers it cannot read.
            $this->me(contract: $claimed)->assertStatus(426);
        }
        // A level with space around it is still that level.
        $this->me(contract: ' 1 ')->assertOk();
    }

    public function test_the_floor_is_not_the_seller_version_gate_and_does_not_read_its_value(): void
    {
        config(['gcc.platform_admin.min_contract' => 1]);

        $statements = $this->statementsRunBy(fn () => $this->me(contract: '1')->assertOk());

        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'app_version') || str_contains($sql, 'version_control'))), 'the shell has a floor of its own and never consults the seller app version gate');
    }

    public function test_the_refusal_is_private_and_says_nothing_of_the_account(): void
    {
        config(['gcc.platform_admin.min_contract' => 1]);

        $response = $this->me();

        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'), 'a refusal is as private as an answer: a proxy must not keep it');
        $body = strtolower($response->getContent());
        foreach (['@', 'admin_id', 'markets', 'capabilit'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, 'a refused build is told about the contract and nothing about who asked');
        }
    }

    public function test_the_api_is_still_a_missing_route_while_the_flag_is_off_whatever_the_floor_says(): void
    {
        config(['gcc.platform_admin.min_contract' => 1, 'gcc.platform_admin.enabled' => false]);

        // The switch comes first: a dormant API is a 404 and never a 426, which would admit it exists.
        $this->me(contract: '1')->assertNotFound();
        $this->me()->assertNotFound();
    }

    private function me(?string $contract = null): TestResponse
    {
        $headers = $this->bearer($this->token ??= $this->mintToken($this->platformAdmin()));

        return $this->getJson(self::ME, $contract === null ? $headers : $headers + [PlatformAdminContract::HEADER => $contract]);
    }
}
