<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Exceptions\PlatformAdminApiException;
use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\PlatformAdminAudit;
use App\Services\PlatformAdmin\PlatformAdminDevices;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /** The longest push token this API stores. FCM's are about 160 characters; anything far longer is not one. */
    private const PUSH_TOKEN_LIMIT = 512;

    public function __construct(private readonly PlatformAdminDevices $devices)
    {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'The devices of THIS session that alerts are sent to',
        description: 'The push token itself is never answered back: a device is identified by an id of its own, and the answer says which platform it is and which topics the server subscribed it to.',
        idempotent: true,
    )]
    public function index(Request $request): JsonResponse
    {
        return PlatformAdminResponses::data(data: [
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'devices' => $this->devices->ofSession(principal: PlatformAdminPrincipal::fromRequest(request: $request)),
        ]);
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'Register this device for alerts of the markets this grant allows',
        description: 'The body is the platform (`android` or `ios`) and the device\'s push token. The TOPICS ARE THE SERVER\'S: a device cannot ask for one, so it cannot ask for a market its grant does not allow nor for a topic of the seller app, and the answer says which it was subscribed to. The device belongs to this session and goes when the session does -- signed out, revoked from another device, or evicted to make room. Registering the same device again in the same session replaces its topics, which is what a grant changed since needs. Nothing of business data is read or written.',
        idempotent: true,
    )]
    public function store(Request $request): JsonResponse
    {
        $principal = PlatformAdminPrincipal::fromRequest(request: $request);
        $platform = $this->devices->platformOf(given: $request->input('platform'));
        $pushToken = $request->input('push_token');

        if ($platform === null) {
            throw PlatformAdminApiException::invalidDevicePlatform();
        }

        if (!is_string($pushToken) || trim($pushToken) === '' || strlen($pushToken) > self::PUSH_TOKEN_LIMIT) {
            throw PlatformAdminApiException::invalidPushToken();
        }

        $device = $this->devices->register(principal: $principal, platform: $platform, pushToken: trim($pushToken));
        $request->attributes->set(PlatformAdminAudit::DETAIL_ATTRIBUTE, ['device_id' => (int) $device->getKey(), 'platform' => $platform, 'topics' => $device->topicList()]);

        return PlatformAdminResponses::data(data: [
            'id' => (int) $device->getKey(),
            'platform' => $platform,
            'topics' => $device->topicList(),
        ]);
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'Stop sending alerts to one device of this session',
        description: 'The row goes and the push token is unsubscribed, in that order, so a push service that cannot be reached still leaves nothing registered. A device of another session is not found.',
        idempotent: false,
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $principal = PlatformAdminPrincipal::fromRequest(request: $request);

        if (!$this->devices->forget(principal: $principal, deviceId: $id)) {
            throw PlatformAdminApiException::deviceNotFound();
        }

        $request->attributes->set(PlatformAdminAudit::DETAIL_ATTRIBUTE, ['device_id' => $id, 'removed' => 1]);

        return PlatformAdminResponses::data(data: ['removed' => true]);
    }
}
