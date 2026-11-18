<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Default Cloud Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Many applications store files both locally and in the cloud. For this
    | reason, you may specify a default "cloud" driver here. This driver
    | will be bound as the Cloud disk implementation in the container.
    |
    */

    'cloud' => env('FILESYSTEM_CLOUD', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
        ],

        /*
         * The disk of the digital product files while DIGITAL_FILES_PRIVATE is on. It is not `public`: it has no address, nothing in public/ links to it,
         * its files are not readable by the other users of the machine, and the shop reads it only through App\Services\DigitalProductFileService.
         * A shop served from its application root (index.php next to storage/) must also tell its web server not to serve storage/app/private: the
         * root .htaccess does it for Apache, anything else needs the same rule (nginx: `location ^~ /storage/app/private { deny all; }`).
         */
        'digital_private' => [
            'driver' => 'local',
            'root' => storage_path('app/private/digital'),
            'visibility' => 'private',
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'visibility' => 'public',
            'endpoint' => env('AWS_ENDPOINT'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Private digital product files
    |--------------------------------------------------------------------------
    |
    | Off by default, so a shop that has its digital files on the public disk behaves exactly as before: a new file is stored on the public disk
    | and its address answers. On, a NEW digital file is stored on the `digital_private` disk, a file is read from it first and from the public disk
    | second (so a file stored before the switch is still delivered until `php artisan digital-files:privatize` has moved it), and every answer
    | that used to carry the public address of a file carries the authenticated download route of its area instead.
    |
    | The value is read with FILTER_VALIDATE_BOOLEAN, not a (bool) cast: the cast turns "off" and "no" into TRUE. With a cached configuration
    | (bootstrap/cache/config.php) a change in .env does nothing until `php artisan config:clear`.
    */
    'digital_files_private' => filter_var(env('DIGITAL_FILES_PRIVATE', false), FILTER_VALIDATE_BOOLEAN),

];
