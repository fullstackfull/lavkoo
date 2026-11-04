<?php

namespace App\Console\Commands;

use App\Models\BusinessSetting;
use App\Traits\PushNotificationTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Madnest\Madzipper\Facades\Madzipper;

class DatabaseRefresh extends Command
{
    use PushNotificationTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database:refresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh database after a certain time';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     * @throws Exception
     */
    public function handle(): void
    {
        // db:wipe on a live store is unrecoverable. This command exists for the demo platform's
        // periodic reset only; anywhere else it must refuse loudly instead of destroying data.
        if (config('app.mode') !== 'demo') {
            $this->error('database:refresh wipes the ENTIRE database and reimports demo data. It only runs when APP_MODE=demo.');
            return;
        }

        $this->demoResetNotification();
        Artisan::call('db:wipe');
        Artisan::call('cache:clear');

        Cache::put('demo_database_refresh', 1, 120);

        $sql_path = base_path('demo/database.sql');
        DB::unprepared(file_get_contents($sql_path));
        File::deleteDirectory('storage/app/public');
        Madzipper::make('demo/public.zip')->extractTo('storage/app');

        Cache::forget('demo_database_refresh');

        if (in_array(request()->ip(), ['127.0.0.1', '::1']) && config('app.mode') != 'demo') {
            Artisan::call('local:database-refresh');
        }
    }
}
