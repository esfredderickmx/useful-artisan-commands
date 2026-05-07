<?php

use EsFredDerick\UsefulArtisanCommands\Services\SchemaDefaultsConfigurationService;

require_once dirname(__DIR__, 2).'/src/Services/SchemaDefaultsConfigurationService.php';

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/useful-schema-defaults-'.bin2hex(random_bytes(6));

    mkdir($this->basePath.'/app/Models/Client', 0777, true);
    mkdir($this->basePath.'/config', 0777, true);
    mkdir($this->basePath.'/database/factories', 0777, true);
    mkdir($this->basePath.'/database/migrations', 0777, true);
    mkdir($this->basePath.'/database/seeders', 0777, true);

    file_put_contents($this->basePath.'/app/Models/Client/User.php', clientUserModelContents());
    file_put_contents($this->basePath.'/config/auth.php', authConfigContents());
    file_put_contents($this->basePath.'/config/cache.php', cacheConfigContents());
    file_put_contents($this->basePath.'/config/database.php', databaseConfigContents());
    file_put_contents($this->basePath.'/config/queue.php', queueConfigContents());
    file_put_contents($this->basePath.'/config/session.php', sessionConfigContents());
    file_put_contents($this->basePath.'/database/factories/UserFactory.php', clientUserFactoryContents());
    file_put_contents($this->basePath.'/database/migrations/0000_00_00_000000_create_initial_schemas.php', initialSchemasMigrationContents());
    file_put_contents($this->basePath.'/database/migrations/0001_01_01_000000_create_users_table.php', usersMigrationContents());
    file_put_contents($this->basePath.'/database/migrations/0001_01_01_000001_create_cache_table.php', cacheMigrationContents());
    file_put_contents($this->basePath.'/database/migrations/0001_01_01_000002_create_jobs_table.php', jobsMigrationContents());
    file_put_contents($this->basePath.'/database/seeders/DatabaseSeeder.php', clientDatabaseSeederContents());

    $this->service = new SchemaDefaultsConfigurationService;
});

afterEach(function () {
    $delete = function (string $path) use (&$delete): void {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $delete($path.'/'.$file);
        }

        rmdir($path);
    };

    $delete($this->basePath);
});

it('syncs schema migrations and default laravel table migrations', function () {
    $result = $this->service->syncDefaultMigrations($this->basePath, qualifiedTables());

    expect($result['schemas'])
        ->toBe(['async', 'client', 'identity', 'state'])
        ->and($result['omitted_tables'])
        ->toBe([])
        ->and(file_get_contents($this->basePath.'/database/migrations/0000_00_00_000000_create_initial_schemas.php'))
        ->toContain("DB::statement('create schema if not exists async;');")
        ->not->toContain('create schema if not exists infra;')
        ->and(file_get_contents($this->basePath.'/database/migrations/0001_01_01_000000_create_users_table.php'))
        ->toContain("Schema::create('client.application_users'")
        ->toContain("Schema::dropIfExists('identity.browser_sessions'")
        ->and(file_get_contents($this->basePath.'/database/migrations/0001_01_01_000002_create_jobs_table.php'))
        ->toContain("Schema::create('async.work_items'")
        ->toContain("Schema::dropIfExists('async.work_failures'");
});

it('syncs user model and factory with laravel 13 factory attributes', function () {
    $result = $this->service->syncUserModel($this->basePath, 'authentication.users', 13);

    expect($result['to'])
        ->toBe('app/Models/Authentication/User.php')
        ->and($result['removed_directories'])
        ->toBe(['app/Models/Client'])
        ->and(file_get_contents($this->basePath.'/app/Models/Authentication/User.php'))
        ->toContain('#[UseFactory(UserFactory::class)]')
        ->toContain("protected \$table = 'authentication.users';")
        ->not->toContain('protected static string $factory')
        ->and(file_get_contents($this->basePath.'/database/factories/UserFactory.php'))
        ->toContain('use App\Models\Authentication\User;')
        ->toContain('@extends Factory<User>')
        ->toContain('#[UseModel(User::class)]');
});

it('syncs user model and factory with laravel 12 factory methods', function () {
    $this->service->syncUserModel($this->basePath, 'authentication.users', 12);

    expect(file_get_contents($this->basePath.'/app/Models/Authentication/User.php'))
        ->toContain('protected static function newFactory(): UserFactory')
        ->toContain('return UserFactory::new();')
        ->not->toContain('#[UseFactory')
        ->and(file_get_contents($this->basePath.'/database/factories/UserFactory.php'))
        ->toContain('protected $model = User::class;')
        ->not->toContain('#[UseModel');
});

it('cleans managed env keys without touching env example', function () {
    file_put_contents($this->basePath.'/.env', "APP_NAME=Laravel\nDB_QUEUE_TABLE=queue.jobs\nSESSION_TABLE=auth.sessions\n");
    file_put_contents($this->basePath.'/.env.example', "DB_QUEUE_TABLE=queue.jobs\n");

    expect($this->service->cleanEnvFiles($this->basePath))
        ->toBe(['.env'])
        ->and(file_get_contents($this->basePath.'/.env'))
        ->toBe("APP_NAME=Laravel\n")
        ->and(file_get_contents($this->basePath.'/.env.example'))
        ->toBe("DB_QUEUE_TABLE=queue.jobs\n");
});

function qualifiedTables(): array
{
    return [
        'migrations' => 'infra.schema_migrations',
        'users' => 'client.application_users',
        'jobs' => 'async.work_items',
        'job_batches' => 'async.work_batches',
        'failed_jobs' => 'async.work_failures',
        'cache' => 'state.cache_entries',
        'cache_locks' => 'state.cache_mutexes',
        'sessions' => 'identity.browser_sessions',
        'password_reset_tokens' => 'identity.reset_tokens',
    ];
}

function clientUserModelContents(): string
{
    return <<<'PHP'
<?php

namespace App\Models\Client;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmail, Notifiable;

    protected $table = 'client.users';
}
PHP;
}

function clientUserFactoryContents(): string
{
    return <<<'PHP'
<?php

namespace Database\Factories;

use App\Models\Client\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
#[UseModel(User::class)]
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }
}
PHP;
}

function clientDatabaseSeederContents(): string
{
    return <<<'PHP'
<?php

namespace Database\Seeders;

use App\Models\Client\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create();
    }
}
PHP;
}

function authConfigContents(): string
{
    return <<<'PHP'
<?php

use App\Models\Client\User;

return [
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],
    'passwords' => [
        'users' => [
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
        ],
    ],
];
PHP;
}

function cacheConfigContents(): string
{
    return <<<'PHP'
<?php

return [
    'stores' => [
        'database' => [
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],
    ],
];
PHP;
}

function databaseConfigContents(): string
{
    return <<<'PHP'
<?php

return [
    'migrations' => [
        'table' => env('DB_MIGRATIONS_TABLE', 'migrations'),
    ],
];
PHP;
}

function queueConfigContents(): string
{
    return <<<'PHP'
<?php

return [
    'connections' => [
        'database' => [
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
        ],
    ],
    'batching' => [
        'table' => env('DB_QUEUE_BATCHING_TABLE', 'job_batches'),
    ],
    'failed' => [
        'table' => env('DB_QUEUE_FAILED_TABLE', 'failed_jobs'),
    ],
];
PHP;
}

function sessionConfigContents(): string
{
    return <<<'PHP'
<?php

return [
    'connection' => env('SESSION_CONNECTION'),
    'table' => env('SESSION_TABLE', 'sessions'),
];
PHP;
}

function initialSchemasMigrationContents(): string
{
    return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create schema if not exists queue;');
    }

    public function down(): void
    {
        DB::statement('drop schema if exists queue;');
    }
};
PHP;
}

function usersMigrationContents(): string
{
    return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client.users', function (Blueprint $table) {});
        Schema::create('authentication.password_reset_tokens', function (Blueprint $table) {});
        Schema::create('authentication.sessions', function (Blueprint $table) {});
    }

    public function down(): void
    {
        Schema::dropIfExists('client.users');
        Schema::dropIfExists('authentication.password_reset_tokens');
        Schema::dropIfExists('authentication.sessions');
    }
};
PHP;
}

function cacheMigrationContents(): string
{
    return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage.cache', function (Blueprint $table) {});
        Schema::create('storage.cache_locks', function (Blueprint $table) {});
    }

    public function down(): void
    {
        Schema::dropIfExists('storage.cache');
        Schema::dropIfExists('storage.cache_locks');
    }
};
PHP;
}

function jobsMigrationContents(): string
{
    return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue.jobs', function (Blueprint $table) {});
        Schema::create('queue.job_batches', function (Blueprint $table) {});
        Schema::create('queue.failed_jobs', function (Blueprint $table) {});
    }

    public function down(): void
    {
        Schema::dropIfExists('queue.jobs');
        Schema::dropIfExists('queue.job_batches');
        Schema::dropIfExists('queue.failed_jobs');
    }
};
PHP;
}
