<?php

namespace Modules\AI\app\Contracts;

interface AuctionAIContract
{
    public function generateProductName(string $title, string $langCode = 'en'): string;

    public function generateProductNameFromImage(string $imageUrl, string $langCode = 'en'): string;

    public function generateProductDescription(string $title, string $langCode = 'en'): string;

    public function generateGeneralSetup(string $title, ?string $description = null): string;

    public function generateShippingPolicy(string $title, ?string $description = null): string;

    public function generateAuctionInfo(string $title, ?string $description = null): string;

    public function generateSeoContent(string $title, ?string $description = null): string;

    public function generateSetupSuggestion(string $title, ?string $description = null): string;
}
