<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\Staff\AdminStepUp;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReadsRouteMiddleware;

/**
 * I2-03's last criterion: publishing and revoking a legal text asks for step-up as well as dual approval (GCC-20 level 3).
 *
 * **Why those two steps and not the others.** Publishing puts a text into force for every customer of a market; revoking takes the text in
 * force away, and a market left with no text in force stops being allowed to sell ({@see \App\Services\Storefront\StorefrontPreflight}).
 * Both were carried out on the strength of a panel session that may be hours old, from a desk that may be unattended. Writing a draft and
 * discarding one change nothing a customer is bound by, and APPROVING is deliberately not asked either: a text may not be approved by its
 * own author, so the dual control this criterion also names is already what guards that step, and asking twice for one step buys nothing.
 *
 * **The switch ships off and the first test says so**, which is the platform of today: both steps go through with no password field, as
 * every test of this screen drives them.
 *
 * **Every refusal here has its unrestricted twin.** A refusal on its own cannot tell a guard from a wrong address or a wrong state, so the
 * same request carrying the right password is driven immediately afterwards and the text IS published.
 *
 * **And this screen is the FIRST one of the panel on which that switch can be turned on and the action still work.** The staff-management
 * and role forms have carried `admin_step_up` since G-14 and no screen in this repository carried the field, so turning the switch on broke
 * them -- which is what their own middleware's docblock says. The publish and revoke forms of this screen now render it
 * (`<x-k.step-up />`), and that it renders NOTHING while the switch is off is asserted below, because a password field appearing on a
 * screen nobody asked to change it is how an operator learns to ignore one.
 */
class PublishingALegalTextAsksAgainTest extends AdminStorefrontScreenTestCase
{
    use ReadsRouteMiddleware;

    private const WORDS = '<p>The terms of Kuwait.</p>';

    private const TEMPLATE = '<p>TEMPLATE: replace this with the terms of the country.</p>';

    private const APPROVERS_PASSWORD = 'Their#Own1Password';

    private const WRONG_PASSWORD = 'Not#Their1Password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->giveTheApproverAPassword();
    }

    // ---- the switch ships off ----------------------------------------------------------------------------------------------------------

    public function test_the_switch_ships_off_and_off_a_text_is_published_and_revoked_with_no_password_at_all(): void
    {
        self::assertFalse(app(AdminStepUp::class)->isRequired(), 'the step-up switch ships off');

        $text = $this->anApprovedText();

        $this->publish($text)->assertRedirect();
        self::assertNotNull($this->text($text)->published_at, 'the platform of today publishes with no second proof');

        $this->revoke($text)->assertRedirect();
        self::assertNotNull($this->text($text)->revoked_at);
    }

    public function test_off_the_screen_shows_no_password_field(): void
    {
        $this->anApprovedText();

        self::assertStringNotContainsString(
            AdminStepUp::PASSWORD_FIELD,
            $this->page(),
            'a field on a screen nobody asked to change is how an operator learns to ignore one',
        );
    }

    // ---- on, publishing asks again ---------------------------------------------------------------------------------------------------

    public function test_publishing_without_the_password_writes_nothing_and_with_it_writes(): void
    {
        $text = $this->anApprovedText();
        $this->askForTheirPasswordAgain();

        $this->publish($text)->assertRedirect();
        self::assertNull($this->text($text)->published_at, 'an unconfirmed publication must write NOTHING');

        // The unrestricted twin, in the same test, because a refusal alone cannot tell a guard from a wrong address or a wrong state.
        $this->publish($text, [AdminStepUp::PASSWORD_FIELD => self::APPROVERS_PASSWORD])->assertRedirect();
        self::assertNotNull($this->text($text)->published_at, 'the same request with the proof publishes the text');
    }

    public function test_a_wrong_password_publishes_nothing(): void
    {
        $text = $this->anApprovedText();
        $this->askForTheirPasswordAgain();

        $this->publish($text, [AdminStepUp::PASSWORD_FIELD => self::WRONG_PASSWORD])->assertRedirect();

        self::assertNull($this->text($text)->published_at);
    }

    public function test_revoking_without_the_password_writes_nothing_and_with_it_writes(): void
    {
        $text = $this->aPublishedText();
        $this->askForTheirPasswordAgain();

        $this->revoke($text)->assertRedirect();
        self::assertNull($this->text($text)->revoked_at, 'a market may not be left without its text in force by an unconfirmed click');

        $this->revoke($text, [AdminStepUp::PASSWORD_FIELD => self::APPROVERS_PASSWORD])->assertRedirect();
        self::assertNotNull($this->text($text)->revoked_at);
    }

    public function test_one_proof_covers_the_steps_that_follow_it_inside_the_window(): void
    {
        $text = $this->anApprovedText();
        $this->askForTheirPasswordAgain();

        $this->publish($text, [AdminStepUp::PASSWORD_FIELD => self::APPROVERS_PASSWORD])->assertRedirect();
        // No password on this one: the proof stamped on the session covers it, so a run of steps asks once rather than once per click.
        $this->revoke($text)->assertRedirect();

        self::assertNotNull($this->text($text)->revoked_at);
    }

    // ---- what is NOT asked ------------------------------------------------------------------------------------------------------------

    public function test_approving_is_not_asked_because_the_second_person_is_what_guards_it(): void
    {
        $this->askForTheirPasswordAgain();
        $text = $this->aDraftOfItsOwnWriter();
        $this->signInAs(self::LEGAL_APPROVER);

        $this->post(route('admin.storefronts.legal-texts.approve', $text), ['body_hash' => $this->text($text)->body_hash])->assertRedirect();

        self::assertNotNull($this->text($text)->approved_at, 'a text may not be approved by its own author, which is the control on this step');
    }

    public function test_writing_a_draft_is_not_asked_either(): void
    {
        $this->askForTheirPasswordAgain();
        $this->signInAs(self::LEGAL_WRITER);

        $this->post(route('admin.storefronts.legal-texts.draft', 'KW'), [
            'document_type' => 'terms', 'locale' => 'en', 'template' => self::TEMPLATE,
        ])->assertRedirect();

        self::assertSame(1, StorefrontLegalDocument::query()->where('country_code', 'KW')->count(), 'a draft binds no customer to anything');
    }

    // ---- the declaration --------------------------------------------------------------------------------------------------------------

    public function test_the_two_steps_carry_the_middleware_and_the_others_do_not(): void
    {
        foreach (['publish', 'revoke'] as $step) {
            self::assertContains(
                'admin_step_up',
                $this->middlewareOfTheRouteNamed('admin.storefronts.legal-texts.' . $step),
                "the {$step} route asks the employee to prove who they are again",
            );
        }

        foreach (['approve', 'draft', 'edit', 'discard', 'new-version'] as $step) {
            self::assertNotContains(
                'admin_step_up',
                $this->middlewareOfTheRouteNamed('admin.storefronts.legal-texts.' . $step),
                "the {$step} route is deliberately not asked: see this class's docblock",
            );
        }
    }

    public function test_on_the_screen_carries_the_field_in_the_publish_and_revoke_forms(): void
    {
        $this->aPublishedTextWithANewVersionToPublish();
        $this->askForTheirPasswordAgain();

        $page = $this->page();

        foreach (['publish', 'revoke'] as $step) {
            self::assertSame(
                1,
                preg_match($this->formOf($step) . '(?:(?!</form>).)*?name="' . AdminStepUp::PASSWORD_FIELD . '"#s', $page),
                "the {$step} form carries the field its own middleware reads, or the action is always refused",
            );
        }
    }

    // ---- the world --------------------------------------------------------------------------------------------------------------------

    private function askForTheirPasswordAgain(): void
    {
        config(['gcc.staff.step_up.enabled' => true]);
    }

    /** The approver's own password, which is what the step-up reads: the base's admins table carries no password column. */
    private function giveTheApproverAPassword(): void
    {
        if (!Schema::hasColumn('admins', 'password')) {
            Schema::table('admins', static fn (Blueprint $table) => $table->string('password')->nullable());
        }

        \Illuminate\Support\Facades\DB::table('admins')
            ->whereIn('id', [self::LEGAL_APPROVER, self::MASTER])
            ->update(['password' => Hash::make(self::APPROVERS_PASSWORD)]);
    }

    /** A draft written by one admin, edited, and left for somebody else to approve. */
    private function aDraftOfItsOwnWriter(string $type = 'terms', string $locale = 'en'): int
    {
        $this->signInAs(self::LEGAL_WRITER);
        $this->post(route('admin.storefronts.legal-texts.draft', 'KW'), ['document_type' => $type, 'locale' => $locale, 'template' => self::TEMPLATE])
            ->assertRedirect();
        $id = (int) StorefrontLegalDocument::query()->where('country_code', 'KW')->where('document_type', $type)->where('locale', $locale)->orderByDesc('id')->value('id');
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS])->assertRedirect();

        return $id;
    }

    /** A text approved by somebody other than its author, signed in as that approver and ready to publish. */
    private function anApprovedText(string $type = 'terms', string $locale = 'en'): int
    {
        $id = $this->aDraftOfItsOwnWriter(type: $type, locale: $locale);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->post(route('admin.storefronts.legal-texts.approve', $id), ['body_hash' => $this->text($id)->body_hash])->assertRedirect();

        return $id;
    }

    private function aPublishedText(): int
    {
        $id = $this->anApprovedText();
        $this->publish($id)->assertRedirect();

        self::assertNotNull($this->text($id)->published_at, 'the fixture must really be in force, or the revocation proves nothing');

        return $id;
    }

    /** A text in force and a second one approved, so the page offers a publish form AND a revoke form at once. */
    private function aPublishedTextWithANewVersionToPublish(): void
    {
        $this->aPublishedText();
        $this->anApprovedText(type: 'privacy');
    }

    private function publish(int $text, array $form = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('admin.storefronts.legal-texts.publish', $text), ['body_hash' => $this->text($text)->body_hash] + $form);
    }

    private function revoke(int $text, array $form = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('admin.storefronts.legal-texts.revoke', $text), ['reason' => 'the regulator asked for it'] + $form);
    }

    private function text(int $id): StorefrontLegalDocument
    {
        return StorefrontLegalDocument::query()->findOrFail($id);
    }

    private function page(string $country = 'KW'): string
    {
        return $this->get(route('admin.storefronts.legal-texts.index', $country))->assertOk()->getContent();
    }

    /** @return list<string> the middleware a named route really runs, read from the route table rather than from the file */
    private function middlewareOfTheRouteNamed(string $name): array
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);

        self::assertNotNull($route, "the route {$name} exists");

        return $this->declaredMiddleware(route: $route);
    }

    private function formOf(string $step): string
    {
        return '#<form[^>]*action="[^"]*' . preg_quote('legal-texts/', '#') . '\d+/' . preg_quote($step, '#') . '"[^>]*>';
    }
}
