<?php

namespace App\Listeners;

use App\Events\RestockProductNotificationEvent;
use App\Jobs\Concerns\MarketOfTheWork;
use App\Jobs\Middleware\ServeInItsStorefront;
use App\Services\Storefront\StorefrontQueues;
use App\Traits\EmailTemplateTrait;
use App\Traits\PushNotificationTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Listeners\Concerns\QueuedMailDelivery;

/**
 * Queued: this listener sends mail, and sending inline let an unreachable SMTP host
 * hold the whole request until the connect timed out — measured at ~60 seconds per
 * recipient on real data. The mail host comes from business_settings, and
 * EmailTemplateTrait::sendingMail() already swallows the exception, so the failure
 * was silent as well as slow.
 *
 * It runs in the market of the PRODUCT, not of the request that raised the event, which is why it does not use
 * {@see \App\Listeners\Concerns\RunsInTheDispatchingStorefront}. Stock returning is noticed by `ProductService` on a seller or admin
 * request, where no market is bound — an operator sees every country — so the dispatching request can say nothing. A product belongs to
 * exactly one country (GCC-02/03), and the people who asked to hear about it are that country's customers, so the notice is written in that
 * market's language and money and in no other's.
 */
class RestockProductNotificationListener implements ShouldQueue
{
    use EmailTemplateTrait, PushNotificationTrait, QueuedMailDelivery;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(RestockProductNotificationEvent $event): void
    {
        $this->sendNotification($event);
    }

    /**
     * The market this notice is written in, captured while the event is being dispatched because Laravel serialises a queued listener's
     * middleware with the job and never calls the listener's constructor.
     *
     * @param mixed ...$event the event, as the dispatcher passes it when it asks a listener for its middleware
     *
     * @return array<int, object>
     */
    public function middleware(mixed ...$event): array
    {
        return [new ServeInItsStorefront(country: $this->marketOfTheRestockedProduct(...$event))];
    }

    /** The queue this notice waits on: the product's market's own while each market has one, and today's queue otherwise. */
    public function viaQueue(mixed ...$event): ?string
    {
        return StorefrontQueues::forMarket($this->marketOfTheRestockedProduct(...$event));
    }

    /**
     * The market of the product the event names, or the market the raising request happened to be serving when the product cannot be read —
     * which is null on every path that raises this event today, and so is the behaviour this listener has always had.
     */
    private function marketOfTheRestockedProduct(mixed ...$event): ?string
    {
        $productId = data_get($event[0] ?? null, 'data.product_id');

        return MarketOfTheWork::ofProduct(productId: is_numeric($productId) ? (int) $productId : null)
            ?? ServeInItsStorefront::countryOfTheDispatchingProcess();
    }

    private function sendNotification(RestockProductNotificationEvent $event): void
    {
        $data = $event->data;
        $this->sendNotificationToHttp([
            'message' => [
                'topic' => $data['topic'],
                'data' => [
                    'title' => (string)$data['title'],
                    'product_id' => (string)($data['product_id'] ?? ''),
                    'slug' => (string)($data['slug'] ?? ''),
                    'body' => (string)$data['description'],
                    'image' => $data['image'] ?? '',
                    'type' => (string)$data['type'] ?? '',
                    'status' => (string)$data['status'] ?? '',
                    'route' => (string)$data['route'] ?? '',
                    'is_read' => '0'
                ],
                'notification' => [
                    'title' => (string)$data['title'],
                    'body' => (string)$data['description'],
                ]
            ]
        ]);
    }
}
