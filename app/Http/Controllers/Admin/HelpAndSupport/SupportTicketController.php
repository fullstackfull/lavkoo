<?php

namespace App\Http\Controllers\Admin\HelpAndSupport;

use App\Contracts\Repositories\SupportTicketConvRepositoryInterface;
use App\Contracts\Repositories\SupportTicketRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\SupportTicketRequest;
use App\Repositories\SupportTicketRepository;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Devrabiul\ToastMagic\Facades\ToastMagic;

class SupportTicketController extends BaseController
{
    /**
     * @param SupportTicketRepository $supportTicketRepo
     */
    public function __construct(
        private readonly SupportTicketRepositoryInterface $supportTicketRepo,
        private readonly SupportTicketConvRepositoryInterface $supportTicketConvRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return \Illuminate\Contracts\View\View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $tickets = $this->supportTicketRepo->getListWhere(
            orderBy: ['updated_at' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['priority' => $request['priority'], 'status' => $request['status']],
            relations: ['customer'],
            dataLimit: getWebConfig('pagination_limit')
        );
        return view('admin-views.support-ticket.view', compact('tickets'));
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $ticket = $this->supportTicketRepo->getFirstWhere(params:['id' => $request['id']]);
        $status = $ticket['status'] == 'open' ? 'close' : 'open';
        $this->supportTicketRepo->update(id: $ticket['id'], data: ['status' => $status]);

        return response()->json(['message' => translate('Support_ticket_status_updated')]);
    }

    public function getView($id): View
    {
        $supportTicket = $this->supportTicketRepo->getListWhere(filters: ['id'=>$id], relations: ['conversations'], dataLimit: 'all');
        // The blade keeps using $ticket after its @foreach; an unknown id used to leave it
        // undefined and 500 — a missing ticket is a 404, not a crash.
        abort_if(boolean: $supportTicket->isEmpty(), code: 404);
        return view('admin-views.support-ticket.singleView', compact('supportTicket'));
    }

    public function reply(SupportTicketRequest $request, SupportTicketService $supportTicketService): RedirectResponse
    {
        if ($request['media'] == null && $request['replay'] == null) {
            ToastMagic::warning(translate('type_something').'!');
            return back();
        }
        if ($request['replay'] && mb_strlen($request['replay']) > 189) {
            ToastMagic::warning(translate('you_cannot_send_more_than_189_characters_text_!'));
            return back();
        }
        $dataArray = $supportTicketService->getAddData(request: $request);
        $this->supportTicketConvRepo->add(data: $dataArray);
        $this->supportTicketRepo->update(id: $request['id'], data: ['status' => 'open']);
        return back();
    }

}
