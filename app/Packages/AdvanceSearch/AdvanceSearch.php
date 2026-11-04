<?php

declare(strict_types=1);

namespace App\Packages\AdvanceSearch;

use Illuminate\Http\JsonResponse;
use stdClass;
use App\Models\Translation;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\After;
use App\Packages\AdvanceSearch\Traits\ModelsWithRoutesTrait;
use App\Packages\AdvanceSearch\Traits\AdminMenuWithRoutesTrait;

class AdvanceSearch
{
    use ModelsWithRoutesTrait;
    use AdminMenuWithRoutesTrait;

    protected string $keyword = '';
    protected string $type = '';

    public function __construct(string $type = '', ?string $keyword = null)
    {
        $this->type = $type;
        $this->keyword = $keyword ?? '';
    }


    public function searchAllList(): array
    {
        $result = array_merge(
            $this->searchModelList(),
            $this->searchPageList(),
            $this->searchMenuList(),
        );

        $searchTerm = strtolower($this->keyword);
        $themeWiseSkipRoutes = $this->getThemeWiseSkipRoutesList();
        $scored = collect($result)->filter(function ($item) use ($themeWiseSkipRoutes) {
            return isset($item['priority']) && (!in_array($item['uri'], $themeWiseSkipRoutes));
        })->map(function ($item) use ($searchTerm, $themeWiseSkipRoutes) {
            $score = 0;
            if (isset($item['page_title']) && str_contains(strtolower($item['page_title']), $searchTerm)) {
                $score += 1;
            }
            $item['match_score'] = $score;
            return $item;
        });

        $sorted = $scored->sort(function ($a, $b) {
            if ($a['match_score'] === $b['match_score']) {
                return $a['priority'] <=> $b['priority'];
            }
            return $b['match_score'] <=> $a['match_score'];
        })->values();

        return $sorted->groupBy('type')->map(function ($items) {
            return $items->unique('uri')->values();
        })->toArray();
    }

    public function getThemeWiseSkipRoutesList(): array
    {
        return [
            'admin/pages-and-media/features-section',
            'admin/brand/add-new',
        ];
    }
    public function searchMenuList()
    {
        $keyword = strtolower($this->keyword);

        if ($this->type == "admin") {
            $result = $this->getAdminMenuWithRoutes();

            $translatedMenus = [];
            if (getDefaultLanguage() != 'en') {
                $messageArray = include(base_path('resources/lang/'.getDefaultLanguage().'/messages.php'));
                $newMessageArray = include(base_path('resources/lang/'.getDefaultLanguage().'/new-messages.php'));
                $allMessages = array_merge($messageArray, $newMessageArray);

                $allMessageKeys = [];
                foreach ($allMessages as $key => $value) {
                    if (str_contains(strtolower((string)$value), $keyword)) {
                        $allMessageKeys[] = strtolower($key);
                    }
                }

                $translatedMenus = collect($result)->filter(function ($item) use ($keyword, $allMessageKeys) {
                    $value = strtolower((string)($item['page_title_value'] ?? ''));
                    return in_array($value, $allMessageKeys);
                })->unique('uri')->values()->toArray();
            }

            $getRawValues = collect($result)->filter(function ($item) use ($keyword) {
                $value = strtolower((string)($item['page_title_value'] ?? ''));
                $keywords = strtolower((string)($item['keywords'] ?? ''));
                if ($value === $keyword || str_contains($value, $keyword)) {
                    return true;
                }
                $keywordList = array_map('trim', explode(',', $keywords));
                foreach ($keywordList as $key) {
                    if ($key === $keyword || str_contains($key, $keyword)) {
                        return true;
                    }
                }
                return false;
            })->unique('uri')->values()->toArray();

            return collect(array_merge($translatedMenus, $getRawValues))
                ->map(function ($item) {
                    $item['page_title_value'] = translate($item['page_title']);
                    return $item;
                })
                ->unique('uri')
                ->values()
                ->toArray();
        }

        return [];
    }


    public function searchPageList(): JsonResponse|array
    {
        // json search
        $keyword = strtolower($this->keyword);
        $routesPath = public_path('json/admin/lang/' . getDefaultLanguage() . '.json');
        if (!File::exists($routesPath)) {
            $eng = public_path('json/admin/lang/en.json');
            $filename = public_path('json/admin/lang/' . getDefaultLanguage() . '.json');
            if (!file_exists(dirname($filename))) {
                File::makeDirectory(public_path('json/admin/lang'), 0777, true, true);
            }
            if (file_exists($eng)) {
                $content = file_get_contents($eng);
                file_put_contents($filename, $content);
            } else {
                file_put_contents($filename, json_encode(new stdClass(), JSON_PRETTY_PRINT));
            }
        }

        $adminFormatedRoutes = public_path('json/admin/admin_formatted_routes.json');
        $data = json_decode(File::get($routesPath), true);

        $adminData = json_decode(File::get($adminFormatedRoutes), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['error' => 'Invalid JSON in routes.json'], 500);
        }

        $matchedRoutes = [];

        foreach ($data as $key => $route) {
            if (empty($route['keywords'])) {
                continue;
            }

            $title = $this->formatTitle($route['page_title_value']);
            if ($title == $keyword) {
                $matchedRoutes[] = $route['key'];
            } elseif (preg_match('/' . preg_quote($keyword, '/') . '/i', $title)) {
                $matchedRoutes[] = $route['key'];
            } else {
                foreach ($route['keywords'] as $value) {
                    $normalizedValue = strtolower(trim($value));
                    if (strpos($normalizedValue, $keyword) !== false) {
                        $matchedRoutes[] = $route['key'];
                        break;
                    }
                }
            }
        }

        $matchedRoutes = array_unique($matchedRoutes);

        $finalMatchedRoutes = [];
        foreach ($adminData as $route) {
            if (in_array($route['key'], $matchedRoutes)) {
                $finalMatchedRoutes[] = [
                    "page_title" => translate($route['page_title']) ?? translate('Unknown'),
                    "page_title_value" => translate($route['page_title']) ?? translate('Unknown'),
                    "key" => $route['key'] ?? base64_encode("page_search_" . ($route['uri'] ?? '')),
                    "uri" => $route['uri'] ?? '',
                    "uri_count" => isset($route['uri']) ? count(explode('/', $route['uri'])) : 0,
                    "method" => $route['method'] ?? "GET",
                    "keywords" => $keyword,
                    "productNames" => [],
                    "items_count" => 1,
                    "priority" => 2,
                    "type" => 'page',
                ];
            }
        }
        return $finalMatchedRoutes;
    }
    function formatTitle($input)
    {
        $withSpaces = str_replace('_', ' ', $input);
        return strtolower(ucwords($withSpaces));
    }


    public function getImageType($model)
    {
        if ($model == 'products') {
            return 'product';
        } else {
            return 'backend-basic';
        }
    }

    public function getCacheTimeoutByDays(int $days = 3): int
    {
        return 60 * 60 * 24 * $days;
    }

    public function searchModelList(): JsonResponse|array
    {
        $result = [];

        $models = $this->getModels();

        $allTranslation = Cache::remember("cache_translations_table_for_advance_search", $this->getCacheTimeoutByDays(days: 2), function () {
            return Translation::all();
        })->where('locale', getDefaultLanguage());

        if (!empty($this->keyword)) {
            $keyword = strtolower($this->keyword);

            if (!empty($models)) {
                foreach ($models as $key => $table) {
                    if (!empty($table['access_type']) && in_array($this->type, $table['access_type'])) {

                        $cache_key = $this->getModelPrefix() . $table['model'];

                        // Walking customer (email walking@customer.com) is a POS-only placeholder
                        // — never a real customer the admin would want to navigate to from search.
                        // Append a cache-key suffix so any pre-existing cache that still contains
                        // it gets bypassed.
                        if ($table['model'] === 'App\Models\User') {
                            $cache_key .= '-skip-walking';
                        }

                        $allItems = Cache::remember($cache_key, $this->getCacheTimeoutByDays(days: 2), function () use ($table) {
                            $query = $table['model']::query();
                            $query->select($table['column']);
                            if (!empty($table['relations'])) {
                                $query->with(array_keys($table['relations']));
                            }

                            if ($table['model'] === 'App\Models\User') {
                                $query->whereNot('email', 'walking@customer.com');
                            }

                            return $query->get();
                        });

                        $filteredItems = $allItems->filter(function ($item) use ($keyword, $table, $allTranslation) {
                            foreach ($table['column'] as $column) {
                                $value = strtolower((string)($item->{$column} ?? ''));
                                if (preg_match('/(?<![a-zA-Z0-9])' . preg_quote($keyword, '/') . '(?![a-zA-Z0-9])/i', $value)) {
                                    return true;
                                }
                            }
                            if (!empty($table['relations'])) {
                                foreach ($table['relations'] as $relationName => $relationData) {
                                    $relatedItems = $item->{$relationName} ?? null;

                                    if ($relatedItems) {
                                        //hasMany
                                        if ($relatedItems instanceof \Illuminate\Support\Collection) {
                                            foreach ($relatedItems as $relatedItem) {
                                                foreach ($relationData['columns'] as $relColumn) {
                                                    $relValue = strtolower((string)($relatedItem->{$relColumn} ?? ''));
                                                    if (preg_match('/(?<![a-zA-Z0-9])' . preg_quote($keyword, '/') . '(?![a-zA-Z0-9])/i', $relValue)) {
                                                        return true;
                                                    }
                                                }
                                            }
                                        } else {
                                            //hasOne/belongsTo
                                            foreach ($relationData['columns'] as $relColumn) {
                                                $relValue = strtolower((string)($relatedItems->{$relColumn} ?? ''));
                                                if (preg_match('/(?<![a-zA-Z0-9])' . preg_quote($keyword, '/') . '(?![a-zA-Z0-9])/i', $relValue)) {
                                                    return true;
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            // Translation logic for product names
                            if ($table['type'] == "brands" || $table['type'] == "categories" || $table['type'] == "products") {
                                // $matches = collect($allTranslation)->first(function ($translation) use ($item, $keyword, $table) {
                                //     return $translation['translationable_type'] === $table['translationable_type']
                                //         && $translation['key'] === 'name'
                                //         && str_contains(strtolower($translation['value']), strtolower($keyword));
                                // });
                                // if ($matches) {
                                //     return true;
                                // }

                                // $matches = collect($allTranslation)->filter(function ($translation) use ($item, $keyword, $table) {
                                //     return $translation['translationable_type'] === $table['translationable_type']
                                //         && $translation['key'] === 'name'
                                //         && str_contains(strtolower($translation['value']), strtolower($keyword)) ? $translation : [];
                                // });

                                // if (count($matches) > 0) {
                                //     return true;
                                // }
                            }

                            return false;
                        })->values();



                        //  search result output
                        if ($filteredItems->count() > 0) {
                            foreach ($filteredItems as $item) {
                                $strictMatchRoutes = [
                                    'admin/customer/view/{id}',
                                    'admin/orders/details/{id}',
                                    'admin/products/update/{id}',
                                    'admin/vendors/view/{id}',
                                    'refund-section/refund/details/{id}',
                                    'admin/auction/products/view?id={id}',
                                ];

                                foreach ($table['routes'] as $route) {
                                    $finalRoute = $route;
                                    if (in_array($route, $strictMatchRoutes)) {
                                        $isExactMatch = false;
                                        foreach ($table['column'] as $column) {
                                            $value = strtolower((string)($item->{$column} ?? ''));
                                            if ($value === strtolower($keyword)) {
                                                $isExactMatch = true;
                                                break;
                                            }
                                        }
                                        if (!$isExactMatch) {
                                            continue;
                                        }
                                    }

                                    if (strpos($route, '{id}') !== false && isset($item->id)) {
                                        $finalRoute = str_replace('{id}', (string)$item->id, $route);
                                    }

                                    $thumbnail = getStorageImages(path: '', type: 'backend-basic');


                                    if (isset($item->thumbnail_full_url)) {
                                        $thumbnail = getStorageImages(path: $item->thumbnail_full_url, type: $this->getImageType($table['model']));
                                    } elseif (isset($item->image_full_url)) {
                                        $thumbnail = getStorageImages(path: $item->image_full_url, type: $this->getImageType($table['model']));
                                    }

                                    $result[] = [
                                        "page_title" => $item->name ?? ucfirst($this->getRouteName($route)),
                                        "page_title_value" => $item->name ?? ucfirst($this->getRouteName($route)),
                                        "key" => base64_encode("dbsearch" . $route . '' . $item->id),
                                        "uri" => $finalRoute,
                                        "uri_count" => count(explode('/', $route)),
                                        "method" => "GET",
                                        "keywords" => $keyword,
                                        "type" => $key,
                                        "relations" => [],
                                        "image" => $thumbnail,
                                        "priority" => 3
                                    ];


                                    //  related data in result
                                    if (!empty($table['relations'])) {
                                        foreach ($table['relations'] as $relationName => $relationData) {
                                            $relatedData = $item->{$relationName} ?? null;

                                            if ($relatedData) {
                                                $relatedData = is_array($relatedData) || $relatedData instanceof \Illuminate\Support\Collection
                                                    ? collect($relatedData)
                                                    : collect([$relatedData]);

                                                $relationRoutes = $this->type === 'admin'
                                                    ? ($relationData['admin_routes'] ?? [])
                                                    : ($relationData['vendor_routes'] ?? []);

                                                foreach ($relatedData as $relatedItem) {
                                                    foreach ($relationRoutes as $relRoute => $label) {
                                                        if (strpos($relRoute, '{id}') !== false && isset($relatedItem->id)) {
                                                            $finalRelRoute = str_replace('{id}', (string)$relatedItem->id, $relRoute);
                                                        } else {
                                                            $finalRelRoute = $relRoute;
                                                        }

                                                        $result[] = [
                                                            "page_title" => ucfirst($label),
                                                            "page_title_value" => ucfirst($label),
                                                            "uri" => $finalRelRoute,
                                                            "key" => base64_encode("dbsearch" . $relRoute . '' . $relatedItem->id),
                                                            "uri_count" => count(explode('/', $finalRelRoute)),
                                                            "method" => "GET",
                                                            "keywords" => $keyword,
                                                            "type" => $key,
                                                            "relations" => [
                                                                $relationName => collect($relatedItem)->only($relationData['columns']),
                                                            ],
                                                        ];
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        return response()->json(['error' => 'Access type not found'], 500);
                    }
                }
            }
        }

        return $result;
    }


    private function getRouteName($actualRouteName)
    {
        $actualRouteName = preg_replace('/\{[^}]+\}/', '', $actualRouteName);
        $routeNameParts = explode('/', $actualRouteName);
        if (count($routeNameParts) >= 2) {
            $lastPart = $routeNameParts[count($routeNameParts) - 1];
            $secondLastPart = $routeNameParts[count($routeNameParts) - 2];

            if (strtolower($lastPart) === 'index') {
                $lastPart = 'List';
            }

            $lastPartWords = explode(' ', str_replace(['_', '-'], ' ', $lastPart));
            $secondLastPartWords = explode(' ', str_replace(['_', '-'], ' ', $secondLastPart));
            $allWords = array_merge($secondLastPartWords, $lastPartWords);
            $uniqueWords = [];

            foreach ($allWords as $word) {
                $lowerWord = strtolower($word);
                if (empty($uniqueWords) || strtolower(end($uniqueWords)) !== $lowerWord) {
                    $uniqueWords[] = $word;
                }
            }

            if (count($uniqueWords) > 1 && strtolower($uniqueWords[0]) === strtolower(end($uniqueWords))) {
                array_shift($uniqueWords);
            }

            $uniqueWords = array_filter($uniqueWords, function ($word) {
                return strtolower($word) !== 'rental';
            });

            $routeName = ucwords(implode(' ', $uniqueWords));
        } else {
            $routeName = ucwords(str_replace(['.', '_', '-'], ' ', Str::afterLast($actualRouteName, '.')));
        }
        return $routeName;
    }
}
