<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliverySyriaSettingController extends Controller
{
    public function show()
    {
        $setting = DB::table('business_settings')
            ->where('type', 'delivery_syria_webhook_token')
            ->first();

        return view(
            'admin-views.integrations.delivery-syria-settings',
            compact('setting')
        );
    }

    public function update(Request $request)
    {
        $request->validate([
            'delivery_syria_webhook_token' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        DB::table('business_settings')
            ->where('type', 'delivery_syria_webhook_token')
            ->update([
                'value' => $request->delivery_syria_webhook_token,
                'updated_at' => now(),
            ]);

        return redirect()
            ->back()
            ->with('success', 'Delivery Syria webhook token updated successfully.');
    }
}