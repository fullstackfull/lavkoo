<?php

namespace Modules\Blog\app\Services\Frontend;

use DOMDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\App;
use Modules\Blog\app\Models\Blog;
use Modules\Blog\app\Models\BlogCategory;
use Modules\Blog\app\Models\BlogTranslation;

class FrontendBlogService
{
    public function getCheckLocale(object|array $request): void
    {
        if ($request->has('locale') && !empty($request['locale'])) {
            foreach (getWebConfig('language') as $language) {
                if ($request['locale'] == getLanguageCode($language['code'])) {
                    session()->put('local', $language['code']);
                    App::setLocale(getLanguageCode(country_code: $language['code']));
                    if (array_key_exists('direction', $language)) {
                        session()->put('direction', $language['direction']);
                    }
                }
            }
        }
    }

    function getModifiedDescription(string|null $description): bool|string
    {
        if ($description) {
            $domDocument = new DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            $domDocument->loadHTML(mb_convert_encoding($description, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            foreach ($domDocument->getElementsByTagName('h2') as $index => $blogTag) {
                $blogTag->setAttribute('id', 'article-section-' . $index);
            }
            return $domDocument->saveHTML();
        }
        return $description;
    }

    function getModifiedDescriptionLinks($description): array
    {
        $blogLinks = [];
        if ($description) {
            $domDocument = new DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            $domDocument->loadHTML(mb_convert_encoding($description, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            foreach ($domDocument->getElementsByTagName('h2') as $index => $blogTag) {
                $blogLinks[] = [
                    'id' => 'article-section-' . $index,
                    'text' => $blogTag->textContent
                ];
            }
        }
        return $blogLinks;
    }

    /** The details page of one post as it is live. The public and the admin preview both render through here. */
    public function detailsView(Blog $blogData): View
    {
        $popularBlogList = Blog::with(['category' => function ($query) {
            return $query->active();
        }, 'translations'])->latest()->paginate(3);
        $articleLinks = $this->getModifiedDescriptionLinks(description: $blogData['description']);
        $updatedDescription = $this->getModifiedDescription(description: $blogData['description']);

        return view(VIEW_FILE_NAMES['frontend_blog_details_view'], [
            'blogData' => $blogData,
            'popularBlogList' => $popularBlogList,
            'articleLinks' => $articleLinks,
            'updatedDescription' => $updatedDescription,
            'blogPlatform' => strpos(url()->current(), '/app') ? 'app' : 'web',
            'blogListRoute' => strpos(url()->current(), '/app') ? 'app.blog.index' : 'frontend.blog.index',
            'popularBlogListRoute' => strpos(url()->current(), '/app') ? 'app.blog.popular-blog' : 'frontend.blog.popular-blog',
            'blogDetailsRoute' => strpos(url()->current(), '/app') ? 'app.blog.details' : 'frontend.blog.details',
        ]);
    }

    /** The post as its unpublished draft reads. Used only for an admin's preview; nothing is saved. */
    public function withDraftApplied(Blog $blogData): Blog
    {
        $draftData = $blogData['draft_data'] ?? [];
        if (empty($draftData)) {
            return $blogData;
        }

        $translatedData = BlogTranslation::where(['translation_id' => $blogData['id'], 'locale' => getDefaultLanguage(), 'is_draft' => 1])->get();
        $blogData->title = $translatedData?->firstWhere('key', 'title')?->value ?? $draftData['title'] ?? '';
        $blogData->description = $translatedData?->firstWhere('key', 'description')?->value ?? $draftData['description'] ?? '';
        $blogData->category = BlogCategory::where('id', ($draftData['category_id'] ?? 0))->first();
        $blogData->writer = $draftData['writer'] ?? '';
        $blogData->publish_date = $draftData['publish_date'] ?? '';
        $blogData->image = $blogData?->draft_image ?? null;

        return $blogData;
    }
}
