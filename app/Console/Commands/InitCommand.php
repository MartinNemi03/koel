<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AskForPassword;
use App\Exceptions\InstallationFailedException;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Jackiedo\DotenvEditor\DotenvEditor;
use Throwable;

class InitCommand extends Command
{
    use AskForPassword;

    private const NON_INTERACTION_MAX_DATABASE_ATTEMPT_COUNT = 10;

    protected $signature =
        'koel:init {--no-assets : Do not compile front-end assets} {--no-scheduler : Do not install scheduler}';
    protected $description = 'Install or upgrade Koel';

    private bool $adminSeeded = false;

    public function __construct(private readonly DotenvEditor $dotenvEditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->alert('KOEL INSTALLATION WIZARD');

        $this->components->info(
            'Remember, you can always install/upgrade manually using the guide at '
            . config('koel.misc.docs_url')
        );

        if ($this->inNoInteractionMode()) {
            $this->components->info('Running in no-interaction mode');
        }

        try {
            $this->clearCaches();
            $this->loadEnvFile();
            $this->maybeGenerateAppKey();
            $this->maybeSetUpDatabase();
            $this->migrateDatabase();
            $this->maybeSeedDatabase();
            $this->maybeSetMediaPath();
            $this->maybeCompileFrontEndAssets();
            $this->maybeCopyManifests();
            $this->dotenvEditor->save();
            $this->tryInstallingScheduler();
        } catch (Throwable $e) {
            Log::error($e);

            $this->components->error("Oops! Koel installation or upgrade didn't finish successfully.");
            $this->components->error('Please check the error log at storage/logs/laravel.log and try again.');
            $this->components->error('For further troubleshooting, visit https://docs.koel.dev/troubleshooting.');
            $this->components->error('😥 Sorry for this. You deserve better.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->success('All done!');

        if (app()->environment('local')) {
            $this->components->info('🏗️ Koel can now be run from localhost with `php artisan serve`');
        } else {
            $this->components->info('🌟 A shiny Koel is now available at ' . config('app.url'));
        }

        if ($this->adminSeeded) {
            $this->components->info(
                sprintf('🧑‍💻 Log in with email %s and password %s', User::FIRST_ADMIN_EMAIL, User::FIRST_ADMIN_PASSWORD)
            );
        }

        if (!Setting::get('media_path')) {
            $this->components->info('📀 You can set up the storage with `php artisan koel:storage`');
        }

        $this->components->info('🛟 Documentation can be found at ' . config('koel.misc.docs_url'));
        $this->components->info('🤗 Consider supporting Koel’s development: ' . config('koel.misc.sponsor_github_url'));
        $this->components->info('🤘 Finally, thanks for using Koel. You rock!');

        return self::SUCCESS;
    }

    private function clearCaches(): void
    {
        $this->components->task('Clearing caches', static function (): void {
            Artisan::call('config:clear', ['--quiet' => true]);
            Artisan::call('cache:clear', ['--quiet' => true]);
        });
    }

    private function loadEnvFile(): void
    {
        if (!File::exists(base_path('.env'))) {
            $this->components->task('Copying .env file', static function (): void {
                File::copy(base_path('.env.example'), base_path('.env'));
            });
        } else {
            $this->components->task('.env file exists -- skipping');
        }

        $this->dotenvEditor->load(base_path('.env'));
    }

    private function maybeGenerateAppKey(): void
    {
        $key = $this->laravel['config']['app.key'];

        $this->components->task($key ? 'Retrieving app key' : 'Generating app key', function () use (&$key): void {
            if (!$key) {
                // Generate the key manually to prevent some clashes with `php artisan key:generate`
                $key = $this->generateRandomKey();
                $this->dotenvEditor->setKey('APP_KEY', $key);
                $this->laravel['config']['app.key'] = $key;
            }
        });

        $this->components->task('Using app key: ' . Str::limit($key, 16));
    }

    /**
     * Prompt user for valid database credentials and set up the database.
     */
    private function setUpDatabase(): void
    {
        $config = [
            'DB_HOST' => '',
            'DB_PORT' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
        ];

        $config['DB_CONNECTION'] = $this->choice(
            'Your DB driver of choice',
            [
                'mysql' => 'MySQL/MariaDB',
                'pgsql' => 'PostgreSQL',
                'sqlsrv' => 'SQL Server',
                'sqlite-e2e' => 'SQLite',
            ],
            'mysql'
        );

        if ($config['DB_CONNECTION'] === 'sqlite-e2e') {
            $config['DB_DATABASE'] = $this->ask('Absolute path to the DB file');
        } else {
            $config['DB_HOST'] = $this->anticipate('DB host', ['127.0.0.1', 'localhost']);
            $config['DB_PORT'] = (string) $this->ask('DB port (leave empty for default)');
            $config['DB_DATABASE'] = $this->anticipate('DB name', ['koel']);
            $config['DB_USERNAME'] = $this->anticipate('DB user', ['koel']);
            $config['DB_PASSWORD'] = (string) $this->ask('DB password');
        }

        $this->dotenvEditor->setKeys($config);
        $this->dotenvEditor->save();

        // Set the config so that the next DB attempt uses refreshed credentials
        config([
            'database.default' => $config['DB_CONNECTION'],
            "database.connections.{$config['DB_CONNECTION']}.host" => $config['DB_HOST'],
            "database.connections.{$config['DB_CONNECTION']}.port" => $config['DB_PORT'],
            "database.connections.{$config['DB_CONNECTION']}.database" => $config['DB_DATABASE'],
            "database.connections.{$config['DB_CONNECTION']}.username" => $config['DB_USERNAME'],
            "database.connections.{$config['DB_CONNECTION']}.password" => $config['DB_PASSWORD'],
        ]);
    }

    private function inNoInteractionMode(): bool
    {
        return (bool) $this->option('no-interaction');
    }

    private function inNoAssetsMode(): bool
    {
        return (bool) $this->option('no-assets');
    }

    private function setUpAdminAccount(): void
    {
        $this->components->task('Creating default admin account', function (): void {
            User::firstAdmin();
            $this->adminSeeded = true;
        });
    }

    private function maybeSeedDatabase(): void
    {
        if (!User::query()->count()) {
            $this->setUpAdminAccount();

            $this->components->task('Seeding data', static function (): void {
                Artisan::call('db:seed', ['--force' => true, '--quiet' => true]);
            });
        } else {
            $this->components->task('Data already seeded -- skipping');
        }
    }

    private function maybeSetUpDatabase(): void
    {
        $attempt = 0;

        while (true) {
            // In non-interactive mode, we must not endlessly attempt to connect.
            // Doing so will just end up with a huge amount of "failed to connect" logs.
            // We do retry a little, though, just in case there's some kind of temporary failure.
            if ($attempt >= self::NON_INTERACTION_MAX_DATABASE_ATTEMPT_COUNT && $this->inNoInteractionMode()) {
                $this->components->error('Maximum database connection attempts reached. Giving up.');
                break;
            }

            $attempt++;

            try {
                // Make sure the config cache is cleared before another attempt.
                Artisan::call('config:clear', ['--quiet' => true]);
                DB::reconnect();
                Schema::getTables();

                break;
            } catch (Throwable $e) {
                Log::error($e);

                // We only try to update credentials if running in interactive mode.
                // Otherwise, we require admin intervention to fix them.
                // This avoids inadvertently wiping credentials if there's a connection failure.
                if ($this->inNoInteractionMode()) {
                    $warning = sprintf(
                        "Cannot connect to the database. Attempt: %d/%d",
                        $attempt,
                        self::NON_INTERACTION_MAX_DATABASE_ATTEMPT_COUNT
                    );

                    $this->components->warn($warning);
                } else {
                    $this->components->warn("Cannot connect to the database. Let's set it up.");
                    $this->setUpDatabase();
                }
            }
        }
    }

    private function migrateDatabase(): void
    {
        $this->components->task('Migrating database', static function (): void {
            Artisan::call('migrate', ['--force' => true, '--quiet' => true]);
        });
    }

    private function maybeSetMediaPath(): void
    {
        if (Setting::get('media_path')) {
            return;
        }

        if ($this->inNoInteractionMode()) {
            $this->setMediaPathFromEnvFile();

            return;
        }

        $this->newLine();
        $this->info('The absolute path to your media directory. You can leave it blank and set it later via the web interface.'); // @phpcs-ignore-line
        $this->info('If you plan to use Koel with a cloud provider (S3 or Dropbox), you can also skip this.');

        while (true) {
            $path = $this->ask('Media path', config('koel.media_path'));

            if (!$path) {
                return;
            }

            if (self::isValidMediaPath($path)) {
                Setting::set('media_path', $path);

                return;
            }

            $this->components->error('The path does not exist or not readable. Try again?');
        }
    }

    private function maybeCompileFrontEndAssets(): void
    {
        if ($this->inNoAssetsMode()) {
            return;
        }

        $this->components->task('Installing npm dependencies', function (): void {
            $this->runOkOrThrow('pnpm install --color');
        });

        $this->components->task('Compiling frontend assets', function (): void {
            $this->runOkOrThrow('pnpm run --color build');
        });
    }

    private function runOkOrThrow(string $command): void
    {
        $printer = $this->option('verbose')
            ? static fn (string $type, string $output) => print $output
            : null;

        throw_unless(Process::forever()->run($command, $printer)->successful(), InstallationFailedException::class);
    }

    private function setMediaPathFromEnvFile(): void
    {
        $path = config('koel.media_path');

        if (!$path) {
            return;
        }

        if (self::isValidMediaPath($path)) {
            Setting::set('media_path', $path);
        } else {
            $this->components->warn(sprintf('The path %s does not exist or not readable. Skipping.', $path));
        }
    }

    private static function isValidMediaPath(string $path): bool
    {
        return File::isDirectory($path) && File::isReadable($path);
    }

    private function generateRandomKey(): string
    {
        return 'base64:' . base64_encode(Encrypter::generateKey($this->laravel['config']['app.cipher']));
    }

    private function tryInstallingScheduler(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Unknown') {
            return;
        }

        if ((bool) $this->option('no-scheduler')) {
            return;
        }

        $result = 0;

        $this->components->task('Installing Koel scheduler', static function () use (&$result): void {
            $result = Artisan::call('koel:scheduler:install', ['--quiet' => true]);
        });

        if ($result !== self::SUCCESS) {
            $this->components->warn(
                'Failed to install scheduler. ' .
                'Please install manually: https://docs.koel.dev/cli-commands#command-scheduling'
            );
        }
    }

    private function maybeCopyManifests(): void
    {
        foreach (['manifest.json', 'manifest-remote.json'] as $file) {
            $destination = public_path($file);
            $source = public_path("$file.example");

            if (File::exists($destination)) {
                $this->components->task("$file already exists -- skipping");
                continue;
            }

            $this->components->task("Copying $file", static function () use ($source, $destination): void {
                File::copy($source, $destination);
            });
        }
    }
}
