<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManualWhatsAppMessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManualMessageController extends Controller
{
    public function index(): View
    {
        return view('admin-views.integrations.manual-message');
    }

    public function check(
        Request $request,
        ManualWhatsAppMessageService $service
    ): RedirectResponse {
        $validated = $request->validate([
            'phone' => [
                'required',
                'string',
                'max:30',
            ],
        ]);

        /*
         * DEBUG مؤقتًا
         */
        $result = $service->debugEligibilitySuccess(
            phone: $validated['phone']
        );

        /*
         * لاحقًا بدل السطر فوق فقط:
         *
         * $result = $service->checkEligibility(
         *     $validated['phone']
         * );
         */

        return redirect()
            ->route('admin.integrations.manual-message.index')
            ->withInput()
            ->with('eligibility', $result);
    }

    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => [
                'required',
                'string',
                'max:30',
            ],

            'conversation_id' => [
                'required',
                'integer',
            ],

            'message' => [
                'required',
                'string',
                'max:4000',
            ],
        ]);

        /*
         * DEBUG:
         * لا نرسل إلى WhatsApp الحقيقي الآن.
         */
        return redirect()
            ->route('admin.integrations.manual-message.index')
            ->with(
                'success',
                'DEBUG: Message accepted successfully for '
                . $validated['phone']
                . '.'
            );
    }
}