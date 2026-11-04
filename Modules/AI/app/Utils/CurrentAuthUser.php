<?php

namespace Modules\AI\app\Utils;

use Illuminate\Support\Facades\Auth;

class CurrentAuthUser
{
    public static function id(): ?int
    {
        if (request()->has('seller') && request()->seller) {
            return request()->seller->id;
        }

        if (request()->user()) {
            return request()->user()->id;
        }

        if (Auth::guard('seller')->check()) {
            return Auth::guard('seller')->id();
        }

        if (Auth::guard('customer')->check()) {
            return Auth::guard('customer')->id();
        }

        if (Auth::guard('admin')->check()) {
            return Auth::guard('admin')->id();
        }
        return null;
    }

    public static function model(): ?object
    {
        if (request()->has('seller') && request()->seller) {
            return request()->seller;
        }

        if (request()->user()) {
            return request()->user();
        }

        if (Auth::guard('seller')->check()) {
            return Auth::guard('seller')->user();
        }

        if (Auth::guard('customer')->check()) {
            return Auth::guard('customer')->user();
        }

        if (Auth::guard('admin')->check()) {
            return Auth::guard('admin')->user();
        }

        return null;
    }

    public static function isAdmin(): bool
    {
        return Auth::guard('admin')->check();
    }

    public static function vendor(): object
    {
        if (request()->has('seller') && request()->seller) {
            return request()->seller;
        }
        if (Auth::guard('seller')->check()) {
            return Auth::guard('seller')->user();
        }
        return (object)[];
    }

    public static function isCustomer(): bool
    {
        return Auth::guard('customer')->check() || (!request()->has('seller') && (bool) request()->user());
    }

}
