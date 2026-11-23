<?php

namespace App\Services;

use App\Contracts\AdminServiceInterface;
use App\Traits\FileManagerTrait;

class AdminService implements AdminServiceInterface
{
    use FileManagerTrait;

    public const MASTER_ADMIN_ID = 1;

    public const MASTER_ROLE_ID = 1;

    public function isLoginSuccessful(string $email, string $password, string|null|bool $rememberToken): bool
    {
        if (auth('admin')->attempt(['email' => $email, 'password' => $password], $rememberToken)) {
            return true;
        }
        return false;
    }

    public function logout(): void
    {
        auth('admin')->logout();
        session()->invalidate();
    }

    /** The first admin, and any account holding the master role. */
    public function isMasterAdmin(?object $admin): bool
    {
        return $this->isFirstAdmin(admin: $admin) || ($admin !== null && (int) $admin['admin_role_id'] === self::MASTER_ROLE_ID);
    }

    public function isFirstAdmin(?object $admin): bool
    {
        return $admin !== null && (int) $admin['id'] === self::MASTER_ADMIN_ID;
    }

    /**
     * Whether staff management must leave this account alone for whoever asks. The first admin is changed in its own profile and
     * by nobody else. Another account that holds the master role is managed by the first admin alone, so that such an account can
     * always be taken down again and never by its equals or by an employee.
     *
     * The employee list leaves only the first admin out; an account holding the master role under another id is listed like any
     * employee, and the requests its controls send are judged here.
     */
    public function isOutOfReachOfStaffManagement(?object $target, ?object $asker): bool
    {
        return $this->isFirstAdmin(admin: $target) || ($this->isMasterAdmin(admin: $target) && !$this->isFirstAdmin(admin: $asker));
    }

    /**
     * The role an employee form asks for, when it is one staff management may hand out: a whole number that is not the master
     * role. Anything else is refused rather than stored, because the database rounds `1abc`, `0.6` or `1.4` to the master role
     * while a loose comparison with 1 lets each of them pass.
     */
    public function assignableRoleId(mixed $roleId): ?int
    {
        $id = is_scalar($roleId) ? filter_var($roleId, FILTER_VALIDATE_INT) : false;

        return $id === false || $id === self::MASTER_ROLE_ID ? null : $id;
    }

    public function getIdentityImages(object $request, ?object $oldImages = null): bool|string
    {
        if (!empty($oldImages['identify_image'])) {
            foreach (json_decode($oldImages['identify_image'], true) as $image) {
                $imageName = is_string($image) ? $image : $image['image_name'];
                $this->delete('admin/' . $imageName);
            }
        }

        $identity_images = [];
        if (!empty($request->file('identity_image'))) {
            foreach ($request['identity_image'] as $img) {
                $identity_images[] = [
                    'image_name'=>$this->upload('admin/', 'webp', $img),
                    'storage' => getWebConfig(name: 'storage_connection_type') ?? 'public',
                ];
            }
            $identity_images = json_encode($identity_images);
        } else {
            $identity_images = json_encode([]);
        }

        return $identity_images;
    }

    public function getProceedImage(object $request, ?string $oldImage = null): bool|string
    {
        if ($oldImage) {
            $image =  $this->update('admin/', $oldImage,  'webp', $request['image']);
        }else {
            $image =  $this->upload('admin/', 'webp', $request['image']);
        }
        return $image;
    }

    /**
     * @return array[f_name: mixed, l_name: mixed, phone: mixed, image: mixed|string]
     */
    public function getAdminDataForUpdate(object $request, object $admin):array
    {
        $image = $request['image'] ? $this->update(dir: 'admin/', oldImage: $admin['image'], format: 'webp', image: $request->file('image')) : $admin['image'];
        return [
            'name' => $request['name'],
            'email' => $request['email'],
            'phone' => $request['phone'],
            'image' => $image,
        ];
    }

    /**
     * @return array[password: string]
     */
    public function getAdminPasswordData(object $request):array
    {
        return [
            'password' => bcrypt($request['password']),
        ];
    }

}
