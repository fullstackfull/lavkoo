<?php

namespace App\Http\Controllers;

use App\Http\Requests\Request;
use App\Models\Seller;
use App\Utils\Helpers;
use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class FirebaseController extends Controller
{
    protected mixed $messaging;

    public function __construct()
    {
        $this->messaging = app('firebase.messaging');
    }

    public function subscribeToTopic(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'topic' => 'required|string',
        ]);

        $token = $request->input('token');
        $topic = $request->input('topic');

        $customer = Auth::guard('customer')->user();
        if ($customer) {
            User::where('id', $customer->id)->update(['cm_firebase_token_web' => $token]);
        }

        try {
            if($this->messaging){
                $this->messaging->subscribeToTopic($topic, $token);
                return response()->json(['message' => 'Successfully subscribed to topic'], 200);
            }
            return response()->json(['message' => 'Unauthorized'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function saveSellerWebToken(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $seller = Auth::guard('seller')->user();
        if ($seller) {
            Seller::where('id', $seller->id)->update(['cm_firebase_token_web' => $request->input('token')]);
        }

        return response()->json(['message' => 'Token saved'], 200);
    }
}
