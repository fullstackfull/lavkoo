<?php

namespace Tests\Support;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * An INFERRED minimal world for the digital files: the core store tables of BuildsFulfillmentSchema, the columns and tables the digital files touch
 * (the real migrations make the variations and the SEO rows), and the two disks a digital file can live on, each as a folder of its own under
 * storage/framework/testing, so a test never touches the files of a shop.
 */
trait BuildsDigitalFileWorld
{
    use BuildsFulfillmentSchema;

    protected const SELLER_TOKEN = 'dddddddddddddddddddddddddddddddddddddddd';

    protected const DIRECTORY = 'product/digital-product/';

    protected function buildDigitalFileWorld(): void
    {
        $this->buildCoreSchema();
        foreach (['2024_05_26_152339_create_product_seos_table.php', '2024_05_26_152030_create_digital_product_variations_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
        Schema::table('products', static function (Blueprint $table) {
            $table->string('digital_file_ready_storage_type')->nullable();
            $table->string('preview_file')->nullable();
            $table->string('meta_image')->nullable();
        });
        Schema::table('order_details', static fn (Blueprint $table) => $table->string('digital_file_after_sell')->nullable());
        Schema::create('users', static function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('sellers', static function (Blueprint $table) {
            $table->id();
            $table->string('auth_token')->nullable();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        DB::table('sellers')->insert(['id' => 7, 'auth_token' => self::SELLER_TOKEN, 'status' => 'approved']);
        Schema::create('digital_product_otp_verifications', static function (Blueprint $table) {
            $table->id();
            $table->string('order_details_id')->nullable();
            $table->string('identity')->nullable();
            $table->string('token')->nullable();
            $table->tinyInteger('otp_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /** The middleware of the web shell that needs the installation database, which what a test of a panel route asks about does not. */
    protected function withoutTheShellOfTheWeb(): void
    {
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
    }

    /** Both disks of a digital file as folders of their own, and the switch of the private mode. */
    protected function useDigitalDisks(bool $private): void
    {
        Storage::fake('public');
        Storage::fake('digital_private');
        config(['filesystems.digital_files_private' => $private]);
    }

    protected function removeDigitalDisks(): void
    {
        $files = new Filesystem();
        foreach (['public', 'digital_private'] as $disk) {
            $files->deleteDirectory(storage_path('framework/testing/disks/' . $disk));
        }
    }

    protected function putOnPublicDisk(string $name, string $content = 'the guide'): void
    {
        Storage::disk('public')->put(self::DIRECTORY . $name, $content);
    }

    protected function putOnPrivateDisk(string $name, string $content = 'the guide'): void
    {
        Storage::disk('digital_private')->put(self::DIRECTORY . $name, $content);
    }

    /** The approved seller the bearer token belongs to, who is also the one signed in to the vendor panel. */
    protected function sellerOfTheToken(): Seller
    {
        return Seller::findOrFail(7);
    }

    /** A document the way a request carries it. */
    protected function pdf(string $name = 'guide.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 4, 'application/pdf');
    }

    /**
     * What the web server answers for the address the apps built from `digital_product_url` and the name of a file: it serves the public disk
     * and nothing else (the private disk is not linked, and the web server is told not to serve it), so a file answers when the public disk holds it.
     */
    protected function publicAddressStatus(string $name): int
    {
        $address = (string) parse_url(dynamicStorage(path: 'storage/app/public/' . self::DIRECTORY . $name), PHP_URL_PATH);

        return Storage::disk('public')->exists((string) preg_replace('#^/storage/(app/public/)?#', '', $address)) ? 200 : 404;
    }
}
