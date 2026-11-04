<?php

namespace Modules\AI\app\Services;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Traits\FileManagerTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\AI\AIProviders\AIProviderManager;
use Modules\AI\AIProviders\ClaudeProvider;
use Modules\AI\AIProviders\OpenAIProvider;
use Modules\AI\app\Exceptions\AIProviderException;
use Modules\AI\app\Exceptions\ImageValidationException;
use Modules\AI\app\Exceptions\UsageLimitException;
use Modules\AI\app\Exceptions\ValidationException;
use Modules\AI\app\PromptTemplates\Auction\AuctionDescriptionTemplate;
use Modules\AI\app\PromptTemplates\Auction\AuctionGeneralSetupTemplate;
use Modules\AI\app\PromptTemplates\Auction\AuctionInfoTemplate;
use Modules\AI\app\PromptTemplates\Auction\AuctionSeoSectionTemplate;
use Modules\AI\app\PromptTemplates\Auction\AuctionShippingPolicyTemplate;
use Modules\AI\app\PromptTemplates\Auction\AuctionSetupTemplate;
use Modules\AI\app\PromptTemplates\Auction\AuctionTitleTemplate;
use Modules\AI\app\PromptTemplates\Blog\BlogSeoSectionTemplate;
use Modules\AI\app\PromptTemplates\Blog\DescriptionTemplate;
use Modules\AI\app\PromptTemplates\Blog\GenerateBlogTitleFromImageTemplate;
use Modules\AI\app\PromptTemplates\Blog\TitleSuggestionTemplate;
use Modules\AI\app\PromptTemplates\Blog\TitleTemplate;
use Modules\AI\app\PromptTemplates\GeneralSetupTemplates;
use Modules\AI\app\PromptTemplates\GenerateProductTitleSuggestionTemplate;
use Modules\AI\app\PromptTemplates\GenerateTitleFromImageTemplate;
use Modules\AI\app\PromptTemplates\PricingTemplate;
use Modules\AI\app\PromptTemplates\ProductDescriptionTemplate;
use Modules\AI\app\PromptTemplates\ProductNameTemplate;
use Modules\AI\app\PromptTemplates\ProductVariationSetup;
use Modules\AI\app\PromptTemplates\SeoSectionTemplate;

class AIContentGeneratorService
{
    use FileManagerTrait;

    protected array $templates = [];
    protected array $providers;

    public function __construct()
    {
        $this->loadTemplates();
        $this->providers = [new OpenAIProvider(), new ClaudeProvider()];
    }

    protected function loadTemplates(): void
    {
        $templateClasses = [
            'product_name' => ProductNameTemplate::class,
            'product_description' => ProductDescriptionTemplate::class,
            'general_setup' => GeneralSetupTemplates::class,
            'pricing_and_others' => PricingTemplate::class,
            'variation_setup' => ProductVariationSetup::class,
            'seo_section' => SeoSectionTemplate::class,
            'generate_product_title_suggestion' => GenerateProductTitleSuggestionTemplate::class,
            'generate_title_from_image' => GenerateTitleFromImageTemplate::class,
            'blog_title' => TitleTemplate::class,
            'blog_description' => DescriptionTemplate::class,
            'blog_title_suggestion' => TitleSuggestionTemplate::class,
            'blog_seo_section' => BlogSeoSectionTemplate::class,
            'generate_blog_title_from_image' => GenerateBlogTitleFromImageTemplate::class
            ,
            'auction_title' => AuctionTitleTemplate::class,
            'auction_description' => AuctionDescriptionTemplate::class,
            'auction_general_setup' => AuctionGeneralSetupTemplate::class,
            'auction_shipping_policy' => AuctionShippingPolicyTemplate::class,
            'auction_info' => AuctionInfoTemplate::class,
            'auction_seo_section' => AuctionSeoSectionTemplate::class,
            'auction_setup' => AuctionSetupTemplate::class,
        ];
        foreach ($templateClasses as $type => $class) {
            if (class_exists($class)) {
                $this->templates[$type] = new $class();
            }
        }
    }

    /**
     * @throws ImageValidationException
     * @throws AIProviderException
     * @throws ValidationException
     * @throws UsageLimitException
     */
    public function generateContent(string $contentType, mixed $context = null, string $langCode = 'en', ?string $description = null, ?string $imageUrl = null): string
    {
        $template = $this->templates[$contentType];
        $prompt = $template->build(context: $context, langCode: $langCode, description: $description, options:['image' => $imageUrl]);
        return (new AIProviderManager($this->providers))->generate(prompt: $prompt, imageUrl: $imageUrl, options: ['section' => $contentType, 'context' => $context, 'description' => $description]);
    }

    public function getAnalyizeImagePath($image): array
    {
        return $this->getAiImagePath($image, 'product');
    }

    public function getAuctionImagePath($image): array
    {
        return $this->getAiImagePath($image, 'auction');
    }

    public function getBlogImagePath($image): array
    {
        return $this->getAiImagePath($image, 'blog');
    }

    public function deleteAiImage($imageName, $type): void
    {
        $dir = "{$type}/ai_{$type}_image/". $imageName;
        $this->delete($dir);
    }

    /**
     * @throws \Exception
     */
    public function getAiImagePath($image, string $type): array
    {
        $storageConnectionType = getWebConfig(name: 'storage_connection_type') ?? 'public';
        $extension = $image->getClientOriginalExtension();

        if ($storageConnectionType === 'public') {
            $dir = "{$type}/ai_{$type}_image/";
            $imageName = $this->fileUpload(dir: $dir, format: $extension, file: $image);
            return $this->getAiImageFullPath($imageName, $type);
        }

        if ($storageConnectionType === 's3') {
            $imageName = "{$type}/ai_{$type}_image/" . Carbon::now()->toDateString() . '-' . uniqid() . '.' . $extension;
            Storage::disk('s3')->put($imageName, file_get_contents($image), 'public');
            return $this->getAiImageFullPath($imageName, $type);
        }

        throw new \Exception('Invalid storage connection type');
    }

    public function getAiImageFullPath(string $imageName, string $type): array
    {
        if (in_array(request()->ip(), ['127.0.0.1', '::1'])) {
            return [
                'imageName' => $imageName,
                'imageFullPath' => 'https://www.notebookcheck.net/fileadmin/_processed_/5/e/csm_IMG_7625_d5ec5f95a9.jpg',
            ];
        }
        $storageConnectionType = getWebConfig(name: 'storage_connection_type') ?? 'public';

        if ($storageConnectionType === 's3') {
            return [
                'imageName' => $imageName,
                'imageFullPath' => Storage::disk('s3')->url($imageName),
            ];
        }

        return [
            'imageName' => $imageName,
            'imageFullPath' => dynamicStorage(path: "storage/app/public/{$type}/ai_{$type}_image/{$imageName}"),
        ];
    }
}
