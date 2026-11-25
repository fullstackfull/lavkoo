<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationSeen;
use App\Services\Notification\BroadcastMarkets;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * The app's inbox of broadcasts, for the market the app is being served (I1-04).
 *
 * It listed every broadcast the platform had ever sent, because there was one market. A Saudi customer was therefore shown a promotion meant
 * for Kuwait -- an offer that does not apply to them, in a currency that is not theirs -- and the unread counter counted it. The list is now
 * the broadcasts that name this market and the ones that name none; a broadcast that names no market is still every customer's, which is
 * every row an installation already has.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly BroadcastMarkets $markets)
    {
    }

    public function list(Request $request): array
    {
        $notification_data = $this->markets->onlyForTheMarketInForce(Notification::active()->where(['sent_to' => 'customer']));
        $notification = $notification_data->with('notificationSeenBy')
            ->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);

        return [
            'total_size' => $notification->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'new_notification' => $notification_data->whereDoesntHave('notificationSeenBy')->count(),
            'notification' => $notification->items()
        ];
    }

    public function notification_seen(Request $request): array
    {
        $user = $request->user();
        NotificationSeen::updateOrInsert(['user_id' => $user->id, 'notification_id' => $request->id], [
            'created_at' => Carbon::now(),
        ]);

        // The counter counts the same rows the list shows: a counter of three over a list of one is how a customer learns to ignore the badge.
        $notification_count = $this->markets->onlyForTheMarketInForce(Notification::active()->where('sent_to', 'customer'))
            ->whereDoesntHave('notificationSeenBy')
            ->count();

        return [
            'notification_count' => $notification_count,
        ];
    }
}
