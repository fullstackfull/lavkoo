<?php

namespace Modules\AI\app\Services;

use Modules\AI\app\Exceptions\UsageLimitException;
use Modules\AI\app\Models\AISetting;
use Modules\AI\app\Models\AISettingLog;
use Modules\AI\app\Utils\CurrentAuthUser;

class AIUsageManagerService
{
    protected function getTextGenerateLimit(AISetting $provider): int
    {
        if (env('APP_MODE') == 'demo') {
            return 10;
        }

        if (CurrentAuthUser::isCustomer()) {
            return (int) ($provider->customer_generate_limit ?? 0);
        }

        return (int) ($provider->generate_limit ?? 0);
    }

    protected function getImageUploadLimit(AISetting $provider): int
    {
        if (env('APP_MODE') == 'demo') {
            return 10;
        }

        if (CurrentAuthUser::isCustomer()) {
            return (int) ($provider->customer_image_upload_limit ?? 0);
        }

        return (int) ($provider->image_upload_limit ?? 0);
    }

    /**
     * @throws UsageLimitException
     */
    public function checkUsageLimits(AISettingLog $log, AISetting $provider, ?string $imageUrl, ?string $section = null): void
    {
        $textGenerateLimit = $this->getTextGenerateLimit($provider);
        $imageUploadLimit = $this->getImageUploadLimit($provider);
        $remainingImgAction = $imageUploadLimit <= 0 ? 0 : ($log?->total_image_generated_count < $imageUploadLimit ? ($imageUploadLimit - $log->total_image_generated_count) : 0);

        if (!empty($imageUrl)) {
            if ($remainingImgAction <= 0) {
                throw new UsageLimitException('Image upload limit reached for this account.');
            }
        } else {
            if ($section !== 'generate_title_from_image' &&
                $log->total_generated_count >= $textGenerateLimit) {
                throw new UsageLimitException('Text generation limit reached for this account.');
            }
        }
    }

    public function incrementUsage(AISettingLog $log, ?string $imageUrl, ?string $section = ''): void
    {
        if (!empty($imageUrl)) {
            $log->total_image_generated_count += 1;
        }
        $log->total_generated_count += 1;
        $usage = $log->section_usage ?? [];
        $usage[$section] = ($usage[$section] ?? 0) + 1;
        $log->section_usage = $usage;
        $log->save();
    }

    public function getOrCreateLog(AISetting $activeProvider): AISettingLog
    {
        $actorId = CurrentAuthUser::id();
        $aiSettingLog = AISettingLog::where('seller_id', $actorId)->first();
        if (!$aiSettingLog) {
            $aiSettingLog = new AISettingLog();
            $aiSettingLog->seller_id = $actorId;
            $aiSettingLog->total_generated_count = 0;
            $aiSettingLog->total_image_generated_count = 0;
        }
        $aiSettingLog->limit_at_time = $this->getTextGenerateLimit($activeProvider);
        return $aiSettingLog;
    }

    public function getGenerateRemainingCount(): int
    {
        if (CurrentAuthUser::isAdmin()) {
            return 0;
        }

        $actorId = CurrentAuthUser::id();
        if (!$actorId) {
            return 0;
        }

        $aiSettingLog = AISettingLog::where('seller_id', $actorId)->first();
        $activeProvider = AISetting::first();
        if (!$activeProvider) {
            return 0;
        }

        $generateLimit = $this->getTextGenerateLimit($activeProvider);
        if (!$aiSettingLog) {
            return $generateLimit;
        }
        return $generateLimit <= 0 ? 0 : (($aiSettingLog->total_generated_count < $generateLimit) ? ($generateLimit - $aiSettingLog->total_generated_count) : 0);
    }

}
