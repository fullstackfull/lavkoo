<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\V2SidebarPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Vendor twin of Admin\V2SidebarPinController — same JSON contract,
 * but scoped to the seller user type.
 */
class V2SidebarPinController extends Controller
{
    public function index(): JsonResponse
    {
        $seller = auth('seller')->user();
        if (!$seller) return response()->json(['pins' => []]);
        return response()->json([
            'pins' => V2SidebarPin::pinsFor(V2SidebarPin::TYPE_SELLER, (int)$seller->id),
        ]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $seller = auth('seller')->user();
        if (!$seller) return response()->json(['error' => 'unauthenticated'], 401);

        $validator = Validator::make($request->all(), [
            'id'     => 'required|string|max:190',
            'action' => 'required|in:add,remove',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'validation', 'messages' => $validator->errors()], 422);
        }

        $pinId  = (string)$request->input('id');
        $action = (string)$request->input('action');
        $type   = V2SidebarPin::TYPE_SELLER;
        $userId = (int)$seller->id;

        if ($action === 'add') {
            V2SidebarPin::firstOrCreate([
                'user_type' => $type,
                'user_id'   => $userId,
                'pin_id'    => $pinId,
            ]);
        } else {
            V2SidebarPin::where('user_type', $type)
                ->where('user_id', $userId)
                ->where('pin_id', $pinId)
                ->delete();
        }

        V2SidebarPin::forgetCacheFor($type, $userId);

        return response()->json([
            'pins' => V2SidebarPin::pinsFor($type, $userId),
        ]);
    }

    public function replace(Request $request): JsonResponse
    {
        $seller = auth('seller')->user();
        if (!$seller) return response()->json(['error' => 'unauthenticated'], 401);

        $pins = (array)$request->input('pins', []);
        $pins = array_values(array_unique(array_filter(array_map(static function ($p) {
            return is_string($p) ? trim($p) : '';
        }, $pins))));
        $pins = array_slice(array_filter($pins, static fn($p) => mb_strlen($p) <= 190), 0, 500);

        $type   = V2SidebarPin::TYPE_SELLER;
        $userId = (int)$seller->id;

        \DB::transaction(function () use ($type, $userId, $pins) {
            V2SidebarPin::where('user_type', $type)->where('user_id', $userId)->delete();
            foreach ($pins as $pinId) {
                V2SidebarPin::create([
                    'user_type' => $type,
                    'user_id'   => $userId,
                    'pin_id'    => $pinId,
                ]);
            }
        });

        V2SidebarPin::forgetCacheFor($type, $userId);

        return response()->json([
            'pins' => V2SidebarPin::pinsFor($type, $userId),
        ]);
    }
}
