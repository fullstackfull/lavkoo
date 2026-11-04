<?php

namespace App\Http\Controllers\Admin;


use App\Contracts\Repositories\ChattingRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DeliveryManRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Enums\ViewPaths\Admin\Chatting;
use App\Events\ChattingEvent;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\ChattingRequest;
use App\Services\ChattingService;
use App\Traits\PushNotificationTrait;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

class ChattingController extends BaseController
{
    use PushNotificationTrait;

    /**
     * @param ChattingRepositoryInterface $chattingRepo
     * @param ShopRepositoryInterface $shopRepo
     * @param ChattingService $chattingService
     * @param DeliveryManRepositoryInterface $deliveryManRepo
     * @param CustomerRepositoryInterface $customerRepo
     */
    public function __construct(
        private readonly ChattingRepositoryInterface    $chattingRepo,
        private readonly ShopRepositoryInterface        $shopRepo,
        private readonly ChattingService                $chattingService,
        private readonly DeliveryManRepositoryInterface $deliveryManRepo,
        private readonly CustomerRepositoryInterface    $customerRepo,
    )
    {
    }


    /**
     * @param Request|null $request
     * @param string|array|null $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     */
    public function index(?Request $request, string|array|null $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {

        $shop = $this->shopRepo->getFirstWhere(params: ['seller_id' => auth('seller')->id()]);
        $adminId = 0;
        if ($type == 'delivery-man') {
            $allChattingUsers = $this->chattingRepo->getListWhereNotNull(
                orderBy: ['id' => 'DESC'],
                filters: ['admin_id' => $adminId],
                whereNotNull: ['delivery_man_id', 'admin_id'],
                relations: ['deliveryMan'],
                dataLimit: 'all'
            )->unique('delivery_man_id');

            if (count($allChattingUsers) > 0) {
                $lastChatUser = $allChattingUsers[0]->deliveryMan;
                $this->chattingRepo->updateAllWhere(
                    params: ['admin_id' => $adminId, 'delivery_man_id' => $lastChatUser['id']],
                    data: ['seen_by_admin' => 1]
                );

                $deliveryMenUnreadMessagesQueryParams = [
                    'admin_id' => $adminId,
                    'usersColumn' => 'delivery_man_id',
                    'filteredByColumn' => 'seen_by_admin',
                    'notificationReceiver' => 'admin',
                ];

                $countUnreadMessages = $this->chattingRepo->countUnreadMessages(data: $deliveryMenUnreadMessagesQueryParams);

                $chattingMessages = $this->chattingRepo->getListWhereNotNull(
                    orderBy: ['id' => 'DESC'],
                    filters: ['admin_id' => $adminId, 'delivery_man_id' => $lastChatUser->id],
                    whereNotNull: ['delivery_man_id', 'admin_id'],
                    relations: ['deliveryMan'],
                    dataLimit: 'all'
                );

                return view('admin-views.chatting.index', [
                    'userType' => $type,
                    'allChattingUsers' => $allChattingUsers,
                    'lastChatUser' => $lastChatUser,
                    'chattingMessages' => $chattingMessages,
                    'countUnreadMessages' => $countUnreadMessages
                ]);
            }
        } elseif ($type == 'customer') {
            $allChattingUsers = $this->chattingRepo->getListWhereNotNull(
                orderBy: ['id' => 'DESC'],
                filters: ['admin_id' => $adminId],
                whereNotNull: ['user_id', 'admin_id'],
                relations: ['customer'],
                dataLimit: 'all'
            )->unique('user_id');

            if (count($allChattingUsers) > 0) {
                $lastChatUser = $allChattingUsers[0]->customer;
                if ($lastChatUser) {
                    $this->chattingRepo->updateAllWhere(
                        params: ['admin_id' => $adminId, 'user_id' => $lastChatUser['id']],
                        data: ['seen_by_admin' => 1]
                    );
                }

                $customersUnreadMessagesQueryParams = [
                    'admin_id' => $adminId,
                    'usersColumn' => 'user_id',
                    'filteredByColumn' => 'seen_by_admin',
                    'notificationReceiver' => 'admin',
                ];

                $countUnreadMessages = $this->chattingRepo->countUnreadMessages(data: $customersUnreadMessagesQueryParams);

                $chattingMessages = $this->chattingRepo->getListWhereNotNull(
                    orderBy: ['id' => 'DESC'],
                    filters: ['admin_id' => $adminId, 'user_id' => $lastChatUser?->id],
                    whereNotNull: ['user_id', 'admin_id'],
                    relations: ['customer'],
                    dataLimit: 'all'
                );
                return view('admin-views.chatting.index', [
                    'userType' => $type,
                    'allChattingUsers' => $allChattingUsers,
                    'lastChatUser' => $lastChatUser,
                    'chattingMessages' => $chattingMessages,
                    'countUnreadMessages' => $countUnreadMessages
                ]);
            }
        }
        return view('admin-views.chatting.index', compact('shop'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function getMessageByUser(Request $request): JsonResponse
    {
        $adminId = 0;
        $data = [];
        if ($request->has(key: 'delivery_man_id')) {
            $getUser = $this->deliveryManRepo->getFirstWhere(params: ['id' => $request['delivery_man_id']]);
            $this->chattingRepo->updateAllWhere(
                params: ['admin_id' => $adminId, 'delivery_man_id' => $request['delivery_man_id']],
                data: ['seen_by_admin' => 1]);

            $chattingMessages = $this->chattingRepo->getListWhereNotNull(
                orderBy: ['id' => 'DESC'],
                filters: ['admin_id' => $adminId, 'delivery_man_id' => $request['delivery_man_id']],
                whereNotNull: ['delivery_man_id', 'admin_id'],
                dataLimit: 'all'
            );
            $data = self::getRenderMessagesView(user: $getUser, message: $chattingMessages, type: 'delivery_man');
        } elseif ($request->has(key: 'user_id')) {
            $getUser = $this->customerRepo->getFirstWhere(params: ['id' => $request['user_id']]);
            $this->chattingRepo->updateAllWhere(
                params: ['admin_id' => $adminId, 'user_id' => $request['user_id']],
                data: ['seen_by_admin' => 1]
            );

            $chattingMessages = $this->chattingRepo->getListWhereNotNull(
                orderBy: ['id' => 'DESC'],
                filters: ['admin_id' => $adminId, 'user_id' => $request['user_id']],
                whereNotNull: ['user_id', 'admin_id'],
                dataLimit: 'all'
            );
            $data = self::getRenderMessagesView(user: $getUser, message: $chattingMessages, type: 'customer');
        }
        return response()->json($data);
    }

    /**
     * @param ChattingRequest $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function addAdminMessage(ChattingRequest $request): JsonResponse
    {
        if($request->hasFile('file')) {
            foreach ($request->file('file') as $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                if (in_array($extension, getDisallowedExtensionsListArray())) {
                    if (env('APP_MODE', 'dev') == 'demo') {
                        return response()->json([
                            'status' => 'error',
                            'message' => translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode')
                        ]);
                    }
                    return response()->json([
                        'status' => 'error',
                        'message' => translate('Files_with_extensions_like') .
                            ' (' . implode(', ', array_map(fn($ext) => '.' . $ext, getDisallowedExtensionsListArray())) . ') ' .
                            translate('are_not_supported') . '!'
                    ]);
                }
            }
        }

        $data = [];
        $shop = [
            'name' => getInHouseShopConfig(key: 'name')
        ];
        $messageForm = (object)[
            'f_name' => 'admin',
            'shop' => (object)$shop,
        ];
        if ($request->has(key: 'delivery_man_id')) {
            $this->chattingRepo->add(
                data: $this->chattingService->addChattingData(
                    request: $request,
                    type: 'delivery-man',
                )
            );
            $deliveryMan = $this->deliveryManRepo->getFirstWhere(params: ['id' => $request['delivery_man_id']]);
            event(new ChattingEvent(key: 'message_from_admin', type: 'delivery_man', userData: $deliveryMan, messageForm: $messageForm));

            $chattingMessages = $this->chattingRepo->getListWhereNotNull(
                orderBy: ['id' => 'DESC'],
                filters: ['admin_id' => 0, 'delivery_man_id' => $request['delivery_man_id']],
                whereNotNull: ['delivery_man_id', 'admin_id'],
                dataLimit: 'all'
            );
            $data = self::getRenderMessagesView(user: $deliveryMan, message: $chattingMessages, type: 'delivery_man');
        } elseif ($request->has(key: 'user_id')) {
            $this->chattingRepo->add(
                data: $this->chattingService->addChattingData(
                    request: $request,
                    type: 'customer',
                )
            );
            $customer = $this->customerRepo->getFirstWhere(params: ['id' => $request['user_id']]);
            event(new ChattingEvent(key: 'message_from_admin', type: 'customer', userData: $customer, messageForm: $messageForm));

            $chattingMessages = $this->chattingRepo->getListWhereNotNull(
                orderBy: ['id' => 'DESC'],
                filters: ['admin_id' => 0, 'user_id' => $request['user_id']],
                whereNotNull: ['user_id', 'admin_id'],
                dataLimit: 'all'
            );
            $data = self::getRenderMessagesView(user: $customer, message: $chattingMessages, type: 'customer');
        }
        return response()->json($data);
    }

    /**
     * @param string $tableName
     * @param string $orderBy
     * @param string|int|null $id
     * @return Collection
     */
    protected function getChatList(string $tableName, string $orderBy, string|int|null $id = null): Collection
    {
        $adminId = 0;
        $columnName = $tableName == 'users' ? 'user_id' : 'delivery_man_id';
        $filters = isset($id) ? ['chattings.admin_id' => $adminId, $columnName => $id] : ['chattings.admin_id' => $adminId];
        return $this->chattingRepo->getListBySelectWhere(
            joinColumn: [$tableName, $tableName . '.id', '=', 'chattings.' . $columnName],
            select: ['chattings.*', $tableName . '.f_name', $tableName . '.l_name', $tableName . '.image', $tableName . '.country_code', $tableName . '.phone'],
            filters: $filters,
            orderBy: ['chattings.id' => $orderBy],
        );
    }

    /**
     * @param object $user
     * @param object $message
     * @param string $type
     * @return array
     * @throws Throwable
     */
    protected function getRenderMessagesView(object $user, object $message, string $type): array
    {
        $userData = [
            'name' => $user['f_name'] . ' ' . $user['l_name'],
            'phone' => $user['country_code'] . $user['phone'],
            'detailsRoute' => $type == 'customer' ? route('admin.customer.view', $user['id']) : '#',
        ];
        $userData['image'] = getStorageImages(path: $user->image_full_url, type: 'backend-profile');
        return [
            'userData' => $userData,
            'chattingMessages' => view('admin-views.chatting.messages', [
                'lastChatUser' => $user,
                'userType' => $type,
                'chattingMessages' => $message
            ])->render(),
        ];
    }
}
