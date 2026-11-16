<?php

namespace Tests\Feature\Blog;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\LogUserBrowsingNavigationMiddleware;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * A blog post is public only while it is active and published. The details page used to look a post up by slug alone, and a draft
 * copy was shown to anyone who added ?source=draft. The public page now shows only live posts. The draft is previewed only through
 * the admin area, behind its authentication and the marketing module's permission.
 */
class BlogPostVisibilityTest extends TestCase
{
    private const ACTIVE = 1;
    private const INACTIVE = 0;
    private const MARKETING_MODULE = 'marketing';

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, LogUserBrowsingNavigationMiddleware::class,
        ]);

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        DB::table('business_settings')->insert([
            'type' => 'blog_feature_active_status', 'value' => json_encode(1), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->integer('priority')->default(0);
            $table->integer('click_count')->default(0);
            $table->timestamps();
        });
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->mediumText('slug');
            $table->string('readable_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('writer')->nullable();
            $table->string('title');
            $table->longText('description');
            $table->string('image')->nullable();
            $table->string('image_storage_type', 15)->nullable();
            $table->string('draft_image')->nullable();
            $table->string('draft_image_storage_type', 15)->nullable();
            $table->datetime('publish_date')->nullable();
            $table->tinyInteger('is_published')->default(0);
            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('is_draft')->default(0);
            $table->text('draft_data')->nullable();
            $table->integer('click_count')->default(0);
            $table->timestamps();
        });
        Schema::create('blog_translations', function (Blueprint $table) {
            $table->id();
            $table->string('translation_type');
            $table->unsignedBigInteger('translation_id');
            $table->string('key');
            $table->longText('value')->nullable();
            $table->string('locale', 10)->nullable();
            $table->tinyInteger('is_draft')->default(0);
            $table->timestamps();
        });
        Schema::create('blog_seos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('blog_id');
            $table->timestamps();
        });
    }

    private function insertPost(string $slug, int $status, int $isPublished, string $liveTitle, ?string $draftTitle = null): int
    {
        $draftData = $draftTitle === null ? null : json_encode(['title' => $draftTitle, 'description' => 'Draft body']);

        return DB::table('blogs')->insertGetId([
            'slug' => $slug,
            'readable_id' => $slug,
            'writer' => 'Writer',
            'title' => $liveTitle,
            'description' => '<p>Live body</p>',
            'publish_date' => now()->subDay(),
            'is_published' => $isPublished,
            'status' => $status,
            'is_draft' => 0,
            'draft_data' => $draftData,
            'click_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function adminWithModules(array $modules): Admin
    {
        $roleId = 7;
        DB::table('admin_roles')->insert([
            'id' => $roleId,
            'name' => 'Test role',
            'module_access' => json_encode($modules),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (new Admin())->forceFill(['id' => 2, 'admin_role_id' => $roleId, 'status' => 1]);
    }

    public function test_a_live_post_is_shown_to_a_guest(): void
    {
        $this->insertPost(slug: 'live-post', status: self::ACTIVE, isPublished: 1, liveTitle: 'Live Title');

        $this->get('/blog/live-post')
            ->assertOk()
            ->assertViewIs(VIEW_FILE_NAMES['frontend_blog_details_view'])
            ->assertSee('Live Title');
    }

    public function test_a_draft_copy_is_never_shown_on_the_public_page_even_with_the_source_parameter(): void
    {
        $this->insertPost(slug: 'live-post', status: self::ACTIVE, isPublished: 1, liveTitle: 'Live Title', draftTitle: 'Secret Draft Title');

        $this->get('/blog/live-post?source=draft')
            ->assertOk()
            ->assertSee('Live Title')
            ->assertDontSee('Secret Draft Title');
    }

    public function test_a_post_saved_as_a_draft_is_not_shown_to_a_guest(): void
    {
        $this->insertPost(slug: 'unpublished-post', status: self::ACTIVE, isPublished: 0, liveTitle: 'Unpublished Title');

        $this->get('/blog/unpublished-post')
            ->assertRedirect()
            ->assertDontSee('Unpublished Title');
    }

    public function test_an_inactive_post_is_not_shown_to_a_guest(): void
    {
        $this->insertPost(slug: 'inactive-post', status: self::INACTIVE, isPublished: 1, liveTitle: 'Inactive Title');

        $this->get('/blog/inactive-post')
            ->assertRedirect()
            ->assertDontSee('Inactive Title');
    }

    public function test_a_guest_gets_no_admin_draft_preview(): void
    {
        $postId = $this->insertPost(slug: 'live-post', status: self::ACTIVE, isPublished: 1, liveTitle: 'Live Title', draftTitle: 'Secret Draft Title');

        $this->get(route('admin.blog.draft-preview', ['id' => $postId]))
            ->assertNotFound()
            ->assertDontSee('Secret Draft Title');
    }

    public function test_an_admin_with_the_marketing_module_previews_the_draft(): void
    {
        $postId = $this->insertPost(slug: 'live-post', status: self::ACTIVE, isPublished: 1, liveTitle: 'Live Title', draftTitle: 'Draft Preview Title');

        $this->actingAs($this->adminWithModules([self::MARKETING_MODULE]), 'admin')
            ->get(route('admin.blog.draft-preview', ['id' => $postId]))
            ->assertOk()
            ->assertSee('Draft Preview Title');
    }

    public function test_an_admin_without_the_marketing_module_cannot_preview_the_draft(): void
    {
        $postId = $this->insertPost(slug: 'live-post', status: self::ACTIVE, isPublished: 1, liveTitle: 'Live Title', draftTitle: 'Secret Draft Title');

        $this->actingAs($this->adminWithModules(['orders']), 'admin')
            ->get(route('admin.blog.draft-preview', ['id' => $postId]))
            ->assertRedirect()
            ->assertDontSee('Secret Draft Title');
    }
}
