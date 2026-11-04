<?php

namespace Modules\AI\app\Services\Auction;

use Modules\AI\app\Response\AuctionAIResponse;

class AuctionAIResponseParser
{
    public function __construct(
        private readonly AuctionAIResponse $auctionAIResponse
    ) {
    }

    public function parseTitle(string $result): string
    {
        return $this->auctionAIResponse->title($result);
    }

    public function parseDescription(string $result): string
    {
        return $this->auctionAIResponse->description($result);
    }

    public function parseGeneralSetup(string $result): array
    {
        return $this->auctionAIResponse->generalSetup($result);
    }

    public function parseShippingPolicy(string $result): array
    {
        return $this->auctionAIResponse->shippingPolicy($result);
    }

    public function parseAuctionInfo(string $result): array
    {
        return $this->auctionAIResponse->auctionInfo($result);
    }

    public function parseSeo(string $result): array
    {
        return $this->auctionAIResponse->seo($result);
    }

    public function parseSetup(string $result): array
    {
        return $this->auctionAIResponse->setup($result);
    }
}
