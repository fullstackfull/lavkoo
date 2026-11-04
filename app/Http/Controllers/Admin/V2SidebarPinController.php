<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\V2SidebarPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Server-side persistence for the admin v2 sidebar pin shortcuts.
 * The pin list follows the admin user across browsers / devices /
 * sessions instead of living only in localStorage. Permission filtering
 * is implicit: pins for menu items the admin can no longer see won't
 * find a matching DOM target client-side and will silently disappear
 * from the rendered pin section.
 */
class V2SidebarPinController extends Controller
{
    /**
     * GET /admin/v2/sidebar-pins
     * Return the current admin's pin list as a JSON array of strings.
     */
    public function index(): JsonResponse
    {
        $admin = auth('admin')->user();
        if (!$admin) return response()->json(['pins' => []]);
        return response()->json([
            'pins' => V2SidebarPin::pinsFor(V2SidebarPin::TYPE_ADMIN, (int)$admin->id),
        ]);
    }

    /**
     * POST /admin/v2/sidebar-pins/toggle
     * Body: { id: <pin id>, action: 'add' | 'remove' }
     * Returns the updated full list.
     */
    public function toggle(Request $request): JsonResponse
    {
        $admin = auth('admin')->user();
        if (!$admin) return response()->json(['error' => 'unauthenticated'], 401);

        $validator = Validator::make($request->all(), [
            'id'     => 'required|string|max:190',
            'action' => 'required|in:add,remove',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'validation', 'messages' => $validator->errors()], 422);
        }

        $pinId  = (string)$request->input('id');
        $action = (string)$request->input('action');
        $type   = V2SidebarPin::TYPE_ADMIN;
        $userId = (int)$admin->id;

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

        // Invalidate the cached pin list for this user so the next
        // page render reflects the change immediately.
        V2SidebarPin::forgetCacheFor($type, $userId);

        return response()->json([
            'pins' => V2SidebarPin::pinsFor($type, $userId),
        ]);
    }

    /**
     * POST /admin/v2/sidebar-pins/replace
     * Body: { pins: ["...", "..."] }
     * Atomically replaces the whole pin list. Used by JS when the
     * client mutates multiple ids in one toggle (e.g. parent ↔ children
     * sync) to keep the round-trip count low.
     */
    public function replace(Request $request): JsonResponse
    {
        $admin = auth('admin')->user();
        if (!$admin) return response()->json(['error' => 'unauthenticated'], 401);

        $pins = (array)$request->input('pins', []);
        // Sanitise: keep only non-empty strings, dedupe, cap length.
        $pins = array_values(array_unique(array_filter(array_map(static function ($p) {
            return is_string($p) ? trim($p) : '';
        }, $pins))));
        $pins = array_slice(array_filter($pins, static fn($p) => mb_strlen($p) <= 190), 0, 500);

        $type   = V2SidebarPin::TYPE_ADMIN;
        $userId = (int)$admin->id;

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

        // Invalidate the cached pin list so subsequent page renders
        // reflect the new set immediately.
        V2SidebarPin::forgetCacheFor($type, $userId);

        return response()->json([
            'pins' => V2SidebarPin::pinsFor($type, $userId),
        ]);
    }
}
