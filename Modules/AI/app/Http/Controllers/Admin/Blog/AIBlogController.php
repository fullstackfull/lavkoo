<?php

namespace Modules\AI\app\Http\Controllers\Admin\Blog;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\AI\app\Http\Requests\Blog\BlogDescriptionRequest;
use Modules\AI\app\Http\Requests\Blog\BlogSeoSectionRequest;
use Modules\AI\app\Http\Requests\Blog\BlogTitleRequest;
use Modules\AI\app\Http\Requests\Blog\BlogTitleSuggestionRequest;
use Modules\AI\app\Http\Requests\Blog\GenerateBlogTitleFromImageRequest;
use Modules\AI\app\Http\Requests\GenerateTitleFromImageRequest;
use Modules\AI\app\Http\Requests\ProductSeoSectionAutoFillRequest;
use Modules\AI\app\Services\AIContentGeneratorService;

class AIBlogController extends Controller
{
    protected AIContentGeneratorService $aiContentGeneratorService;
    public function __construct(AIContentGeneratorService $AIContentGeneratorService)
    {
        parent::__construct();
        $this->aiContentGeneratorService = $AIContentGeneratorService;
    }

    public function titleAutoFill(BlogTitleRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "blog_title", context: $request['title'], langCode: $request['langCode']);
            return $this->successResponse(data: json_decode($result,true), status: 200);
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function descriptionAutoFill(BlogDescriptionRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "blog_description", context: $request['title'], langCode: $request['langCode']);
            return $this->successResponse(data: $result, status: 200);
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }
    public function seoSectionAutoFill(BlogSeoSectionRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "blog_seo_section", context: $request['title'], description: $request['description']);
            return $this->successResponse(data: json_decode($result,true), status: 200);
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }
    public function generateBlogTitleSuggestion(BlogTitleSuggestionRequest $request): JsonResponse
    {
        try {
            $result = $this->aiContentGeneratorService->generateContent(contentType: "blog_title_suggestion", context: $request['keywords'], description: $request['description']);
            $response = json_decode($result,true);
            return $this->successResponse(data: $response, status: 200);
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

    public function generateBlogTitleFromImages(GenerateBlogTitleFromImageRequest $request): JsonResponse
    {
        try {
            $imageFile = $request->file('image');
            $imagePath = $this->aiContentGeneratorService->getBlogImagePath($imageFile);
            $result = $this->aiContentGeneratorService->generateContent(contentType: "generate_blog_title_from_image", description: $request['description'], imageUrl: $imagePath['imageFullPath']);
            $this->aiContentGeneratorService->deleteAiImage($imagePath['imageName'],'blog');
            return $this->successResponse(data: $result, status: 200);
        } catch (Exception $e) {
            $status = $e->getCode() > 0 ? $e->getCode() : 500;
            return $this->errorResponse(message: $e->getMessage(), status: $status);
        }
    }

}
