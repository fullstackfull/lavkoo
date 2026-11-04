<?php

namespace App\Http\Controllers\Admin\Product;

use App\Contracts\Repositories\AttributeRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\AttributeRequest;
use App\Http\Resources\AttributeResource;
use App\Traits\PaginatorTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AttributeController extends BaseController
{
    use PaginatorTrait;

    public function __construct(
        private readonly AttributeRepositoryInterface   $attributeRepo,
        private readonly TranslationRepositoryInterface $translationRepo,
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
        $attributes = $this->attributeRepo->getListWhere(searchValue: requestString('searchValue') ?: null, dataLimit: getWebConfig(name: 'pagination_limit'));
        $language = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $language[0];
        return view('admin-views.attribute.view', compact('attributes', 'language', 'defaultLanguage'));
    }

    public function getList(): JsonResponse
    {
        $attributes = $this->attributeRepo->getList(dataLimit: 'all');
        return response()->json(AttributeResource::collection($attributes));
    }

    public function getUpdateView(string|int $id): View
    {
        $attribute = $this->attributeRepo->getFirstWhere(params: ['id' => $id], relations: ['translations']);
        $language = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $language[0];
        return view('admin-views.attribute.edit', compact('attribute', 'language', 'defaultLanguage'));
    }

    public function getTranslationData(string|int $id):JsonResponse
    {
        $attribute = $this->attributeRepo->getFirstWhere(params: ['id' => $id], relations: ['translations']);
        return response()->json([
            'id' => $attribute['id'],
            'name' => $attribute['name'],
            'translations' => $attribute['translations']
        ]);
    }


    public function add(AttributeRequest $request): JsonResponse|RedirectResponse
    {
        $dataArray = [
            'name' => $request['name'][array_search('en', $request['lang'])],
        ];
        if ($request->has('attribute_id') && $request->attribute_id) {
            $this->attributeRepo->update(id: $request->attribute_id, data: $dataArray);
            $this->translationRepo->update(request: $request, model: 'App\Models\Attribute', id: $request->attribute_id);
            ToastMagic::success(translate('attribute_updated_successfully'));
        } else {
            $savedAttributes = $this->attributeRepo->add(data: $dataArray);
            $this->translationRepo->add(request: $request, model: 'App\Models\Attribute', id: $savedAttributes->id);
            ToastMagic::success(translate('attribute_added_successfully'));
        }
        return back();
    }

    public function update(AttributeRequest $request): RedirectResponse
    {
        $dataArray = [
            'name' => $request['name'][array_search('en', $request['lang'])],
        ];

        $this->attributeRepo->update(id: $request['id'], data: $dataArray);
        $this->translationRepo->update(request: $request, model: 'App\Models\Attribute', id: $request['id']);

        ToastMagic::success(translate('attribute_updated_successfully'));
        return back();
    }

    public function delete(Request $request): JsonResponse
    {
        $this->attributeRepo->delete(params: ['id' => $request['id']]);
        $this->translationRepo->delete(model: 'App\Models\Attribute', id: $request['id']);
        return response()->json(['message' => translate('attribute_deleted_successfully')]);
    }

}
