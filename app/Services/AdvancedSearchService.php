<?php

namespace App\Services;

use App\Packages\AdvanceSearch\AdvanceSearch;

class AdvancedSearchService
{
    public function getSortRecentSearchByType(object|array $searchData): array
    {
        $fallbackResults = collect();
        $resultTypes = (new AdvanceSearch(type: 'admin', keyword: ''))->getResultTypes();

        foreach ($searchData as $search) {
            $response = is_string($search->response) ? json_decode($search->response, true) : $search->response;

            if ($this->isHitOfTheSearch(response: $response, resultTypes: $resultTypes)) {
                $fallbackResults->push($response);
            }
        }

        $fallbackResults = $fallbackResults->sortBy('priority')->values();
       return $fallbackResults->groupBy('type')->toArray();

    }

    /** A recent search holds what the employee's browser posted, so a hit is kept only if the search itself could have given it. */
    private function isHitOfTheSearch(mixed $response, array $resultTypes): bool
    {
        return is_array($response)
            && is_string($response['type'] ?? null)
            && in_array($response['type'], $resultTypes, true)
            && is_string($response['uri'] ?? null)
            && is_string($response['page_title_value'] ?? null)
            && $this->isPriorityOfTheSearch(priority: $response['priority'] ?? null)
            && is_string($response['image'] ?? '');
    }

    private function isPriorityOfTheSearch(mixed $priority): bool
    {
        return is_int($priority) || (is_string($priority) && is_numeric($priority));
    }
}
