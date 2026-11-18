<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/** What a seller API answers when the record it was asked for is not the seller's own. */
trait SellerOwnedRecords
{
    private function recordNotFound(string $code, string $message): JsonResponse
    {
        return response()->json(['errors' => [['code' => $code, 'message' => translate($message)]]], 404);
    }
}
