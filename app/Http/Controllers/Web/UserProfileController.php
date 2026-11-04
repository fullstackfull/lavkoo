<?php

namespace App\Http\Controllers\Web;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\OrderDetailsRewardsRepositoryInterface;
use App\Contracts\Repositories\OrderStatusHistoryRepositoryInterface;
use App\Contracts\Repositories\RestockProductRepositoryInterface;
use App\Contracts\Repositories\RestockProductCustomerRepositoryInterface;
use App\Contracts\Repositories\RobotsMetaContentRepositoryInterface;
use App\Enums\WebConfigKey;
use App\Events\RefundEvent;
use App\Http\Requests\Web\CustomerProfileUpdateRequest;
use App\Models\OfflinePaymentMethod;
use App\Models\OrderEditHistory;
use App\Models\OrderStatusHistory;
use App\Models\SupportTicketConv;
use App\Services\OrderStatusHistoryService;
use App\Traits\PdfGenerator;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\DeliveryMan;
use App\Models\DeliveryZipCode;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ProductCompare;
use App\Models\RefundRequest;
use App\Models\Review;
use App\Models\Seller;
use App\Models\ShippingAddress;
use App\Models\SupportTicket;
use App\Models\Wishlist;
use App\Traits\CommonTrait;
use App\Models\User;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use App\Utils\ImageManager;
use App\Utils\OrderManager;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auction\app\Services\AuctionAccountDeletionGuardService;
use function App\Utils\payment_gateways;

class UserProfileController extends Controller
{
    use CommonTrait, PdfGenerator;

    public function __construct(
        private Order                                              $order,
        private Seller                                             $seller,
        private Product                                            $product,
        private Review                                             $review,
        private DeliveryMan                                        $deliver_man,
        private ProductCompare                                     $compare,
        private Wishlist                                           $wishlist,
        private readonly BusinessSettingRepositoryInterface        $businessSettingRepo,
        private readonly RobotsMetaContentRepositoryInterface      $robotsMetaContentRepo,
        private readonly RestockProductRepositoryInterface         $restockProductRepo,
        private readonly RestockProductCustomerRepositoryInterface $restockProductCustomerRepo,
        private readonly OrderDetailsRewardsRepositoryInterface    $orderDetailsRewardsRepo,
        private readonly OrderStatusHistoryRepositoryInterface     $orderStatusHistoryRepo,
        private readonly OrderStatusHistoryService                 $orderStatusHistoryService,
    )
    {

    }

    public function user_account(Request $request)
    {
        $country_restrict_status = getWebConfig(name: 'delivery_country_restriction');
        $customerDetail = User::where('id', auth('customer')->id())->first();
        return view(VIEW_FILE_NAMES['user_account'], compact('customerDetail'));

    }

    public function getUserProfileUpdate(CustomerProfileUpdateRequest $request): RedirectResponse
    {
        $imageName = $request->file('image') ? ImageManager::update('profile/', auth('customer')->user()->image, 'webp', $request->file('image')) : auth('customer')->user()->image;
        $user = auth('customer')->user();
        User::find($user['id'])->update([
            'f_name' => $request['f_name'],
            'l_name' => $request['l_name'],
            'phone' => $user['is_phone_verified'] ? $user['phone'] : $request['phone'],
            'email' => $request['email'],
            'is_phone_verified' => $request['phone'] == $user['phone'] ? $user['is_phone_verified'] : 0,
            'is_email_verified' => $request['email'] == $user['email'] ? $user['is_email_verified'] : 0,
            'image' => $imageName,
            'password' => strlen($request['password']) > 5 ? bcrypt($request['password']) : auth('customer')->user()->password,
        ]);

        Toastr::info(translate('updated_successfully'));
        return redirect()->back();
    }

    public function account_address_add()
    {
        $country_restrict_status = getWebConfig(name: 'delivery_country_restriction');
        $zip_restrict_status = getWebConfig(name: 'delivery_zip_code_area_restriction');
        $default_location = getWebConfig(name: 'default_location');

        $countries = $country_restrict_status ? $this->get_delivery_country_array() : COUNTRIES;

        $zip_codes = $zip_restrict_status ? DeliveryZipCode::all() : 0;

        return view(VIEW_FILE_NAMES['account_address_add'], compact('countries', 'zip_restrict_status', 'zip_codes', 'default_location'));
    }

    public function account_delete($id)
    {
        if (auth('customer')->id() == $id) {
            $user = User::find($id);

            $ongoing = ['out_for_delivery', 'processing', 'confirmed', 'pending'];
            $order = Order::where('customer_id', $user->id)->whereIn('order_status', $ongoing)->count();
            if ($order > 0) {
                Toastr::warning(translate('you_can_not_delete_account_due_ongoing_order'));
                return redirect()->back();
            }

            if (getCheckAddonPublishedStatus(moduleName: 'Auction')) {
                $auctionBlocker = app(AuctionAccountDeletionGuardService::class)->blockingReason((int) $user->id);
                if ($auctionBlocker !== null) {
                    Toastr::warning(translate($auctionBlocker));
                    return redirect()->back();
                }
            }

            auth()->guard('customer')->logout();

            ImageManager::delete('/profile/' . $user['image']);
            session()->forget('wish_list');

            $user->delete();
            Toastr::info(translate('Your_account_deleted_successfully!!'));
            return redirect()->route('home');
        }

        Toastr::warning(translate('access_denied') . '!!');
        return back();
    }

    public function account_address(): View|RedirectResponse
    {
        $country_restrict_status = getWebConfig(name: 'delivery_country_restriction');
        $zip_restrict_status = getWebConfig(name: 'delivery_zip_code_area_restriction');

        $countries = $country_restrict_status ? $this->get_delivery_country_array() : COUNTRIES;
        $zip_codes = $zip_restrict_status ? DeliveryZipCode::all() : 0;

        $countriesName = [];
        $countriesCode = [];
        foreach ($countries as $country) {
            $countriesName[] = $country['name'];
            $countriesCode[] = $country['code'];
        }

        if (auth('customer')->check()) {
            $shippingAddresses = ShippingAddress::where('customer_id', auth('customer')->id())->latest()->get();
            return view('web-views.users-profile.account-address', compact('shippingAddresses', 'country_restrict_status', 'zip_restrict_status', 'countries', 'zip_codes', 'countriesName', 'countriesCode'));
        } else {
            return redirect()->route('home');
        }
    }

    public function address_store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required',
            'phone' => 'required|max:20',
            'city' => 'required',
            // Required only when deliveries are limited to an allow-list of postcodes; markets
            // without postcodes should never be blocked by an empty field.
            'zip' => getWebConfig(name: 'delivery_zip_code_area_restriction') ? 'required' : 'nullable',
            'country' => 'required',
            'address' => 'required',
        ]);

        $numericPhoneValue = preg_replace('/[^0-9]/', '', $request['phone']);
        $numericLength = strlen($numericPhoneValue);
        if ($numericLength < 4 || $numericLength > 20) {
            $request->validate([
                'phone' => 'min:5|max:20',
            ], [
                'phone.min' => translate('The_phone_number_must_be_at_least_4_characters'),
                'phone.max' => translate('The_phone_number_may_not_be_greater_than_20_characters'),
            ]);
        }

        $country_restrict_status = getWebConfig(name: 'delivery_country_restriction');
        $zip_restrict_status = getWebConfig(name: 'delivery_zip_code_area_restriction');

        $country_exist = self::delivery_country_exist_check($request->country);
        $zipcode_exist = self::delivery_zipcode_exist_check($request->zip);

        if ($country_restrict_status && !$country_exist) {
            Toastr::error(translate('Delivery_unavailable_in_this_country!'));
            return back();
        }

        if ($zip_restrict_status && !$zipcode_exist) {
            Toastr::error(translate('Delivery_unavailable_in_this_zip_code_area!'));
            return back();
        }

        $address = [
            'customer_id' => auth('customer')->check() ? auth('customer')->id() : null,
            'contact_person_name' => $request['name'],
            'address_type' => Str::lower($request['address_type'] ?? ''),
            'address' => $request['address'],
            'city' => $request['city'],
            'zip' => $request['zip'],
            'country' => $request['country'],
            'phone' => $request['phone'],
            'is_billing' => $request['is_billing'],
            'latitude' => $request['latitude'],
            'longitude' => $request['longitude'],
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('shipping_addresses')->insert($address);

        Toastr::success(translate('address_added_successfully!'));

        return back();
    }

    public function address_edit(Request $request, $id)
    {
        $shippingAddress = ShippingAddress::where('customer_id', auth('customer')->id())->find($id);
        $country_restrict_status = getWebConfig(name: 'delivery_country_restriction');
        $zip_restrict_status = getWebConfig(name: 'delivery_zip_code_area_restriction');

        $delivery_countries = $country_restrict_status ? self::get_delivery_country_array() : COUNTRIES;
        $delivery_zipcodes = $zip_restrict_status ? DeliveryZipCode::all() : 0;

        $countriesName = [];
        $countriesCode = [];
        foreach ($delivery_countries as $country) {
            $countriesName[] = $country['name'];
            $countriesCode[] = $country['code'];
        }

        if (isset($shippingAddress)) {
            return view(VIEW_FILE_NAMES['account_address_edit'], compact('shippingAddress', 'country_restrict_status', 'zip_restrict_status', 'delivery_countries', 'delivery_zipcodes', 'countriesName', 'countriesCode'));
        } else {
            Toastr::warning(translate('access_denied'));
            return back();
        }
    }

    public function address_update(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required',
            'phone' => 'required|max:20',
            'city' => 'required',
            // Required only when deliveries are limited to an allow-list of postcodes; markets
            // without postcodes should never be blocked by an empty field.
            'zip' => getWebConfig(name: 'delivery_zip_code_area_restriction') ? 'required' : 'nullable',
            'country' => 'required',
            'address' => 'required',
        ]);

        $numericPhoneValue = preg_replace('/[^0-9]/', '', $request['phone']);
        $numericLength = strlen($numericPhoneValue);
        if ($numericLength < 4 || $numericLength > 20) {
            $request->validate([
                'phone' => 'min:5|max:20',
            ], [
                'phone.min' => translate('The_phone_number_must_be_at_least_4_characters'),
                'phone.max' => translate('The_phone_number_may_not_be_greater_than_20_characters'),
            ]);
        }

        $country_restrict_status = getWebConfig(name: 'delivery_country_restriction');
        $zip_restrict_status = getWebConfig(name: 'delivery_zip_code_area_restriction');

        $country_exist = self::delivery_country_exist_check($request->country);
        $zipcode_exist = self::delivery_zipcode_exist_check($request->zip);

        if ($country_restrict_status && !$country_exist) {
            Toastr::error(translate('Delivery_unavailable_in_this_country!'));
            return back();
        }

        if ($zip_restrict_status && !$zipcode_exist) {
            Toastr::error(translate('Delivery_unavailable_in_this_zip_code_area!'));
            return back();
        }

        $updateAddress = [
            'contact_person_name' => $request->name,
            'address_type' => Str::lower($request['address_type'] ?? ''),
            'address' => $request->address,
            'city' => $request->city,
            'zip' => $request->zip,
            'country' => $request->country,
            'phone' => $request->phone,
            'is_billing' => $request->is_billing,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (auth('customer')->check()) {
            // Scope to the owner: without customer_id any logged-in customer could rewrite another
            // customer's saved address (e.g. redirect their deliveries) by passing its id.
            $updated = ShippingAddress::where('id', $request->id)
                ->where('customer_id', auth('customer')->id())
                ->update($updateAddress);
            if ($updated) {
                Toastr::success(translate('address_updated_successfully!'));
            } else {
                Toastr::error(translate('address_not_found!'));
            }
        } else {
            Toastr::error(translate('Insufficient_permission!'));
        }
        return redirect()->route('account-address');
    }

    public function address_delete(Request $request)
    {
        if (auth('customer')->check()) {
            // Scope to the owner: destroy() by raw id let any logged-in customer delete another
            // customer's saved address.
            ShippingAddress::where('id', $request->id)
                ->where('customer_id', auth('customer')->id())
                ->delete();
            Toastr::success(translate('address_Delete_Successfully'));
            return redirect()->back();
        } else {
            return redirect()->back();
        }
    }

    public function account_payment()
    {
        if (auth('customer')->check()) {
            return view('web-views.users-profile.account-payment');

        } else {
            return redirect()->route('home');
        }

    }

    public function account_order(Request $request)
    {
        $order_by = $request->order_by ?? 'desc';
        $paymentGatewayList = payment_gateways();
        $offlinePaymentMethods = OfflinePaymentMethod::where('status', 1)->get();
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $cashOnDeliveryStatus = getWebConfig(name: 'cash_on_delivery');
        $orders = $this->order->with('orderEditHistory', 'details', 'seller.shop')
            ->withSum('orderDetails', 'qty')
            ->where(['customer_id' => auth('customer')->id(), 'is_guest' => '0'])
            ->orderBy('id', $order_by)
            ->paginate(10)->appends($request->all());
        return view(VIEW_FILE_NAMES['account_orders'], compact(
            'orders',
            'order_by',
            'paymentGatewayList',
            'offlinePaymentMethods',
            'offlinePaymentStatus',
            'cashOnDeliveryStatus',
        ));
    }

    public function account_order_details(Request $request): View|RedirectResponse
    {
        $availablePaymentMethod = [];
        $order = $this->order->with(['deliveryManReview', 'customer', 'offlinePayments', 'details.productAllStatus', 'details.refundRequest'])->where(['id' => $request['id'], 'customer_id' => auth('customer')->id(), 'is_guest' => '0'])->first();

        if ($order) {
            $offlinePaymentMethods = OfflinePaymentMethod::where('status', 1)->get();
            $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
            $paymentGatewayList = payment_gateways();
            $isPhysicalProduct = $order->details()->whereHas('product', fn($q) => $q->where('product_type', 'physical'))->exists();;
            $cashOnDeliveryStatus = getWebConfig(name: 'cash_on_delivery');

            if ($cashOnDeliveryStatus && $cashOnDeliveryStatus['status'] && $isPhysicalProduct) {
                $availablePaymentMethod = ['cash_on_delivery'];
            }
            if (getWebConfig(name: 'digital_payment') && count(payment_gateways()) > 0) {
                $availablePaymentMethod = ['payment_gateways'];
            }
            if ($offlinePaymentStatus && $offlinePaymentStatus['status'] == 1 && count($offlinePaymentMethods) > 0) {
                $availablePaymentMethod = ['offline_payment'];
            }
            if (auth('customer')->check() && getWebConfig(name: 'wallet_status')) {
                $availablePaymentMethod = ['wallet_status'];
            }
            $order?->details?->map(function ($detail) use ($order) {
                $order['total_qty'] += $detail->qty;

                $reviews = Review::where(['product_id' => $detail['product_id'], 'customer_id' => auth('customer')->id()])->whereNull('delivery_man_id')->get();
                $reviewData = null;
                foreach ($reviews as $review) {
                    if ($review->order_id == $detail->order_id) {
                        $reviewData = $review;
                    }
                }
                $detail['reviewData'] = $reviewData;
                return $order;
            });

            return view(VIEW_FILE_NAMES['account_order_details'], [
                'order' => $order,
                'refund_day_limit' => getWebConfig(name: 'refund_day_limit'),
                'current_date' => Carbon::now(),
                'paymentGatewayList' => $paymentGatewayList,
                'cashOnDeliveryStatus' => getWebConfig(name: 'cash_on_delivery')['status'],
                'isPhysicalProduct' => $isPhysicalProduct,
                'offlinePaymentStatus' => $offlinePaymentStatus,
                'digitalPayment' => getWebConfig(name: 'digital_payment')['status'],
                'walletStatus' => getWebConfig(name: 'wallet_status'),
                'offlinePaymentMethods' => $offlinePaymentMethods,
                'availablePaymentMethod' => $availablePaymentMethod,
            ]);
        }

        Toastr::warning(translate('invalid_order'));
        return redirect()->route('account-oder');
    }

    public function account_order_details_seller_info(Request $request)
    {
        $paymentGatewayList = payment_gateways();
        $offlinePaymentMethods = OfflinePaymentMethod::where('status', 1)->get();
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $cashOnDeliveryStatus = getWebConfig(name: 'cash_on_delivery');

        // Ownership: scope to the customer's own order so one customer can't read another's order/seller panel.
        $order = $this->order->with(['seller.shop'])->where('customer_id', auth('customer')->id())->find($request->id);
        if (!$order) {
            Toastr::warning(translate('invalid_order'));
            return redirect()->route('account-oder');
        }

        $productIds = $this->product->active()->where(['added_by' => $order->seller_is])->where('user_id', $order->seller_id)->pluck('id')->toArray();
        $rating = $this->review->active()->whereIn('product_id', $productIds);
        $rating_count = $rating->count();
        $avg_rating = $rating->avg('rating');
        $product_count = count($productIds);

        $vendorRattingStatusPositive = 0;
        foreach ($rating->pluck('rating') as $singleRating) {
            ($singleRating >= 4 ? ($vendorRattingStatusPositive++) : '');
        }

        $rating_percentage = $rating_count != 0 ? ($vendorRattingStatusPositive * 100) / $rating_count : 0;

        return view(VIEW_FILE_NAMES['seller_info'], compact('avg_rating', 'product_count', 'rating_count', 'order', 'rating_percentage', 'paymentGatewayList', 'cashOnDeliveryStatus', 'offlinePaymentMethods', 'offlinePaymentStatus'));

    }

    public function account_order_details_delivery_man_info(Request $request)
    {
        $paymentGatewayList = payment_gateways();
        $offlinePaymentMethods = OfflinePaymentMethod::where('status', 1)->get();
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $cashOnDeliveryStatus = getWebConfig(name: 'cash_on_delivery');

        // Ownership: scope to the customer's own order so one customer can't read another's order details.
        $order = $this->order->with(['verificationImages', 'details.product', 'deliveryMan.rating', 'deliveryManReview', 'deliveryMan' => function ($query) {
            return $query->withCount('review');
        }])->where('customer_id', auth('customer')->id())->find($request->id);

        if (!$order) {
            Toastr::warning(translate('invalid_order'));
            return redirect()->route('account-oder');
        }

        foreach ($order->details as $details) {
            if ($details->product) {
                $order['product_type_check'] = $details->product->product_type;
                if ($details->product->product_type == 'physical') {
                    break;
                }
            }
        }

        $delivered_count = $this->order->where(['order_status' => 'delivered', 'delivery_man_id' => $order->delivery_man_id, 'delivery_type' => 'self_delivery'])->count();

        return view(VIEW_FILE_NAMES['delivery_man_info'], compact('delivered_count', 'order', 'paymentGatewayList', 'cashOnDeliveryStatus', 'offlinePaymentMethods', 'offlinePaymentStatus'));
    }

    public function getAccountOrderDetailsReviewsView(Request $request): View|RedirectResponse
    {
        $paymentGatewayList = payment_gateways();
        $offlinePaymentMethods = OfflinePaymentMethod::where('status', 1)->get();
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $cashOnDeliveryStatus = getWebConfig(name: 'cash_on_delivery');

        $order = $this->order->with(['deliveryManReview', 'customer', 'offlinePayments', 'details'])
            ->where(['id' => $request['id'], 'customer_id' => auth('customer')->id(), 'is_guest' => '0'])
            ->first();
        if ($order) {
            $order?->details?->map(function ($detail) use ($order) {
                $order['total_qty'] += $detail->qty;
                $reviews = Review::with('reply')
                    ->where(['product_id' => $detail['product_id'], 'customer_id' => auth('customer')->id()])
                    ->whereNull('delivery_man_id')->get();
                $reviewData = null;
                foreach ($reviews as $review) {
                    if ($review->order_id == $detail->order_id) {
                        $reviewData = $review;
                    }
                }
                if (isset($reviews[0]) && !$reviewData) {
                    $reviewData = ($reviews[0]['order_id'] != null ? $reviews[0] : null);
                }
                $detail['reviewData'] = $reviewData;
                return $order;
            });

            return view(VIEW_FILE_NAMES['order_details_review'], compact('order', 'paymentGatewayList', 'cashOnDeliveryStatus', 'offlinePaymentMethods', 'offlinePaymentStatus'));
        }
        Toastr::warning(translate('invalid_order'));
        return redirect()->route('account-oder');
    }


    public function account_wishlist()
    {
        if (auth('customer')->check()) {
            $wishlists = Wishlist::where('customer_id', auth('customer')->id())->get();
            return view('web-views.products.wishlist', compact('wishlists'));
        } else {
            return redirect()->route('home');
        }
    }

    public function account_tickets()
    {
        if (auth('customer')->check()) {
            $supportTickets = SupportTicket::where('customer_id', auth('customer')->id())->latest()->paginate(10);
            return view(VIEW_FILE_NAMES['account_tickets'], compact('supportTickets'));
        } else {
            return redirect()->route('home');
        }
    }

    public function submitSupportTicket(Request $request): RedirectResponse
    {
        $request->validate([
            'ticket_subject' => 'required',
            'ticket_type' => 'required',
            'ticket_priority' => 'required',
            'ticket_description' => 'required_without_all:image.*',
            'image.*' => 'required_without_all:ticket_description|image|mimes:jpeg,png,jpg,gif|max:6000',
        ], [
            'ticket_subject.required' => translate('The_ticket_subject_is_required'),
            'ticket_type.required' => translate('The_ticket_type_is_required'),
            'ticket_priority.required' => translate('The_ticket_priority_is_required'),
            'ticket_description.required_without_all' => translate('Either_a_ticket_description_or_an_image_is_required'),
            'image.*.required_without_all' => translate('Either_a_ticket_description_or_an_image_is_required'),
            'image.*.image' => translate('The_file_must_be_an_image'),
            'image.*.mimes' => translate('The_file_must_be_of_type:_jpeg,_png,_jpg,_gif'),
            'image.*.max' => translate('The_image_must_not_exceed_6_MB'),
        ]);

        $images = [];
        if ($request->file('image')) {
            foreach ($request['image'] as $key => $value) {
                $image_name = ImageManager::upload('support-ticket/', 'webp', $value);
                $images[] = [
                    'file_name' => $image_name,
                    'storage' => getWebConfig(name: 'storage_connection_type') ?? 'public',
                ];
            }
        }

        $ticket = [
            'subject' => $request['ticket_subject'],
            'type' => $request['ticket_type'],
            'customer_id' => auth('customer')->check() ? auth('customer')->id() : null,
            'priority' => $request['ticket_priority'],
            'description' => $request['ticket_description'],
            'attachment' => json_encode($images),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('support_tickets')->insert($ticket);
        return back();
    }

    public function single_ticket(Request $request)
    {
        $ticket = SupportTicket::with(['conversations' => function ($query) {
            $query->orderBy('id', 'desc');
        }])->where('id', $request->id)->where('customer_id', auth()->guard('customer')->id())->first();
        if (!$ticket) {
            Toastr::warning(translate('Invalid_ticket'));
            return back();
        }
        return view(VIEW_FILE_NAMES['ticket_view'], compact('ticket'));
    }

    public function comment_submit(Request $request, $id)
    {
        // Ownership: without this any logged-in customer could reopen and inject a message into another
        // customer's ticket thread by passing its id.
        $ticket = SupportTicket::where('id', $id)->where('customer_id', auth('customer')->id())->first();
        if (!$ticket) {
            Toastr::warning(translate('Invalid_ticket'));
            return back();
        }

        if ($request->file('image') == null && empty($request['comment'])) {
            Toastr::error(translate('type_something') . '!');
            return back();
        }

        DB::table('support_tickets')->where(['id' => $id])->update([
            'status' => 'open',
            'updated_at' => now(),
        ]);

        $image = [];
        if ($request->file('image')) {
            $validator = $request->validate([
                'image.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:6000'
            ]);
            foreach ($request->image as $key => $value) {
                $image_name = ImageManager::upload('support-ticket/', 'webp', $value);
                $image[] = [
                    'file_name' => $image_name,
                    'storage' => getWebConfig(name: 'storage_connection_type') ?? 'public',
                ];
            }
        }
        $data = [
            'customer_message' => $request->comment,
            'attachment' => $image,
            'support_ticket_id' => $id,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        SupportTicketConv::create($data);
        Toastr::success(translate('message_send_successfully') . '!');
        return back();
    }

    public function support_ticket_close($id)
    {
        // Ownership: scope the close to the caller's own ticket.
        DB::table('support_tickets')->where(['id' => $id])->where('customer_id', auth('customer')->id())->update([
            'status' => 'close',
            'updated_at' => now(),
        ]);
        Toastr::success(translate('ticket_closed') . '!');
        return redirect('/account-tickets');
    }


    public function support_ticket_delete(Request $request)
    {

        if (auth('customer')->check()) {
            // Ownership: without the customer_id scope any logged-in customer could delete another
            // customer's ticket (and its conversations + attachment files) by id.
            $support = SupportTicket::where('id', $request->id)->where('customer_id', auth('customer')->id())->first();
            if (!$support) {
                Toastr::warning(translate('Invalid_ticket'));
                return back();
            }

            if ($support->attachment && !is_array($support->attachment) && count(json_decode($support->attachment)) > 0) {
                foreach (json_decode($support->attachment, true) as $image) {
                    ImageManager::delete('/support-ticket/' . $image);
                }
            } else if ($support->attachment && is_array($support->attachment) && count($support->attachment) > 0) {
                foreach ($support->attachment as $image) {
                    ImageManager::delete('/support-ticket/' . $image['file_name']);
                }
            }

            foreach ($support->conversations as $conversation) {
                if ($conversation->attachment && !is_array($support->attachment) && count(json_decode($conversation->attachment)) > 0) {
                    foreach (json_decode($conversation->attachment, true) as $image) {
                        ImageManager::delete('/support-ticket/' . $image);
                    }
                } else if ($conversation->attachment && is_array($conversation->attachment) && count($conversation->attachment) > 0) {
                    foreach ($conversation->attachment as $image) {
                        ImageManager::delete('/support-ticket/' . $image['file_name']);
                    }
                }
            }
            $support->conversations()->delete();

            $support->delete();
            return redirect()->back();
        } else {
            return redirect()->back();
        }

    }

    public function track_order(): View
    {
        $robotsMetaContentData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => 'track-order']);
        if (!$robotsMetaContentData) {
            $robotsMetaContentData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => 'default']);
        }
        return view(VIEW_FILE_NAMES['tracking-page'], [
            'robotsMetaContentData' => $robotsMetaContentData
        ]);
    }

    public function track_order_wise_result(Request $request)
    {
        if (auth('customer')->check()) {
            $paymentGatewayList = payment_gateways();
            $offlinePaymentMethods = OfflinePaymentMethod::where('status', 1)->get();
            $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
            $cashOnDeliveryStatus = getWebConfig(name: 'cash_on_delivery');

            $orderDetails = Order::with('orderDetails')->where('id', $request['order_id'])->whereHas('details', function ($query) {
                $query->where('customer_id', (auth('customer')->id()));
            })->first();

            if (!$orderDetails) {
                Toastr::warning(translate('invalid_order'));
                return redirect()->route('account-oder');
            }

            $isOrderOnlyDigital = self::getCheckIsOrderOnlyDigital($orderDetails);
            return view(VIEW_FILE_NAMES['track_order_wise_result'], compact('orderDetails', 'isOrderOnlyDigital', 'paymentGatewayList', 'cashOnDeliveryStatus', 'offlinePaymentMethods', 'offlinePaymentStatus'));
        }
        return back();
    }

    public function getCheckIsOrderOnlyDigital($order): bool
    {
        $isOrderOnlyDigital = true;
        if ($order->orderDetails) {
            foreach ($order->orderDetails as $detail) {
                $product = json_decode($detail->product_details, true);
                if (isset($product['product_type']) && $product['product_type'] == 'physical') {
                    $isOrderOnlyDigital = false;
                }
            }
        }
        return $isOrderOnlyDigital;
    }

    public function track_order_result(Request $request)
    {
        $isOrderOnlyDigital = false;
        $user = auth('customer')->user();
        $user_phone = $request['phone_number'] ?? '';
        $order_verification_status = getWebConfig(name: 'order_verification');
        $cashOnDeliveryStatus = getWebConfig(name: 'cash_on_delivery');
        $isPhysicalProduct = false;
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $offlinePaymentMethods = OfflinePaymentMethod::where('status', 1)->get();
        $paymentGatewayList = payment_gateways();
        $availablePaymentMethod = [];
        $walletStatus = getWebConfig(name: 'wallet_status');
        $digitalPayment = getWebConfig(name: 'digital_payment')['status'];
        $orderEditPaymentHistory = OrderEditHistory::where('order_id', $request['order_id'])->get();

        $orderExist = null;
        $order = Order::with('shippingAddress', 'billingAddress', 'details', 'latestEditHistory')
            ->where(['id' => $request['order_id'], 'order_type' => 'default_type'])
            ->first();

        if (!isset($user)) {
            $userInfo = User::where('phone', $request['phone_number'])->orWhere('phone', 'like', "%{$request['phone_number']}%")->first();

            if ($order && $order->is_guest) {

                $shippingAddress = (array)($order['shipping_address_data'] ?? []);
                $billingAddress = (array)($order['billing_address_data'] ?? []);
                if ($order && $order?->shippingAddress && $order?->shippingAddress->phone == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($shippingAddress['phone'] == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($order && $order?->billingAddress && $order?->billingAddress->phone == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($billingAddress['phone'] == $request['phone_number']) {
                    $orderExist = $order;
                }

            } elseif ($userInfo) {
                $orderExist = Order::with('latestEditHistory')->where('id', $request['order_id'])->whereHas('details', function ($query) use ($userInfo) {
                    $query->where('customer_id', $userInfo->id);
                })->first();
            } else {
                Toastr::error(translate('invalid_Order_Id_or_phone_Number'));
                return redirect()->route('track-order.index', ['order_id' => $request['order_id'], 'phone_number' => $request['phone_number']]);
            }
        } else {
            $order = Order::with('details', 'latestEditHistory')->where('id', $request['order_id'])->first();
            if ($order && $order->is_guest) {

                $shippingAddress = (array)($order['shipping_address_data'] ?? []);
                $billingAddress = (array)($order['billing_address_data'] ?? []);
                if ($order && $order?->shippingAddress && $order?->shippingAddress->phone == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($shippingAddress['phone'] == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($order && $order?->billingAddress && $order?->billingAddress->phone == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($billingAddress['phone'] == $request['phone_number']) {
                    $orderExist = $order;
                }

            } elseif ($user->phone == $request['phone_number']) {
                $orderExist = Order::with('details', 'latestEditHistory')->where('id', $request['order_id'])->whereHas('details', function ($query) {
                    $query->where('customer_id', auth('customer')->id());
                })->first();
            }

            if ($request['from_order_details'] == 1) {
                $orderExist = Order::with('details', 'latestEditHistory')->where('id', $request['order_id'])->whereHas('details', function ($query) {
                    $query->where('customer_id', auth('customer')->id());
                })->first();
            }
        }

        if (isset($orderExist)) {
            if ($orderExist['order_type'] == 'POS') {
                Toastr::error(translate('this_order_is_created_by_') . ($orderExist['seller_is'] == 'seller' ? 'vendor' : 'admin') . translate('_from POS') . ',' . translate('please_contact_with_') . ($orderDetails['seller_is'] == 'seller' ? 'vendor' : 'admin') . translate('_to_know_more_details') . '.');
                return redirect()->back();
            }
            if ($orderExist && $orderExist->orderDetails()->whereHas('product', fn($q) => $q->where('product_type', 'physical'))->exists()) {
                $isPhysicalProduct = true;
            }
            $isOrderOnlyDigital = self::getCheckIsOrderOnlyDigital($orderExist);
            $orderDetails = $orderExist;
            return view(VIEW_FILE_NAMES['track_order'], compact(
                'orderDetails',
                'user_phone',
                'order_verification_status',
                'orderEditPaymentHistory',
                'isOrderOnlyDigital',
                'paymentGatewayList',
                'cashOnDeliveryStatus',
                'isPhysicalProduct',
                'offlinePaymentStatus',
                'digitalPayment',
                'walletStatus',
                'offlinePaymentMethods',
                'availablePaymentMethod',
            ));
        }

        Toastr::error(translate('invalid_Order_Id_or_phone_Number'));
        return redirect()->route('track-order.index', ['order_id' => $request['order_id'], 'phone_number' => $request['phone_number']]);
    }

    public function track_last_order()
    {
        $order_id = Order::where('customer_id', auth('customer')->id())->latest()->first()->id;
        $orderDetails = Order::with(['deliveryMan', 'orderStatusHistory' => function ($query) {
            return $query->latest();
        }])->where(['id' => $order_id])->first();

        if ($orderDetails != null) {
            return view('web-views.order.tracking', compact('orderDetails'));
        } else {
            return redirect()->route('track-order.index')->with('Error', translate('invalid_Order_Id_or_phone_Number'));
        }

    }

    public function order_cancel($id)
    {
        // Ownership scope: a customer may only cancel their OWN order (prevents cancelling/restocking
        // arbitrary orders by guessing sequential ids).
        $order = Order::where(['id' => $id, 'customer_id' => auth('customer')->id()])->first();
        if (!$order) {
            Toastr::error(translate('order_not_found'));
            return back();
        }
        if ($order['payment_method'] == 'cash_on_delivery' && $order['order_status'] == 'pending') {
            OrderManager::getStockUpdateOnOrderStatusChange($order, 'canceled');
            $orderStatusHistoryData = $this->orderStatusHistoryService->getOrderHistoryData(orderId: $id, userId: auth('customer')->id(), userType: 'customer', status: 'canceled');
            $this->orderStatusHistoryRepo->add($orderStatusHistoryData);
            OrderManager::removeOldStatusHistory(orderId: $id, orderStatus: 'canceled');
            Order::where(['id' => $id])->update([
                'order_status' => 'canceled'
            ]);
            Toastr::success(translate('successfully_canceled'));
        } elseif ($order['payment_method'] == 'offline_payment') {
            Toastr::error(translate('The_order_status_cannot_be_updated_as_it_is_an_offline_payment'));
        } else {
            Toastr::error(translate('status_not_changable_now'));
        }
        return back();
    }

    public function refund_request(Request $request, $id): View|RedirectResponse
    {
        $orderDetails = OrderDetail::find($id);
        $user = auth('customer')->user();

        // Ownership: the refund-request form exposes another customer's order line otherwise.
        if (!$orderDetails || !Order::where('id', $orderDetails->order_id)->where('customer_id', $user->id)->exists()) {
            abort(404);
        }

        $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');
        if ($loyaltyPointStatus == 1) {
            $loyaltyPoint = CustomerManager::countLoyaltyPointForAmount($id);
            if ($user['loyalty_point'] < $loyaltyPoint) {
                Toastr::warning(translate('you_have_not_sufficient_loyalty_point_to_refund_this_order') . '!!');
                return back();
            }
        }

        return view('web-views.users-profile.refund-request', [
            'order_details' => $orderDetails,
        ]);
    }

    public function store_refund(Request $request): RedirectResponse
    {
        $request->validate([
            'order_details_id' => 'required',
            'amount' => 'required',
            'refund_reason' => 'required'

        ]);
        if ($request->file('images')) {
            foreach ($request->file('images') as $img) {
                $extension = $img->getClientOriginalExtension();
                if (in_array($extension, getDisallowedExtensionsListArray())) {
                    Toastr::error(translate('Invalid_file_format_given_') . $extension);
                    return redirect()->back();
                }
            }
        }
        $orderDetailsReward = $this->orderDetailsRewardsRepo->getFirstWhere(params: ['order_details_id' => $request['order_details_id'], 'reward_type' => 'loyalty_point']);
        $orderDetails = OrderDetail::find($request->order_details_id);
        $user = auth('customer')->user();

        // Ownership: a customer may only file a refund against a line of their OWN order. Without this
        // a customer could refund another customer's order line by passing its order_details_id.
        if (!$orderDetails || !Order::where('id', $orderDetails->order_id)->where('customer_id', $user->id)->exists()) {
            Toastr::error(translate('order_not_found'));
            return back();
        }

        if ($orderDetailsReward && $user->loyalty_point < $orderDetailsReward['reward_amount']) {
            Toastr::warning(translate('you_have_not_sufficient_loyalty_point_to_refund_this_order') . '!!');
            return back();
        }
        $refundRequest = new RefundRequest;
        $refundRequest->order_details_id = $request->order_details_id;
        $refundRequest->customer_id = auth('customer')->id();
        $refundRequest->status = 'pending';
        $refundRequest->amount = OrderManager::getRefundDetailsForSingleOrderDetails(orderDetailsId: $orderDetails['id'])['total_refundable_amount'];
        $refundRequest->product_id = $orderDetails->product_id;
        $refundRequest->order_id = $orderDetails->order_id;
        $refundRequest->refund_reason = $request->refund_reason;

        if ($request->file('images')) {
            $images = [];
            foreach ($request->file('images') as $img) {
                $images[] = [
                    'image_name' => ImageManager::upload('refund/', 'webp', $img),
                    'storage' => getWebConfig(name: 'storage_connection_type') ?? 'public',
                ];
            }
            $refundRequest->images = $images;
        }
        $refundRequest->save();

        $orderDetails->refund_request = 1;
        $orderDetails->save();

        $order = Order::find($orderDetails->order_id);
        event(new RefundEvent(status: 'refund_request', order: $order, refund: $refundRequest, orderDetails: $orderDetails));

        Toastr::success(translate('refund_requested_successful!!'));
        return redirect()->route('account-order-details', ['id' => $orderDetails->order_id]);
    }

    public function generate_invoice($id)
    {
        // Ownership scope: an invoice carries customer PII (name/address/phone/items) and must only be
        // downloadable by the order's owner. A logged-in customer owns orders by customer_id; a guest
        // owns the order placed under their session guest_id (customer_id == guest_id, is_guest == 1) —
        // the guest order-success modal links here, so guests must keep working.
        $order = Order::with('seller', 'latestEditHistory')->with('shipping')
            ->where('id', $id)
            ->where(function ($query) {
                auth('customer')->check()
                    ? $query->where('customer_id', auth('customer')->id())
                    : $query->where(['customer_id' => session('guest_id'), 'is_guest' => 1]);
            })
            ->first();
        if (!$order) {
            abort(404);
        }
        $invoiceSettings = getWebConfig(name: 'invoice_settings');
        $mpdf_view = \View::make(VIEW_FILE_NAMES['order_invoice'], compact('order', 'invoiceSettings'));
        $this->generatePdf(view: $mpdf_view, filePrefix: 'order_invoice_', filePostfix: $order['id'], pdfType: 'invoice', requestFrom: 'web');
    }

    public function refund_details($id)
    {
        $order_details = OrderDetail::find($id);
        // Ownership: the order line (price/qty/discount/tax) must belong to the requesting customer.
        if (!$order_details || !Order::where('id', $order_details->order_id)->where('customer_id', auth('customer')->id())->exists()) {
            abort(404);
        }
        $refund = RefundRequest::with(['product', 'order'])->where('customer_id', auth('customer')->id())
            ->where('order_details_id', $order_details->id)->first();
        $product = $this->product->find($order_details->product_id);
        $order = $this->order->find($order_details->order_id);

        if (request()->ajax()) {
            if ($product) {
                return response()->json([
                    'status' => 1,
                    'view' => view(VIEW_FILE_NAMES['refund_details'], compact('order_details', 'refund', 'product', 'order'))->render(),
                ]);
            }
            return response()->json(['status' => 0, 'message' => translate('product_not_found')]);
        }

        if ($product) {
            return view(VIEW_FILE_NAMES['refund_details'], compact('order_details', 'refund', 'product', 'order'));
        }

        Toastr::error(translate('product_not_found'));
        return redirect()->back();
    }

    public function submit_review(Request $request, $id): View|RedirectResponse
    {
        $order_details = OrderDetail::where(['id' => $id])->whereHas('order', function ($q) {
            $q->where(['customer_id' => auth('customer')->id(), 'payment_status' => 'paid']);
        })->first();

        if (!$order_details) {
            Toastr::error(translate('invalid_order!'));
            return redirect('/');
        }

        return view('web-views.users-profile.submit-review', compact('order_details'));
    }

    public function refer_earn(Request $request): View|RedirectResponse
    {
        $refEarningStatus = getWebConfig(name: 'ref_earning_status') ?? 0;
        if (!$refEarningStatus) {
            Toastr::error(translate('you_have_no_permission'));
            return redirect('/');
        }
        $customer_detail = User::where('id', auth('customer')->id())->first();
        if (empty($customer_detail['referral_code'])) {
            User::where('id', auth('customer')->id())->update([
                'referral_code' => Helpers::generate_referer_code(),
            ]);
            $customer_detail = User::where('id', auth('customer')->id())->first();
        }

        return view(VIEW_FILE_NAMES['refer_earn'], compact('customer_detail'));
    }

    public function user_coupons(Request $request): View
    {
        $coupons = Coupon::active()->with('seller')
            ->whereIn('customer_id', [auth('customer')->id(), '0'])
            ->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('expire_date', '>=', date('Y-m-d'))
            ->paginate(8);

        return view(VIEW_FILE_NAMES['user_coupons'], compact('coupons'));
    }

    public function restockRequestsView(Request $request): View
    {
        $restockProducts = $this->restockProductRepo->getListWhere(
            orderBy: ['updated_at' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['customer_id' => auth('customer')->id()],
            relations: ['product.clearanceSale' => function ($query) {
                return $query->active();
            }],
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT),
        );
        $productIdsArray = $restockProducts->pluck('product_id')->toArray();
        return view(VIEW_FILE_NAMES['user_restock_requests'], compact('restockProducts', 'productIdsArray'));
    }

    public function deleteRestockRequest(Request $request): RedirectResponse
    {
        $customerId = auth('customer')->id();
        if ($request['id']) {
            $this->restockProductCustomerRepo->delete(params: ['restock_product_id' => $request['id'], 'customer_id' => $customerId]);
        } else {
            $this->restockProductCustomerRepo->delete(params: ['customer_id' => $customerId]);
        }

        $restockProducts = $this->restockProductRepo->getListWhere(relations: ['restockProductCustomers'], dataLimit: 'all');
        $restockProducts->map(function ($restockProduct) {
            if ($restockProduct->restockProductCustomers->count() === 0) {
                $this->restockProductRepo->delete(params: ['id' => $restockProduct['id']]);
            }
        });

        Toastr::success(translate('product_restock_request_removed_successfully'));
        return redirect()->route('user-restock-requests');
    }
}
