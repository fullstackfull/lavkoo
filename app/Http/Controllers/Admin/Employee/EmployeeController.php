<?php

namespace App\Http\Controllers\Admin\Employee;

use App\Contracts\Repositories\AdminRepositoryInterface;
use App\Contracts\Repositories\AdminRoleRepositoryInterface;
use App\Enums\WebConfigKey;
use App\Exports\EmployeeListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\AdminAddRequest;
use App\Http\Requests\Admin\AdminUpdateRequest;
use App\Services\AdminService;
use App\Services\Staff\SensitiveAdminAction;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Traits\PaginatorTrait;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends BaseController
{
    use PaginatorTrait;

    public function __construct(
        private readonly AdminRepositoryInterface $adminRepo,
        private readonly AdminRoleRepositoryInterface $adminRoleRepo,
        private readonly SensitiveAdminAction $sensitive,
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
        $employee_roles = $this->adminRoleRepo->getEmployeeRoleList(dataLimit: 'all');
        $employees = $this->adminRepo->getEmployeeListWhere(
            orderBy:['id'=>'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters:['admin_role_id' => $request['admin_role_id'] ?? 'all'] ,
            relations: ['role'],
            dataLimit:getWebConfig(name: WebConfigKey::PAGINATION_LIMIT)
        );
        return view('admin-views.employee.list', compact('employees','employee_roles'));
    }

    public function getAddView(): View
    {
        $employee_roles = $this->adminRoleRepo->getEmployeeRoleList(dataLimit: 'all');
        return view('admin-views.employee.add-new', compact('employee_roles'));
    }

    public function add(AdminAddRequest $request, AdminService $adminService): RedirectResponse
    {
        $roleId = $adminService->assignableRoleId(roleId: $request['role_id']);
        if ($roleId === null) {
            return $this->refuse(request: $request);
        }

        $data = [
            'name' => $request['name'],
            'phone' => $request['phone'],
            'email' => $request['email'],
            'admin_role_id' => $roleId,
            'identify_type' => $request['identify_type'],
            'identify_number' => $request['identify_number'],
            'identify_image' => $adminService->getIdentityImages(request: $request),
            'password' => bcrypt($request['password']),
            'status'=>1,
            'image' => $adminService->getProceedImage(request:$request),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->sensitive->perform(fn () => $this->adminRepo->add(data: $data));
        ToastMagic::success(translate('employee_added_successfully'));
        return redirect()->route('admin.employee.list');
    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $employees = $this->adminRepo->getEmployeeListWhere(
            searchValue: requestString('searchValue') ?: null,
            filters: ['admin_role_id' => $request['role']],
            relations: ['role'],
            dataLimit: 'all');
        $active = $employees->where('status',1)->count();
        $inactive = $employees->where('status',0)->count();

        $filter = 'all';
        if($request->has('role') &&  $request['role'] != 'all') {
            $filter = $this->adminRoleRepo->getFirstWhere(params:['id'=>$request['role']])['name'];
        }

        $data = [
            'employees' => $employees,
            'search' => $request['searchValue'],
            'active' => $active,
            'inactive' => $inactive,
            'filter' => $filter
        ];

        return Excel::download(new EmployeeListExport($data), 'Employees.xlsx');
    }

    public function getView(Request $request, AdminService $adminService): View|RedirectResponse|JsonResponse
    {
        $employee = $this->adminRepo->getFirstWhere(params:['id' => $request['id']], relations:['role']);
        if ($this->isOutOfReach(employee: $employee, adminService: $adminService)) {
            return $this->refuse(request: $request);
        }
        return view('admin-views.employee.view', compact('employee'));
    }

    public function getUpdateView(Request $request, $id, AdminService $adminService): View|RedirectResponse|JsonResponse
    {
        $employee = $this->adminRepo->getFirstWhere(params:['id' => $id]);
        if ($this->isOutOfReach(employee: $employee, adminService: $adminService)) {
            return $this->refuse(request: $request);
        }
        $adminRoles = $this->adminRoleRepo->getEmployeeRoleList(dataLimit: 'all');
        return view('admin-views.employee.edit', compact('adminRoles', 'employee'));
    }

    public function update(AdminUpdateRequest $request, AdminService $adminService): RedirectResponse
    {
        $roleId = $adminService->assignableRoleId(roleId: $request['role_id']);
        $employee = $this->adminRepo->getFirstWhere(params:['id' => $request['id']]);
        if ($roleId === null || $this->isOutOfReach(employee: $employee, adminService: $adminService)) {
            return $this->refuse(request: $request);
        }
        $identity_image = [];
        if ($request->file('identity_image')) {
            $identity_image = $adminService->getIdentityImages(request: $request, oldImages: $employee);
        }

        $data = [
            'name' => $request['name'],
            'phone' => $request['phone'],
            'email' => $request['email'],
            'admin_role_id' => $roleId,
            'password' => $request['password'] ? bcrypt($request['password']) : $employee['password'],
            'image' => $request->file('image') ? $adminService->getProceedImage(request:$request, oldImage:$employee['image']) : $employee['image'],
            'identify_image' => $request->file('identity_image') ? $identity_image : $employee['identify_image'],
            'identify_type' => $request['identify_type'],
            'identify_number' => $request['identify_number'],
            'updated_at' => now(),
        ];

        $this->sensitive->perform(fn () => $this->adminRepo->update(id: $request['id'], data: $data));
        ToastMagic::success(translate('employee_updated_successfully'));
        return redirect()->route('admin.employee.list');
    }

    public function updateStatus(Request $request, AdminService $adminService): RedirectResponse|JsonResponse
    {
        if ($this->isOutOfReach(employee: $this->adminRepo->getFirstWhere(params: ['id' => $request['id']]), adminService: $adminService)) {
            return $this->refuse(request: $request);
        }
        $this->sensitive->perform(fn () => $this->adminRepo->update(id: $request['id'], data: ['status' => $request->get('status', 0)]));
        if($request->ajax()) {
            return response()->json([
                'status' => 'success',
                'message' => translate('Employee_Status_Updated_Successfully'),
            ]);
        }
        ToastMagic::success(translate('Employee_Status_Updated_Successfully'));
        return back();
    }

    private function isOutOfReach(?object $employee, AdminService $adminService): bool
    {
        return $adminService->isOutOfReachOfStaffManagement(target: $employee, asker: auth('admin')->user());
    }

    private function refuse(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->ajax()) {
            return response()->json(data: ['status' => 'error', 'message' => translate('access_denied')], status: 403);
        }
        ToastMagic::warning(translate('access_denied'));

        return redirect()->route('admin.employee.list');
    }
}
