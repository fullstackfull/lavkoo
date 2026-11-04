<?php

namespace Modules\AI\app\Http\Controllers\API\V3;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\AI\app\Exceptions\ApiException;
use Modules\AI\app\Http\Requests\ApiRequests\GeneralSetupRequest;
use Modules\AI\app\Http\Requests\ApiRequests\GenerateProductTitleSuggestionRequest;
use Modules\AI\app\Http\Requests\ApiRequests\GenerateTitleFromImageRequest;
use Modules\AI\app\Http\Requests\ApiRequests\ProductDescriptionAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\ProductPricingRequest;
use Modules\AI\app\Http\Requests\ApiRequests\ProductSeoSectionAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\ProductTitleAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\ProductVariationSetupAutoFillRequest;
use Modules\AI\app\Response\ProductResponse;
use Modules\AI\app\Services\AIContentGeneratorService;
use Modules\AI\app\Services\AIUsageManagerService;

class AIProductController extends Controller
{

    protected AIContentGeneratorService $aiContentGeneratorService;
    protected ProductResponse $productResponse;
    protected AIUsageManagerService $aIUsageManagerService;

    public function __construct(AIContentGeneratorService $AIContentGeneratorService, ProductResponse $productResponse, AIUsageManagerService $aiUsageManagerService)
    {
        parent::__construct();
        $this->aiContentGeneratorService = $AIContentGeneratorService;
        $this->productResponse = $productResponse;
        $this->aIUsageManagerService = $aiUsageManagerService;
    }

    public function titleAutoFill(ProductTitleAutoFillRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "product_name", context: $request['name'], langCode: $request['langCode'] ?? 'en');
            return $this->successResponse(data: $result, message: 'Title generated successfully', status: 200);
        } catch (ApiException $e) {
            return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
        }catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function descriptionAutoFill(ProductDescriptionAutoFillRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "product_description", context: $request['name'], langCode: $request['langCode']);
            return $this->successResponse(data: $result, status: 200);
        } catch (ApiException $e) {
            return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
        }catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }

    }

    public function generalSetupAutoFill(GeneralSetupRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "general_setup", context: $request['name'], description: $request['description']);
            $data = $this->productResponse->productGeneralSetupAutoFillFormat(result: $result);
            return $this->successResponse(data: $data, status: 200);
        } catch (ApiException $e) {
            return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
        }catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function pricingAndOthersAutoFill(ProductPricingRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "pricing_and_others", context: $request['name'], description: $request['description']);
            $data = $this->productResponse->productPriceAndOthersAutoFill(result: $result);
            return $this->successResponse(data: $data, status: 200);
        }catch (ApiException $e) {
            return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function productVariationSetupAutoFill(ProductVariationSetupAutoFillRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "variation_setup", context: $request['name'], description: $request['description']);
            $response = $this->productResponse->variationSetupAutoFill(result: $result);
            return $this->successResponse(data: $response['data'], status: 200);
        } catch (ApiException $e) {
            return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
        }catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function productSeoSectionAutoFill(ProductSeoSectionAutoFillRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "seo_section", context: $request['name'], description: $request['description']);
            $response = $this->productResponse->productSeoAutoFill(result: $result);
            return $this->successResponse(data: $response, status: 200);
        }catch (ApiException $e) {
            return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function generateProductTitleSuggestion(GenerateProductTitleSuggestionRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "generate_product_title_suggestion", context: $request['keywords'], description: $request['description']);
            $response = $this->productResponse->generateTitleSuggestions(result: $result);
            return $this->successResponse(data: $response, status: 200);
        } catch (ApiException $e) {
            return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
        }catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function generateTitleFromImages(GenerateTitleFromImageRequest $request): JsonResponse
    {
        try {
            $imageFile = $request->file('image');
            $imagePath = $this->aiContentGeneratorService->getAnalyizeImagePath($imageFile);
            $result = $this->aiContentGeneratorService->generateContent(contentType: "generate_title_from_image", imageUrl: $imagePath['imageFullPath']);
            $this->aiContentGeneratorService->deleteAiImage($imagePath['imageName'], 'product');
            return $this->successResponse(data: $result, status: 200);
        }catch (ApiException $e) {
            return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function generateLimitCheck(){
      try{
          $result =  $this->aIUsageManagerService->getGenerateRemainingCount();
          return $this->successResponse(data: $result);
      }catch (ApiException $e) {
          return $this->errorResponse(message: $e->getMessage(), status: $e->getStatusCode());
      } catch (Exception $e) {
          $status = $e->getCode() > 0 ? $e->getCode() : 500;
          return $this->errorResponse(message: $e->getMessage(), status: $status);
      }
    }
}
