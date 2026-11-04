<?php

namespace App\Http\Controllers\Admin\Employee;

use App\Contracts\Repositories\AdminRepositoryInterface;
use App\Contracts\Repositories\AdminRoleRepositoryInterface;
use App\Enums\ExportFileNames\Admin\Employee;
use App\Enums\GlobalConstant;
use App\Exports\EmployeeRoleListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\CustomRoleRequest;
use App\Traits\PaginatorTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Maatwebsite\Excel\Facades\Excel;

class CustomRoleController extends BaseController
{
    use PaginatorTrait;

    public function __construct(
        private readonly AdminRepositoryInterface     $adminRepo,
        private readonly AdminRoleRepositoryInterface $adminRoleRepo,
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
        $employeeRolePermission = GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION;
        $roles = $this->adminRoleRepo->getEmployeeRoleList(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['admin_role_id' => $request['role']],
            dataLimit: 'all'
        );
        return view('admin-views.custom-role.create', compact('roles', 'employeeRolePermission'));
    }

    public function add(CustomRoleRequest $request): RedirectResponse
    {
        $data = [
            'name' => $request['name'],
            'module_access' => json_encode($request['modules']),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $this->adminRoleRepo->add(data: $data);
        ToastMagic::success(translate('role_added_successfully'));
        return back();
    }

    public function getUpdateView($id): View
    {
        $employeeRolePermission = GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION;
        $role = $this->adminRoleRepo->getFirstWhere(params: ['id' => $id]);
        return view('admin-views.custom-role.edit', compact('role', 'employeeRolePermission'));
    }

    public function update(CustomRoleRequest $request): RedirectResponse
    {
        $data = [
            'name' => $request['name'],
            'module_access' => json_encode($request['modules']),
        ];
        $this->adminRoleRepo->update(id: $request['id'], data: $data);
        ToastMagic::success(translate('role_updated_successfully'));
        return back();
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $this->adminRoleRepo->update(id: $request['id'], data: ['status' => $request->get('status', 0)]);
        return response()->json([
            'success' => 1,
            'message' => translate('status_updated_successfully'),
        ], 200);

    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $roles = $this->adminRoleRepo->getEmployeeRoleList(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['admin_role_id' => $request['role']],
            dataLimit: 'all');

        return Excel::download(new EmployeeRoleListExport([
            'roles' => $roles,
            'searchValue' => $request['searchValue'],
            'active' => count($roles->where('status', 1)),
            'inActive' => count($roles->where('status', 0)),
        ]), Employee::EMPLOYEE_ROLE_LIST);
    }

    public function delete(Request $request): JsonResponse
    {
        $this->adminRoleRepo->delete(params: ['id' => $request['id']]);
        return response()->json([
            'success' => 1,
            'message' => translate('role_deleted_successfully')
        ], 200);
    }

}
