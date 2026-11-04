<?php

namespace Modules\AI\app\Http\Controllers\API\V3;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\AI\app\Contracts\AuctionAIContract;
use Modules\AI\app\Http\Requests\ApiRequests\AuctionDescriptionAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\AuctionGeneralSetupAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\AuctionInfoAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\AuctionSeoSectionAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\AuctionShippingPolicyAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\AuctionSetupAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\AuctionTitleAutoFillRequest;
use Modules\AI\app\Http\Requests\ApiRequests\GenerateTitleFromImageRequest;
use Modules\AI\app\Http\Requests\GenerateProductTitleSuggestionRequest;
use Modules\AI\app\Services\AIContentGeneratorService;
use Modules\AI\app\Services\AIUsageManagerService;
use Modules\AI\app\Services\Auction\AuctionAIResponseParser;

class AIAuctionProductController extends Controller
{
    public function __construct(
        private readonly AuctionAIContract $auctionAIService,
        private readonly AuctionAIResponseParser $auctionAIResponseParser,
        private readonly AIContentGeneratorService $aiContentGeneratorService,
        private readonly AIUsageManagerService $aiUsageManagerService
    ) {
        parent::__construct();
    }

    public function titleAutoFill(AuctionTitleAutoFillRequest $request): JsonResponse
    {
        try {
            $result = $this->auctionAIService->generateProductName(
                title: $request['name'],
                langCode: $request['langCode'] ?? 'en',
            );

            return $this->sectionSuccessResponse($this->auctionAIResponseParser->parseTitle($result));
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function descriptionAutoFill(AuctionDescriptionAutoFillRequest $request): JsonResponse
    {
        try {
            $result = $this->auctionAIService->generateProductDescription(
                title: $request['name'],
                langCode: $request['langCode'] ?? 'en',
            );

            return $this->sectionSuccessResponse($this->auctionAIResponseParser->parseDescription($result));
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function generalSetupAutoFill(AuctionGeneralSetupAutoFillRequest $request): JsonResponse
    {
         try {
            $result = $this->auctionAIService->generateGeneralSetup(
                title: $request['name'],
                description: $request['description'],
            );

            return $this->sectionSuccessResponse($this->auctionAIResponseParser->parseGeneralSetup($result));
         } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function shippingPolicyAutoFill(AuctionShippingPolicyAutoFillRequest $request): JsonResponse
    {
        try {
            $result = $this->auctionAIService->generateShippingPolicy(
                title: $request['name'],
                description: $request['description'],
            );

            return $this->sectionSuccessResponse($this->auctionAIResponseParser->parseShippingPolicy($result));
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function auctionInfoAutoFill(AuctionInfoAutoFillRequest $request): JsonResponse
    {
        try {
            return $this->buildAuctionInfoResponse($request['name'], $request['description']);
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function seoSectionAutoFill(AuctionSeoSectionAutoFillRequest $request): JsonResponse
    {
        try {
            $result = $this->auctionAIService->generateSeoContent(
                title: $request['name'],
                description: $request['description'],
            );

            return $this->sectionSuccessResponse($this->auctionAIResponseParser->parseSeo($result));
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function generateTitleFromImages(GenerateTitleFromImageRequest $request): JsonResponse
    {
        try {
            $imageFile = $request->file('image');
            $imagePath = $this->aiContentGeneratorService->getAuctionImagePath($imageFile);
            $result = $this->auctionAIService->generateProductNameFromImage(
                imageUrl: $imagePath['imageFullPath'],
            );
            $this->aiContentGeneratorService->deleteAiImage($imagePath['imageName'], 'auction');

            return $this->sectionSuccessResponse($this->auctionAIResponseParser->parseTitle($result));
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function generateProductTitleSuggestion(GenerateProductTitleSuggestionRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(
                contentType: "generate_product_title_suggestion",
                context: $request['keywords'],
                description: $request['description'] ?? null,
            );

            return $this->sectionSuccessResponse(json_decode($result, true));
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function setupAutoFill(AuctionSetupAutoFillRequest $request): JsonResponse
    {
        try {
            return $this->buildAuctionInfoResponse($request['name'], $request['description']);
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function generateLimitCheck(): JsonResponse
    {
        try {
            return $this->successResponse(data: $this->aiUsageManagerService->getGenerateRemainingCount());
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    private function buildAuctionInfoResponse(string $title, ?string $description = null): JsonResponse
    {
        $result = $this->auctionAIService->generateAuctionInfo(
            title: $title,
            description: $description,
        );

        return $this->sectionSuccessResponse($this->auctionAIResponseParser->parseAuctionInfo($result));
    }

    private function sectionSuccessResponse(mixed $data): JsonResponse
    {
        return $this->successResponse(data: [
            'data' => $data,
            'remaining_count' => $this->aiUsageManagerService->getGenerateRemainingCount(),
        ]);
    }
}
