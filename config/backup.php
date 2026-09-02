<?php

use App\Support\Backup\MinimumBackupSizeInKilobytes;
use App\Support\Backup\OpsBackupNotifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
 | Retention values are env-tunable. A blank env value ("KEY=") must fall back to
 | the shipped default, NOT silently become 0 — env()'s own default only covers an
 | ABSENT key, and (int) '' is 0. So guard with is_numeric() (a real "0" still wins,
 | e.g. to skip a tier). Invoked inline to produce plain ints, so `config:cache`
 | still serializes the resolved array cleanly (no closure is stored as a value).
 */
$backupIntEnv = static fn (string $key, int $default): int => is_numeric($v = env($key, $default)) ? (int) $v : $default;

// Size cap in MB: a POSITIVE int is the ceiling; 0 / blank / non-numeric = null =
// unlimited. 0 must NOT mean a literal 0-MB cap (that would delete every backup but
// the newest on the next backup:clean) — treat it as "no cap", the intuitive reading.
$backupMaxStorageMb = (is_numeric($mb = env('BACKUP_MAX_STORAGE_MB', 5000)) && (int) $mb > 0) ? (int) $mb : null;

return [

    'backup' => [
        /*
         * The name of this application. You can use this name to monitor
         * the backups.
         */
        'name' => env('APP_NAME', 'laravel-backup'),

        'source' => [
            'files' => [
                /*
                 * The list of directories and files that will be included in the backup.
                 */
                'include' => [
                    base_path(),
                    // storage_path(),  // Include if you use zero downtime deployments and don't follow symlinks
                ],

                /*
                 * These directories and files will be excluded from the backup.
                 *
                 * Directories used by the backup process will automatically be excluded.
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('framework'),
                    // Pre-update DB rollback snapshots (ekdosi:db-snapshot): plaintext
                    // full-DB dumps that include plaintext-at-rest secrets. NEVER let
                    // them get swept into a file backup (recursive bloat + a second
                    // copy of every secret in the archive).
                    storage_path('app/db-snapshots'),
                    // Operator export artifacts (company:export bundles + CSV exports):
                    // plaintext tenant data — possibly raw-secret bundles. Same reasoning;
                    // they're downloaded + deleted, not part of the backup set.
                    storage_path('app/exports'),
                ],

                /*
                 * Determines if symlinks should be followed.
                 */
                'follow_links' => false,

                /*
                 * Determines if it should avoid unreadable folders.
                 */
                'ignore_unreadable_directories' => false,

                /*
                 * This path is used to make directories in resulting zip-file relative
                 * Set to `null` to include complete absolute path
                 * Example: base_path()
                 */
                'relative_path' => null,
            ],

            /*
             * The names of the connections to the databases that should be backed up
             * MySQL, PostgreSQL, SQLite and Mongo databases are supported.
             *
             * The content of the database dump may be customized for each connection
             * by adding a 'dump' key to the connection settings in config/database.php.
             * E.g.
             * 'mysql' => [
             *       ...
             *      'dump' => [
             *           'exclude_tables' => [
             *                'table_to_exclude_from_backup',
             *                'another_table_to_exclude'
             *            ]
             *       ],
             * ],
             *
             * If you are using only InnoDB tables on a MySQL server, you can
             * also supply the useSingleTransaction option to avoid table locking.
             *
             * E.g.
             * 'mysql' => [
             *       ...
             *      'dump' => [
             *           'useSingleTransaction' => true,
             *       ],
             * ],
             *
             * For a complete list of available customization options, see https://github.com/spatie/db-dumper
             */
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        /*
         * The database dump can be compressed to decrease disk space usage.
         *
         * Out of the box Laravel-backup supplies
         * Spatie\DbDumper\Compressors\GzipCompressor::class.
         *
         * You can also create custom compressor. More info on that here:
         * https://github.com/spatie/db-dumper#using-compression
         *
         * If you do not want any compressor at all, set it to null.
         */
        'database_dump_compressor' => null,

        /*
         * If specified, the database dumped file name will contain a timestamp (e.g.: 'Y-m-d-H-i-s').
         */
        'database_dump_file_timestamp_format' => null,

        /*
         * The base of the dump filename, either 'database' or 'connection'
         *
         * If 'database' (default), the dumped filename will contain the database name.
         * If 'connection', the dumped filename will contain the connection name.
         */
        'database_dump_filename_base' => 'database',

        /*
         * The file extension used for the database dump files.
         *
         * If not specified, the file extension will be .archive for MongoDB and .sql for all other databases
         * The file extension should be specified without a leading .
         */
        'database_dump_file_extension' => '',

        'destination' => [
            /*
             * The compression algorithm to be used for creating the zip archive.
             *
             * If backing up only database, you may choose gzip compression for db dump and no compression at zip.
             *
             * Some common algorithms are listed below:
             * ZipArchive::CM_STORE (no compression at all; set 0 as compression level)
             * ZipArchive::CM_DEFAULT
             * ZipArchive::CM_DEFLATE
             * ZipArchive::CM_BZIP2
             * ZipArchive::CM_XZ
             *
             * For more check https://www.php.net/manual/zip.constants.php and confirm it's supported by your system.
             */
            'compression_method' => ZipArchive::CM_DEFAULT,

            /*
             * The compression level corresponding to the used algorithm; an integer between 0 and 9.
             *
             * Check supported levels for the chosen algorithm, usually 1 means the fastest and weakest compression,
             * while 9 the slowest and strongest one.
             *
             * Setting of 0 for some algorithms may switch to the strongest compression.
             */
            'compression_level' => 9,

            /*
             * The filename prefix used for the backup zip file.
             */
            'filename_prefix' => '',

            /*
             * The disk names on which the backups will be stored.
             *
             * AUDIT OPS-1: a local-only copy dies with the VM — production MUST
             * add an off-site disk (e.g. BACKUP_DESTINATION_DISKS="local,s3"
             * with the s3 disk configured in filesystems.php, or a dedicated
             * sftp disk). Comma-separated env so provisioning is one line;
             * default stays 'local' for dev/CI.
             */
            'disks' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('BACKUP_DESTINATION_DISKS', 'local')),
            ))),

            /*
             * Determines whether to allow backups to continue when some targets fail instead of failing completely.
             */
            'continue_on_failure' => false,
        ],

        /*
         * The directory where the temporary files will be stored.
         */
        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * The password to be used for archive encryption.
         * Set to `null` to disable encryption.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        /*
         * The encryption algorithm to be used for archive encryption.
         * Set to 'none' to disable encryption.
         *
         * Supported: 'none', 'default', 'aes128', 'aes192', 'aes256'
         *
         * When set to 'default', we'll use AES-256 if available on your system.
         */
        'encryption' => 'default',

        /*
         * After creating the zip, verify it can be opened and contains files.
         * Recommended for critical backups but adds a small overhead.
         */
        'verify_backup' => false,

        /*
         * The number of attempts, in case the backup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new backup if the previous try failed
         * Set to `0` for none
         */
        'retry_delay' => 0,
    ],

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail' and 'slack'.
     * For Slack you need to install laravel/slack-notification-channel.
     *
     * You can also use your own notification classes, just make sure the class is named after one of
     * the `Spatie\Backup\Notifications\Notifications` classes.
     */
    'notifications' => [
        /*
         * AUDIT OPS-2: failures stay LOUD (mail), successes stay quiet — a
         * nightly "backup ok" mail trains everyone to ignore the inbox, and
         * `backup:monitor` (UnhealthyBackupWasFound) covers the "did it keep
         * working" question.
         */
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        /*
         * AUDIT OPS-2: route to the real ops recipients (the same chain the
         * per-company backup alerts use: «Ρυθμίσεις συστήματος» override →
         * EKDOSI_BACKUP_ALERT_EMAIL → super_admin users) instead of the
         * static placeholder below.
         */
        'notifiable' => OpsBackupNotifiable::class,

        'mail' => [
            // Last-resort fallback ONLY (see OpsBackupNotifiable) — spatie
            // validates this eagerly as an email, so it must keep a valid
            // shape even though real routing happens in the notifiable.
            'to' => 'your@example.com',

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',

            /*
             * If this is set to null the default channel of the webhook will be used.
             */
            'channel' => null,

            'username' => null,

            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',

            /*
             * If this is an empty string, the name field on the webhook will be used.
             */
            'username' => '',

            /*
             * If this is an empty string, the avatar on the webhook will be used.
             */
            'avatar_url' => '',
        ],

        /*
         * A generic webhook channel that POSTs JSON to a URL.
         * Useful for Mattermost, Microsoft Teams, or custom integrations.
         */
        'webhook' => [
            'url' => '',
        ],
    ],

    /*
     * The log channel used for backup activity messages.
     *
     * Set to a channel name defined in config/logging.php to use that channel.
     * Set to false to disable backup logging entirely.
     * Set to null to use the default log channel.
     */
    'log_channel' => null,

    /*
     * Here you can specify which backups should be monitored.
     * If a backup does not meet the specified requirements the
     * UnHealthyBackupWasFound event will be fired.
     */
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'laravel-backup'),
            'disks' => ['local'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
                // Flag an empty/near-empty dump (e.g. the 9.7 KB one a wiped DB
                // produces) as unhealthy instead of letting it pass as "OK".
                MinimumBackupSizeInKilobytes::class => 100,
            ],
        ],

        /*
        [
            'name' => 'name of the second app',
            'disks' => ['local', 's3'],
            'health_checks' => [
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => 5000,
            ],
        ],
        */
    ],

    'cleanup' => [
        /*
         * The strategy that will be used to cleanup old backups. The default strategy
         * will keep all backups for a certain amount of days. After that period only
         * a daily backup will be kept. After that period only weekly backups will
         * be kept and so on.
         *
         * No matter how you configure it the default strategy will never
         * delete the newest backup.
         */
        'strategy' => DefaultStrategy::class,

        /*
         * Retention policy (env-tunable — the crons are already env-driven, so
         * the sizing that goes with them is too). The tiers are CUMULATIVE: each
         * one starts where the previous ended, and anything older than the last
         * non-zero tier is deleted. The newest backup is NEVER deleted. Applied
         * when `backup:clean` runs (default 02:30). Set a tier to 0 to skip it.
         *
         * Each tier's window is ADDITIVE to the ones before it (spatie builds the
         * daily Period as [now-(keep_all+keep_daily) .. now-keep_all]). Shipped
         * default = "light + a few months of history": keep EVERYTHING for 7 days
         * → then a FURTHER 30 days at one-per-day → (no weekly tier) → then 6 months
         * at one-per-month → nothing older. Total horizon ≈ 7 days + 30 days + 6
         * months ≈ 7 months — much lighter than the old 2-year default, which let
         * `private/<app>/` grow unbounded (a legal invoicing DB whose dumps carry
         * the full mydata_marks XML).
         *
         * For an even leaner "7 days + a month of dailies" box, drop the monthly
         * tier: BACKUP_KEEP_MONTHLY_MONTHS=0 (env, no code change).
         */
        'default_strategy' => [
            // Keep EVERY backup from the last N days (no thinning).
            'keep_all_backups_for_days' => $backupIntEnv('BACKUP_KEEP_ALL_DAYS', 7),

            // Then, for a FURTHER N days beyond that, keep the most recent per day.
            'keep_daily_backups_for_days' => $backupIntEnv('BACKUP_KEEP_DAILY_DAYS', 30),

            // Then, for a further N weeks, keep the most recent per week (0 = skip).
            'keep_weekly_backups_for_weeks' => $backupIntEnv('BACKUP_KEEP_WEEKLY_WEEKS', 0),

            // Then, for a further N months, keep the most recent per month.
            'keep_monthly_backups_for_months' => $backupIntEnv('BACKUP_KEEP_MONTHLY_MONTHS', 6),

            // Then, for a further N years, keep the most recent per year (0 = skip).
            'keep_yearly_backups_for_years' => $backupIntEnv('BACKUP_KEEP_YEARLY_YEARS', 0),

            // Hard size ceiling AFTER tiered cleanup (MB). null = unlimited — see
            // $backupMaxStorageMb above for the 0/blank handling.
            'delete_oldest_backups_when_using_more_megabytes_than' => $backupMaxStorageMb,
        ],

        /*
         * The number of attempts, in case the cleanup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new cleanup if the previous try failed
         * Set to `0` for none
         */
        'retry_delay' => 0,
    ],

];
