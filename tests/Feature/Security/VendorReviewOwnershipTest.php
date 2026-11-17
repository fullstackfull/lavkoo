<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;

/**
 * The vendor panel's review pages reach a review by the id in the URL or the form. The `reviews.view` permission only says that a seller may
 * work on reviews, so each handler must answer only for a review of the seller's own product: another seller's review, and the review of an
 * in-house product whose user_id happens to equal the seller's id, are not found, and nothing changes.
 */
class VendorReviewOwnershipTest extends VendorPanelTestCase
{
    private const OWN_REVIEW = 100;
    private const OTHER_SELLERS_REVIEW = 200;
    private const IN_HOUSE_REVIEW = 300;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[self::OWN_REVIEW, self::OWN_PRODUCT], [self::OTHER_SELLERS_REVIEW, self::OTHER_SELLERS_PRODUCT], [self::IN_HOUSE_REVIEW, self::IN_HOUSE_PRODUCT]] as [$reviewId, $productId]) {
            DB::table('reviews')->insert([
                'id' => $reviewId, 'product_id' => $productId, 'customer_id' => 7, 'rating' => 5, 'status' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_a_seller_cannot_show_or_hide_another_sellers_review(): void
    {
        $response = $this->signInAs(self::SELLER)->getJson('/vendor/reviews/update-status/' . self::OTHER_SELLERS_REVIEW . '/1');

        $response->assertNotFound()->assertJson(['status' => 0]);
        $this->assertSame(0, $this->statusOf(self::OTHER_SELLERS_REVIEW));
    }

    public function test_a_seller_cannot_show_or_hide_the_review_of_an_in_house_product_sharing_their_id(): void
    {
        $response = $this->signInAs(self::SELLER)->getJson('/vendor/reviews/update-status/' . self::IN_HOUSE_REVIEW . '/1');

        $response->assertNotFound()->assertJson(['status' => 0]);
        $this->assertSame(0, $this->statusOf(self::IN_HOUSE_REVIEW));
    }

    public function test_a_seller_can_still_show_and_hide_their_own_review(): void
    {
        $this->signInAs(self::SELLER);

        $this->getJson('/vendor/reviews/update-status/' . self::OWN_REVIEW . '/1')->assertOk()->assertJson(['status' => 1]);
        $this->assertSame(1, $this->statusOf(self::OWN_REVIEW));

        $this->getJson('/vendor/reviews/update-status/' . self::OWN_REVIEW . '/0')->assertOk()->assertJson(['status' => 1]);
        $this->assertSame(0, $this->statusOf(self::OWN_REVIEW));
    }

    public function test_a_seller_cannot_reply_to_another_sellers_review(): void
    {
        $response = $this->signInAs(self::SELLER)->post('/vendor/reviews/add-review-reply', ['review_id' => self::OTHER_SELLERS_REVIEW, 'reply_text' => 'Spam from another seller']);

        $response->assertRedirect();
        $this->assertSame(0, DB::table('review_replies')->count());
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_a_seller_cannot_reply_to_the_review_of_an_in_house_product_sharing_their_id(): void
    {
        $this->signInAs(self::SELLER)->post('/vendor/reviews/add-review-reply', ['review_id' => self::IN_HOUSE_REVIEW, 'reply_text' => 'Speaking for the marketplace']);

        $this->assertSame(0, DB::table('review_replies')->count());
    }

    public function test_a_seller_can_still_reply_to_their_own_review_and_a_second_reply_replaces_the_first(): void
    {
        $this->signInAs(self::SELLER);

        $this->post('/vendor/reviews/add-review-reply', ['review_id' => self::OWN_REVIEW, 'reply_text' => 'Thank you'])->assertRedirect();
        $this->post('/vendor/reviews/add-review-reply', ['review_id' => self::OWN_REVIEW, 'reply_text' => 'Thank you very much'])->assertRedirect();

        $replies = DB::table('review_replies')->get();
        $this->assertCount(1, $replies);
        $this->assertSame(
            ['review_id' => self::OWN_REVIEW, 'added_by' => 'seller', 'added_by_id' => self::SELLER, 'reply_text' => 'Thank you very much'],
            ['review_id' => (int) $replies[0]->review_id, 'added_by' => $replies[0]->added_by, 'added_by_id' => (int) $replies[0]->added_by_id, 'reply_text' => $replies[0]->reply_text],
        );
    }

    private function statusOf(int $reviewId): int
    {
        return (int) DB::table('reviews')->where('id', $reviewId)->value('status');
    }
}
