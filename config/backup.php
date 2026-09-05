<?php

declare(strict_types=1);
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| ⚠ WHY THIS FILE ARRIVED LATE.
|
| `spatie/laravel-backup` was installed in Phase 2 and `RecordBackupOutcome` —
| the listener that writes each run into `backup_log` so the foundation can
| answer "are we backed up?" — was registered in Phase 3. Nothing ever ran a
| backup. The package's own config was never published, and no schedule entry
| existed, so the listener sat waiting for events that were never fired and the
| table stayed empty.
|
| That is the exact shape CLAUDE.md's standing rule is about: a table, a
| listener and a policy all present, all correct, and the thing they describe
| not happening. Site Health asks "when did a backup last succeed?" — a question
| worth asking only if something is answering it.
|
| ── Shaped for shared hosting, not for a server ─────────────────────────────
|
| Three decisions here are about InMotion specifically:
|
| 1. THE BACKUP GOES TO LOCAL DISK, outside the web root. There is no S3 bucket
|    in scope and no credentials for one. A local backup protects against the
|    two things that actually happen — a bad deploy and a bad migration — and
|    does NOT protect against losing the account. `docs/PHASE-2-RUNBOOK.md`
|    covers pulling a copy off the server, which is the part a human has to do.
|
| 2. `storage/app/backups` IS EXCLUDED FROM ITSELF. Without that line each
|    backup contains every previous backup, and the archive doubles in size
|    every night until the account's disk quota stops it — which on shared
|    hosting takes the website down with it.
|
| 3. THE RETENTION IS SHORT, AND THE REASON IS INODES. cPanel counts files, not
|    just bytes. `scghf:media-doctor` reports the account's inode headroom; a
|    year of daily archives is a year of inodes spent on copies of a database
|    nobody will restore from beyond the last fortnight.
|
*/

return [

    'backup' => [

        'name' => env('APP_NAME', 'scghf'),

        'source' => [

            'files' => [

                /*
                 * The application, without the parts that regenerate.
                 *
                 * `vendor` and `node_modules` are reinstallable from the lock
                 * files and are the bulk of the file count. The media library
                 * is NOT excluded: those files are the foundation's own
                 * photographs and documents, and are the one thing here that
                 * cannot be rebuilt from git.
                 */
                'include' => [
                    base_path(),
                ],

                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    base_path('.git'),
                    storage_path('framework/cache'),
                    storage_path('framework/sessions'),
                    storage_path('framework/views'),
                    storage_path('debugbar'),

                    // ⚠ Never remove this line. See note 2 above.
                    storage_path('app/backups'),
                ],

                'follow_links' => false,
                'ignore_unreadable_directories' => true,
                'relative_path' => base_path(),
            ],

            'databases' => [
                'mysql',
            ],
        ],

        /*
         * Compressed, because the archive is stored on the same quota as the
         * website and a plain SQL dump of a donation database is mostly
         * repeated column names.
         */
        'database_dump_compressor' => GzipCompressor::class,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [

            'compression_method' => ZipArchive::CM_DEFAULT,

            'compression_level' => 9,

            'filename_prefix' => '',

            /*
             * `backups` is a local disk pointed OUTSIDE public/, defined in
             * config/filesystems.php. A backup reachable over the web is a
             * complete copy of the donor database available to anybody who
             * guesses the filename.
             */
            'disks' => [
                /*
                 * `BACKUP_DISK` was documented in `.env.example` from Phase 2
                 * and read by nothing. It is read here, and the default is the
                 * dedicated `backups` disk rather than `local` — see the note
                 * in config/filesystems.php about why the destination must sit
                 * outside the web root.
                 */
                env('BACKUP_DISK', 'backups'),
            ],
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        'encryption' => 'default',

        'tries' => 1,

        'retry_delay' => 0,
    ],

    /*
     * Who hears about a failure.
     *
     * Mail only — Slack and the rest need webhooks this deployment does not
     * have. The address is the technical contact rather than the general
     * enquiries inbox: a backup failure is not a message for whoever is
     * answering the public that morning.
     */
    'notifications' => [

        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            /*
             * `?:` and not env()'s second argument.
             *
             * `BACKUP_NOTIFICATION_EMAIL=` is PRESENT and empty in the shipped
             * `.env.example`, so `env('...', $fallback)` returns the empty
             * string and never reaches the fallback — and the package rejects
             * an empty address by throwing, which takes down every artisan
             * command including `schedule:run`. An unset key and a key set to
             * nothing are not the same thing, and this is one of the places
             * where the difference is an outage.
             */
            'to' => env('BACKUP_NOTIFICATION_EMAIL') ?: env('MAIL_FROM_ADDRESS') ?: 'backups@localhost',
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS') ?: 'backups@localhost',
                'name' => env('MAIL_FROM_NAME') ?: 'Backups',
            ],
        ],

        'discord' => ['webhook_url' => ''],
        'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
    ],

    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'scghf'),
            'disks' => [env('BACKUP_DISK', 'backups')],
            'health_checks' => [
                MaximumAgeInDays::class => 2,
                MaximumStorageInMegabytes::class => 2000,
            ],
        ],
    ],

    /*
     * How much history to keep.
     *
     * Fourteen daily archives, then weekly for a couple of months. See note 3
     * above: the constraint is the account's inode quota, not disk space, and
     * the restore anybody actually performs is from the last day or two.
     */
    'cleanup' => [

        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 3,
            'keep_daily_backups_for_days' => (int) env('BACKUP_KEEP_DAILY', 14),
            'keep_weekly_backups_for_weeks' => (int) env('BACKUP_KEEP_WEEKLY', 8),
            'keep_monthly_backups_for_months' => (int) env('BACKUP_KEEP_MONTHLY', 6),
            'keep_yearly_backups_for_years' => 2,

            /*
             * The ceiling that actually bites on shared hosting. cPanel's
             * Backup Usage allowance is separate from disk quota and smaller
             * than people expect, and an account over it stops being backed up
             * by the HOST as well — so the two failures arrive together.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => (int) env('BACKUP_MAX_MEGABYTES', 3000),
        ],

        'tries' => 1,
        'retry_delay' => 0,
    ],
];
