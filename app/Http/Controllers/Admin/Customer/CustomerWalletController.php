<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Contracts\Repositories\AddFundBonusCategoriesRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\WalletTransactionRepositoryInterface;
use App\Enums\WebConfigKey;
use App\Events\AddFundToWalletEvent;
use App\Exports\CustomerTransactionsExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\AddFundBonusCategoriesDeleteRequest;
use App\Http\Requests\Admin\AddFundBonusCategoriesUpdateRequest;
use App\Http\Requests\Admin\AddFundRequest;
use App\Http\Requests\Admin\BonusSetupRequest;
use App\Services\CustomerWalletService;
use App\Traits\PaginatorTrait;
use Carbon\Carbon;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerWalletController extends BaseController
{
    use PaginatorTrait;

    public function __construct(
        private readonly CustomerRepositoryInterface               $customerRepo,
        private readonly BusinessSettingRepositoryInterface        $businessSettingRepo,
        private readonly WalletTransactionRepositoryInterface      $walletTransactionRepo,
        private readonly AddFundBonusCategoriesRepositoryInterface $addFundBonusCategoriesRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $from = null;
        $to = null;

        // `?date[]=x` hands the request an ARRAY, which explode() rejects outright, and a half-typed
        // range leaves the second end undefined at Carbon — either one takes the whole report down.
        // A range that cannot be read is simply not applied.
        $range = $request->query('date');
        $dates = is_string($range) ? explode(' - ', $range) : [];
        $isReadableRange = count($dates) === 2
            && Carbon::canBeCreatedFromFormat(date: $dates[0], format: 'd M Y')
            && Carbon::canBeCreatedFromFormat(date: $dates[1], format: 'd M Y');

        if ($isReadableRange) {
            $from = Carbon::createFromFormat('d M Y', $dates[0])->startOfDay();
            $to = Carbon::createFromFormat('d M Y', $dates[1])->endOfDay();
        }
        $customerStatus = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'wallet_status'])['value'];
        $filters = [
            'to' => $to,
            'from' => $from,
            'transaction_type' => $request['transaction_type'] ?? 'all',
            'customer_id' => $request['customer_id'],
        ];

        $data = $this->walletTransactionRepo->getListWhereSelect(filters: $filters, dataLimit: 'all');
        $transactions = $this->walletTransactionRepo->getListWhere(searchValue: requestString('searchValue') ?: null, filters: $filters, relations: ['user'], dataLimit: getWebConfig(name: 'pagination_limit'));
        $customer = "all";
        if (isset($request['customer_id']) && $request['customer_id'] != 'all' && !is_null($request['customer_id']) && $request->has('customer_id')) {
            $customer = $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]);
        }
        $customers = $this->customerRepo->getCustomerNameList(request: $request, dataLimit: 'all')
            ->filter(function ($customer) {
                return $customer['id'] != 0;
            })
            ->values()
            ->toArray();

        $sidebarCustomers = $customers;
        array_unshift($customers, ['id' => 'all', 'text' => translate('All_Customer')]);

        // The range the report was actually drawn for, so the picker echoes what was applied
        // rather than re-reading the raw query — which is where the array reached htmlspecialchars().
        $dateRange = $isReadableRange ? $range : '';

        return view('admin-views.customer.wallet.report', compact('data', 'transactions', 'customerStatus', 'customer','customers','sidebarCustomers','dateRange'));
    }

    public function addFund(AddFundRequest $request, CustomerWalletService $customerWalletService): JsonResponse
    {
        $walletTransaction = $this->walletTransactionRepo->addWalletTransaction(
            user_id: $request['customer_id'],
            amount: $request['amount'],
            transactionType: 'add_fund_by_admin',
            reference: ($request['reference'] ?? 'add_fund_by_admin'));

        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]);
        $customerWalletService->sendPushNotificationMessage(request: $request, customer: $customer);

        if ($walletTransaction) {
            $data = [
                'walletTransaction' => $walletTransaction,
                'userName' => $customer['f_name'],
                'userType' => 'customer',
                'templateName' => 'add-fund-to-wallet',
                'subject' => translate('add_fund_to_wallet'),
                'title' => translate('add_fund_to_wallet'),
            ];
            event(new AddFundToWalletEvent(email: $customer['email'], data: $data));
            return response()->json(['message' => translate('transaction_successful')], 200);
        }

        return response()->json(['errors' => [
            'message' => translate('failed_to_create_transaction')
        ]], 200);
    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $filters = [
            'to' => $request['to'],
            'from' => $request['from'],
            'transaction_type' => $request['transaction_type'],
            'customer_id' => $request['customer_id'],
        ];
        $summary = $this->walletTransactionRepo->getListWhereSelect(filters: $filters, dataLimit: 'all');
        $transactions = $this->walletTransactionRepo->getListWhere(filters: $filters, dataLimit: 'all');
        $data = [
            'type' => 'wallet',
            'transactions' => $transactions,
            'credit' => $summary[0]->total_credit,
            'debit' => $summary[0]->total_debit,
            'balance' => $summary[0]->total_credit - $summary[0]->total_debit,
            'transaction_type' => $request['transaction_type'],
            'to' => $request['to'],
            'from' => $request['from'],
            'customer' => $request['customer_id'] ? $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]) : "all_customers",
        ];
        return Excel::download(new CustomerTransactionsExport($data), 'Wallet-Transactions-Report.xlsx');
    }

    public function getBonusSetupView(Request $request): View
    {
        $this->addFundBonusCategoriesRepo->updatePastDateStatus();
        $data = $this->addFundBonusCategoriesRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('search') ?: null,
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT),
        );
        return view('admin-views.customer.wallet.wallet-bonus-setup', compact('data'));
    }

    public function addBonusSetup(BonusSetupRequest $request): JsonResponse
    {
        $data = [
            'title' => $request['title'],
            'description' => $request['description'],
            'bonus_type' => $request['bonus_type'],
            'bonus_amount' => $request['bonus_type'] == 'fixed' ? currencyConverter($request['bonus_amount']) : $request['bonus_amount'],
            'min_add_money_amount' => currencyConverter($request['min_add_money_amount']),
            'max_bonus_amount' => currencyConverter($request['max_bonus_amount']),
            'start_date_time' => $request['start_date_time'],
            'end_date_time' => $request['end_date_time'] ?? now()->addDay(7),
            'created_at' => now(),
        ];
        $this->addFundBonusCategoriesRepo->add(data: $data);
        return response()->json(['message' => translate('wallet_Bonus_added_Successfully')]);
    }

    public function updateStatus(AddFundBonusCategoriesUpdateRequest $request): JsonResponse|RedirectResponse
    {
        $this->addFundBonusCategoriesRepo->update(id: $request['id'], data: ['is_active' => $request->get('status', 0)]);
        return response()->json(['message' => translate('Wallet_bonus_status_updated_successfully')]);
    }

    public function deleteBonus(AddFundBonusCategoriesDeleteRequest $request): JsonResponse|RedirectResponse
    {
        $this->addFundBonusCategoriesRepo->delete(params: ['id' => $request['id']]);
        if ($request->ajax()) {
            return response()->json([
                'status' => 1,
                'message' => translate('bonus_removed_Successfully'),
            ]);
        }
        ToastMagic::success(translate('bonus_removed_Successfully'));
        return back();
    }

    public function getUpdateView(Request $request): View
    {
        $data = $this->addFundBonusCategoriesRepo->getFirstWhere(params: ['id' => $request['id']]);
        return view('admin-views.customer.wallet.wallet-bonus-edit', compact('data'));
    }

    public function update(BonusSetupRequest $request): JsonResponse
    {
        $data = [
            'title' => $request['title'],
            'description' => $request['description'],
            'bonus_type' => $request['bonus_type'],
            'bonus_amount' => $request['bonus_type'] == 'fixed' ? currencyConverter($request['bonus_amount']) : $request['bonus_amount'],
            'min_add_money_amount' => currencyConverter($request['min_add_money_amount']),
            'max_bonus_amount' => currencyConverter($request['max_bonus_amount']),
            'start_date_time' => $request['start_date_time'],
            'end_date_time' => $request['end_date_time'],
        ];
        $this->addFundBonusCategoriesRepo->update(id: $request['id'], data: $data);
        return response()->json(['message' => translate('wallet_Bonus_updated_Successfully')]);
    }

}
