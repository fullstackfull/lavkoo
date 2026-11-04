<?php

namespace Modules\AI\app\Services\Auction;

use Modules\AI\app\Contracts\AuctionAIContract;
use Modules\AI\app\Services\AIContentGeneratorService;

class AuctionAIContentService implements AuctionAIContract
{
    public function __construct(
        private readonly AIContentGeneratorService $aiContentGeneratorService
    ) {
    }

    public function generateProductName(string $title, string $langCode = 'en'): string
    {
        return $this->generateAuctionContent(
            contentType: 'auction_title',
            context: $title,
            langCode: $langCode,
        );
    }

    public function generateProductNameFromImage(string $imageUrl, string $langCode = 'en'): string
    {
        return $this->aiContentGeneratorService->generateContent(
            contentType: 'generate_title_from_image',
            langCode: $langCode,
            imageUrl: $imageUrl,
        );
    }

    public function generateProductDescription(string $title, string $langCode = 'en'): string
    {
        return $this->generateAuctionContent(
            contentType: 'auction_description',
            context: $title,
            langCode: $langCode,
        );
    }

    public function generateGeneralSetup(string $title, ?string $description = null): string
    {
        return $this->generateAuctionContent(
            contentType: 'auction_general_setup',
            context: $title,
            description: $description,
        );
    }

    public function generateShippingPolicy(string $title, ?string $description = null): string
    {
        return $this->generateAuctionContent(
            contentType: 'auction_shipping_policy',
            context: $title,
            description: $description,
        );
    }

    public function generateAuctionInfo(string $title, ?string $description = null): string
    {
        return $this->generateAuctionContent(
            contentType: 'auction_info',
            context: $title,
            description: $description,
        );
    }

    public function generateSeoContent(string $title, ?string $description = null): string
    {
        return $this->generateAuctionContent(
            contentType: 'auction_seo_section',
            context: $title,
            description: $description,
        );
    }

    public function generateSetupSuggestion(string $title, ?string $description = null): string
    {
        return $this->generateAuctionInfo(title: $title, description: $description);
    }

    private function generateAuctionContent(
        string $contentType,
        ?string $context = null,
        string $langCode = 'en',
        ?string $description = null
    ): string {
        return $this->aiContentGeneratorService->generateContent(
            contentType: $contentType,
            context: $context,
            langCode: $langCode,
            description: $description,
        );
    }
}
