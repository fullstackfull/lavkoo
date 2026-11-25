<?php

namespace App\Services;

use App\Services\Notification\BroadcastMarkets;
use App\Traits\FileManagerTrait;

class NotificationService
{
    use FileManagerTrait;

    /**
     * @param list<string> $markets the markets this broadcast is for; empty means every market, which is what a broadcast has always been
     */
    public function getNotificationAddData(object $request, array $markets = []):array
    {
        $image = $request['image'] ? $this->upload(dir:'notification/', format: 'webp',image:  $request->file('image')) : '';
        return [
            'title' => $request['title'],
            'description' => $request['description'],
            'image' => $image,
            'notification_count' => 1,
            ...app(BroadcastMarkets::class)->columnFor(markets: $markets),
        ];
    }
    /**
     * @param list<string>|null $markets the markets to change this broadcast to, or null to leave the ones it was sent with alone
     */
    public function getNotificationUpdateData(object $request,string|null $notificationImage, ?array $markets = null):array
    {
        $image = $request['image'] ? $this->update(dir:'notification/', oldImage: $notificationImage, format: 'webp',image:  $request->file('image')) : $notificationImage;
        return [
            'title' => $request['title'],
            'description' => $request['description'],
            'image' => $image,
            ...($markets === null ? [] : app(BroadcastMarkets::class)->columnFor(markets: $markets)),
        ];
    }
}
