<?php

namespace Tests\Feature\Retention;

use App\Mail\AbandonedCartReminderMail;
use App\Models\AbandonedCartReminder;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * cart:remind-abandoned writes the ledger row of a reminder (abandoned_cart_reminders, unique on cart_group_id and stage) BEFORE it sends the
 * mail, and the selection leaves out every cart group that has a row for the stage. So a reminder for one cart group and stage is sent at most
 * once: a second run, a run that died halfway and a run that lost the insert to another worker all send nothing more. The price of that is
 * that a send that failed is not tried again for that cart group and stage: nothing retries it.
 *
 * No migration in this repository creates the ledger (the installation dump does, and it is not here): the table is built here from the model
 * and the code that writes it, like the other store tables of these tests, with the unique key the command documents. That the live table has
 * the key is not proved by any file of this repository. The two tests that need the key (the key itself and the lost race) therefore document
 * the contract the command relies on and cannot fail on a change of the live table; the others fail on a change of the command. When a
 * migration creates the ledger, build the table from it in createTheLedger().
 */
class AbandonedCartReminderLedgerTest extends ApiAddressTestCase
{
    private const COMMAND = 'cart:remind-abandoned';

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCartTables();
        $this->createTheLedger();
        DB::table('business_settings')->insert(['type' => 'abandoned_cart_reminder_status', 'value' => '1']);
        Cache::flush();

        $this->customer = $this->makeCustomer(['email' => 'shopper@example.test']);
        $this->putOneProductInTheCart($this->customer->id);
        DB::table('carts')->where('customer_id', $this->customer->id)->update(['updated_at' => now()->subHours(8)]);
    }

    private function createTheLedger(): void
    {
        Schema::create('abandoned_cart_reminders', static function (Blueprint $table): void {
            $table->id();
            $table->string('cart_group_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('email');
            $table->unsignedInteger('stage')->default(1);
            $table->decimal('cart_value', 16, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->dateTime('recovered_at')->nullable();
            $table->unsignedBigInteger('recovered_order_id')->nullable();
            $table->timestamps();
            $table->unique(['cart_group_id', 'stage']);
        });
    }

    private function cartGroup(): string
    {
        return 'group-' . $this->customer->id . 'c';
    }

    /** @return list<array{0: string, 1: int}> the cart group and the stage of every ledger row */
    private function ledger(): array
    {
        return DB::table('abandoned_cart_reminders')->orderBy('stage')->get(['cart_group_id', 'stage'])
            ->map(static fn (object $row): array => [$row->cart_group_id, (int) $row->stage])
            ->all();
    }

    private function sendReminders(int $stage = 1)
    {
        return $this->artisan(self::COMMAND, ['--stage' => $stage]);
    }

    /** A mailer that accepts the recipient and fails when the message is handed over: the sender that throws. */
    private function mailServerThatIsDown(): object
    {
        return new class {
            public function to(mixed $users): object
            {
                return new class {
                    public function send(mixed $mailable): never
                    {
                        throw new RuntimeException('the mail server is down');
                    }
                };
            }
        };
    }

    public function test_two_runs_send_one_reminder_for_a_cart_group_and_stage(): void
    {
        Mail::fake();

        $this->sendReminders()->expectsOutputToContain('Sent 1 reminder(s).')->assertSuccessful();
        $this->sendReminders()->expectsOutputToContain('No abandoned carts match the current thresholds.')->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, 1);
        Mail::assertSent(AbandonedCartReminderMail::class, static fn (AbandonedCartReminderMail $mail): bool => $mail->hasTo('shopper@example.test') && $mail->reminder->stage === 1);
        $this->assertSame([[$this->cartGroup(), 1]], $this->ledger());
        $row = DB::table('abandoned_cart_reminders')->first();
        $this->assertSame([$this->customer->id, 'shopper@example.test', 100.0, 1], [(int) $row->customer_id, $row->email, (float) $row->cart_value, (int) $row->item_count]);
    }

    public function test_a_run_that_died_after_the_ledger_row_was_written_leaves_the_row_and_the_next_run_does_not_send_the_reminder_again(): void
    {
        $mailManager = Mail::getFacadeRoot();
        Mail::swap($this->mailServerThatIsDown());

        $this->sendReminders()->expectsOutputToContain('the mail server is down')->assertFailed();

        $this->assertSame([[$this->cartGroup(), 1]], $this->ledger(), 'the row was written before the send and stays');

        Mail::swap($mailManager);
        Mail::fake();
        $this->sendReminders()->expectsOutputToContain('No abandoned carts match the current thresholds.')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame([[$this->cartGroup(), 1]], $this->ledger(), 'at most once: a reminder whose send failed is not tried again for that cart group and stage');
    }

    /** The collision comes from the key createTheLedger() builds; what this pins is the command's side of it: a ledger insert that fails sends nothing and does not stop the run. */
    public function test_a_run_that_loses_the_insert_to_another_worker_sends_nothing_and_leaves_the_row_of_the_other_worker(): void
    {
        Mail::fake();
        AbandonedCartReminder::creating(function (AbandonedCartReminder $reminder): void {
            DB::table('abandoned_cart_reminders')->insert([
                'cart_group_id' => $reminder->cart_group_id, 'stage' => $reminder->stage, 'email' => $reminder->email, 'customer_id' => $reminder->customer_id,
                'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->sendReminders()->expectsOutputToContain('Sent 0 reminder(s).')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame([[$this->cartGroup(), 1]], $this->ledger());
    }

    /** Documents the contract of the table the command writes to; it asserts the key of the table this class builds, not the live one. */
    public function test_contract_the_unique_key_of_the_ledger_refuses_a_second_row_for_the_same_cart_group_and_stage_and_allows_another_stage_or_group(): void
    {
        $reminder = ['cart_group_id' => 'group-1', 'customer_id' => 1, 'email' => 'shopper@example.test', 'stage' => 1, 'cart_value' => 100, 'item_count' => 1, 'sent_at' => now()];
        AbandonedCartReminder::create($reminder);

        try {
            AbandonedCartReminder::create($reminder);
            $this->fail('A second row for the same cart group and stage was accepted.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, AbandonedCartReminder::count());
        }

        AbandonedCartReminder::create([...$reminder, 'stage' => 2]);
        AbandonedCartReminder::create([...$reminder, 'cart_group_id' => 'group-2']);

        $this->assertSame(3, AbandonedCartReminder::count());
    }

    public function test_a_second_stage_of_the_same_cart_group_is_sent(): void
    {
        Mail::fake();

        $this->sendReminders(stage: 1)->assertSuccessful();
        $this->sendReminders(stage: 2)->expectsOutputToContain('Sent 1 reminder(s).')->assertSuccessful();
        $this->sendReminders(stage: 2)->expectsOutputToContain('No abandoned carts match the current thresholds.')->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, 2);
        Mail::assertSent(AbandonedCartReminderMail::class, static fn (AbandonedCartReminderMail $mail): bool => $mail->reminder->stage === 2);
        $this->assertSame([[$this->cartGroup(), 1], [$this->cartGroup(), 2]], $this->ledger());
    }
}
