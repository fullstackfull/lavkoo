<?php

namespace App\Services;

class PrioritySetupService
{
    public function updateBrandAndCategoryPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'sort_by' => $data['sort_by'] ?? null,
        ];
    }

    public function updateTopVendorPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'minimum_rating_point' => $data['minimum_rating_point'] ?? null,
            'sort_by' => $data['sort_by'] ?? null,
            'vacation_mode_sorting' => $data['vacation_mode_sorting'] ?? null,
            'temporary_close_sorting' => $data['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateVendorPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'sort_by' => $data['sort_by'] ?? null,
            'vacation_mode_sorting' => $data['vacation_mode_sorting'] ?? null,
            'temporary_close_sorting' => $data['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateVendorProductListPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'sort_by' => $data['sort_by'] ?? null,
            'out_of_stock_product' => $data['out_of_stock_product'] ?? null,
        ];
    }

    public function updateFeaturedProductPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'sort_by' => $data['sort_by'] ?? null,
            'out_of_stock_product' => $data['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $data['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateFeatureDealPrioritySetupData($request): array
    {
        return [
            'custom_sorting_status' => $request->get('custom_sorting_status', 0),
            'sort_by' => $request['sort_by'] ?? null,
            'out_of_stock_product' => $request['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $request['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateFlashDealPrioritySetupData($request): array
    {
        return [
            'custom_sorting_status' => $request->get('custom_sorting_status', 0),
            'sort_by' => $request['sort_by'] ?? null,
            'out_of_stock_product' => $request['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $request['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateNewArrivalProductListPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'duration' => $data['duration'] ?? 1,
            'duration_type' => $data['duration_type'] ?? 'month',
            'sort_by' => $data['sort_by'] ?? null,
            'out_of_stock_product' => $data['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $data['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateTopRatedProductListPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'minimum_rating_point' => $data['minimum_rating_point'] ?? null,
            'sort_by' => $data['sort_by'] ?? null,
            'out_of_stock_product' => $data['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $data['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateCategoryWiseProductListPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'sort_by' => $data['sort_by'] ?? null,
            'out_of_stock_product' => $data['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $data['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateBestSellingProductListPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'sort_by' => $data['sort_by'] ?? null,
            'out_of_stock_product' => $data['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $data['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateProductListPrioritySetupData($data): array
    {
        return [
            'custom_sorting_status' => $data['custom_sorting_status'] ?? 0,
            'out_of_stock_product' => $data['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $data['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateStockClearanceProductPrioritySetupData($request): array
    {
        return [
            'custom_sorting_status' => $request->get('custom_sorting_status', 0),
            'sort_by' => $request['sort_by'] ?? null,
            'out_of_stock_product' => $request['out_of_stock_product'] ?? null,
            'temporary_close_sorting' => $request['temporary_close_sorting'] ?? null,
        ];
    }

    public function updateBlogCategoryPrioritySetupData($request): array
    {
        return [
            'default_sorting_status' => $request['default_sorting_status'] ?? 0,
            'custom_sorting_status' => $request['custom_sorting_status'] ?? 0,
            'sort_by' => $request['sort_by'] ?? null,
        ];
    }

    public function updateBlogPrioritySetupData($request): array
    {
        return [
            'default_sorting_status' => $request['default_sorting_status'] ?? 0,
            'custom_sorting_status' => $request['custom_sorting_status'] ?? 0,
            'sort_by' => $request['sort_by'] ?? null,
        ];
    }
}
