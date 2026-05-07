<?php

namespace EsFredDerick\UsefulArtisanCommands\Services;

use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use RuntimeException;

class SchemaDefaultsConfigurationService
{
    /**
     * @return array<string, array{label: string, default: string}>
     */
    public function schemaGroups(): array
    {
        return [
            'migrations' => [
                'label' => 'Migrations table schema name',
                'default' => 'database',
            ],
            'queue' => [
                'label' => 'Jobs, job batches, and failed jobs tables schema name',
                'default' => 'queue',
            ],
            'storage' => [
                'label' => 'Cache and cache locks tables schema name',
                'default' => 'storage',
            ],
            'authentication' => [
                'label' => 'Sessions and password reset tokens tables schema name',
                'default' => 'authentication',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, env: string, schema_group: string, default_table: string, config_file: string, anchor: string, config_key: string}>
     */
    public function tableSettings(): array
    {
        return [
            'migrations' => [
                'label' => 'Migrations table name',
                'env' => 'DB_MIGRATIONS_TABLE',
                'schema_group' => 'migrations',
                'default_table' => 'migrations',
                'config_file' => 'database.php',
                'anchor' => "'migrations' => [",
                'config_key' => 'table',
            ],
            'jobs' => [
                'label' => 'Jobs table name',
                'env' => 'DB_QUEUE_TABLE',
                'schema_group' => 'queue',
                'default_table' => 'jobs',
                'config_file' => 'queue.php',
                'anchor' => "'database' => [",
                'config_key' => 'table',
            ],
            'job_batches' => [
                'label' => 'Job batches table name',
                'env' => 'DB_QUEUE_BATCHING_TABLE',
                'schema_group' => 'queue',
                'default_table' => 'job_batches',
                'config_file' => 'queue.php',
                'anchor' => "'batching' => [",
                'config_key' => 'table',
            ],
            'failed_jobs' => [
                'label' => 'Failed jobs table name',
                'env' => 'DB_QUEUE_FAILED_TABLE',
                'schema_group' => 'queue',
                'default_table' => 'failed_jobs',
                'config_file' => 'queue.php',
                'anchor' => "'failed' => [",
                'config_key' => 'table',
            ],
            'cache' => [
                'label' => 'Cache table name',
                'env' => 'DB_CACHE_TABLE',
                'schema_group' => 'storage',
                'default_table' => 'cache',
                'config_file' => 'cache.php',
                'anchor' => "'database' => [",
                'config_key' => 'table',
            ],
            'cache_locks' => [
                'label' => 'Cache locks table name',
                'env' => 'DB_CACHE_LOCK_TABLE',
                'schema_group' => 'storage',
                'default_table' => 'cache_locks',
                'config_file' => 'cache.php',
                'anchor' => "'database' => [",
                'config_key' => 'lock_table',
            ],
            'sessions' => [
                'label' => 'Sessions table name',
                'env' => 'SESSION_TABLE',
                'schema_group' => 'authentication',
                'default_table' => 'sessions',
                'config_file' => 'session.php',
                'anchor' => "'connection' => env('SESSION_CONNECTION'),",
                'config_key' => 'table',
            ],
            'password_reset_tokens' => [
                'label' => 'Password reset tokens table name',
                'env' => 'AUTH_PASSWORD_RESET_TOKEN_TABLE',
                'schema_group' => 'authentication',
                'default_table' => 'password_reset_tokens',
                'config_file' => 'auth.php',
                'anchor' => "'passwords' => [",
                'config_key' => 'table',
            ],
        ];
    }

    /**
     * @return array{label: string, default_schema: string, default_table: string}
     */
    public function userTableSetting(): array
    {
        return [
            'label' => 'Users table name',
            'default_schema' => 'client',
            'default_table' => 'users',
        ];
    }

    /**
     * @return array<string, array{default_table: string}>
     */
    public function managedMigrationTableSettings(): array
    {
        return [
            'users' => ['default_table' => $this->userTableSetting()['default_table']],
            ...array_map(
                static fn (array $setting): array => ['default_table' => $setting['default_table']],
                $this->tableSettings(),
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function availableEnvFiles(string $basePath): array
    {
        $files = glob($basePath.'/.env*') ?: [];

        $envFiles = array_values(array_filter(array_map(
            static fn (string $file): string => basename($file),
            $files,
        ), static fn (string $file): bool => is_file($basePath.'/'.$file) && $file !== '.env.example'));

        usort($envFiles, static fn (string $first, string $second): int => match (true) {
            $first === '.env' => -1,
            $second === '.env' => 1,
            default => $first <=> $second,
        });

        return $envFiles;
    }

    /**
     * @return array<int, string>
     */
    public function managedEnvKeys(): array
    {
        return array_values(array_map(
            static fn (array $setting): string => $setting['env'],
            $this->tableSettings(),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function cleanEnvFiles(string $basePath): array
    {
        $cleaned = [];

        foreach ($this->availableEnvFiles($basePath) as $envFile) {
            $path = $basePath.'/'.$envFile;
            $contents = file_get_contents($path) ?: '';
            $cleanContents = $this->removeEnvValues($contents, $this->managedEnvKeys());

            if ($cleanContents === $contents) {
                continue;
            }

            file_put_contents($path, $cleanContents);

            $cleaned[] = $envFile;
        }

        return $cleaned;
    }

    /**
     * @param  array<string, string>  $qualifiedTables
     * @return array{schemas: array<int, string>, updated_tables: array<int, array{file: string, table: string}>, omitted_tables: array<int, array{file: string, table: string}>, missing_migrations: array<int, string>}
     */
    public function syncDefaultMigrations(string $basePath, array $qualifiedTables): array
    {
        $syncResult = $this->updateDefaultLaravelTableMigrations($basePath, $qualifiedTables);
        $schemas = $this->writeInitialSchemasMigration($basePath, $qualifiedTables);

        return [
            ...$syncResult,
            'schemas' => $schemas,
        ];
    }

    /**
     * @param  array<string, string>  $qualifiedTables
     */
    public function applyToConfigFiles(string $basePath, array $qualifiedTables): void
    {
        foreach ($this->tableSettings() as $key => $setting) {
            $this->replaceConfigValue(
                basePath: $basePath,
                configFile: $setting['config_file'],
                anchor: $setting['anchor'],
                configKey: $setting['config_key'],
                expression: $this->phpString($qualifiedTables[$key]),
            );
        }
    }

    /**
     * @param  array<string, string>  $qualifiedTables
     */
    public function applyToEnvFile(string $basePath, string $envFile, array $qualifiedTables): void
    {
        $this->ensureConfigEnvCompatibility($basePath);

        $envPath = $basePath.'/'.$envFile;
        $contents = is_file($envPath) ? (file_get_contents($envPath) ?: '') : '';

        foreach ($this->tableSettings() as $key => $setting) {
            $contents = $this->writeEnvValue($contents, $setting['env'], $qualifiedTables[$key]);
        }

        file_put_contents($envPath, $contents);
    }

    public function currentEnvValue(string $basePath, string $envFile, string $key, string $default): string
    {
        $envPath = $basePath.'/'.$envFile;
        $contents = is_file($envPath) ? (file_get_contents($envPath) ?: '') : '';

        if (preg_match('/^#?\s*'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches)) {
            return trim($matches[1], '"\'') ?: $default;
        }

        return $default;
    }

    public function currentConfigValue(string $basePath, string $key, string $envFile): string
    {
        $setting = $this->tableSettings()[$key];
        $line = $this->findConfigValueLine(
            basePath: $basePath,
            configFile: $setting['config_file'],
            anchor: $setting['anchor'],
            configKey: $setting['config_key'],
        );

        if (preg_match('/\''.preg_quote($setting['config_key'], '/').'\'\s*=>\s*env\(\s*\'([^\']+)\'\s*,\s*\'([^\']*)\'\s*\),/', $line, $matches)) {
            return $this->currentEnvValue($basePath, $envFile, $matches[1], $matches[2]);
        }

        if (preg_match('/\''.preg_quote($setting['config_key'], '/').'\'\s*=>\s*\'([^\']*)\',/', $line, $matches)) {
            return $matches[1];
        }

        return $this->defaultQualifiedTable($key);
    }

    public function schemaNameFromQualifiedTable(string $qualifiedTable, string $default): string
    {
        return str_contains($qualifiedTable, '.') ? str($qualifiedTable)->before('.')->toString() : $default;
    }

    public function tableNameFromQualifiedTable(string $qualifiedTable, string $default): string
    {
        return str_contains($qualifiedTable, '.') ? str($qualifiedTable)->afterLast('.')->toString() : ($qualifiedTable ?: $default);
    }

    public function defaultQualifiedTable(string $key): string
    {
        if ($key === 'users') {
            $setting = $this->userTableSetting();

            return "{$setting['default_schema']}.{$setting['default_table']}";
        }

        $setting = $this->tableSettings()[$key];
        $schema = $this->schemaGroups()[$setting['schema_group']]['default'];

        return "{$schema}.{$setting['default_table']}";
    }

    public function currentUserQualifiedTable(string $basePath): string
    {
        $modelPath = $this->currentUserModelPath($basePath);
        $contents = $modelPath === null ? '' : (file_get_contents($modelPath) ?: '');

        if (preg_match('/protected\s+\$table\s*=\s*\'([^\']+)\';/', $contents, $matches)) {
            return $matches[1];
        }

        return $this->defaultQualifiedTable('users');
    }

    /**
     * @return array{from: string|null, to: string, namespace: string, removed_directories: array<int, string>}
     */
    public function syncUserModel(string $basePath, string $qualifiedTable, ?int $laravelMajorVersion = null): array
    {
        $modelPath = $this->currentUserModelPath($basePath) ?? $basePath.'/app/Models/Client/User.php';
        $originalModelPath = is_file($modelPath) ? $modelPath : null;
        $schema = $this->schemaNameFromQualifiedTable($qualifiedTable, $this->userTableSetting()['default_schema']);
        $namespaceSegment = Str::studly($schema);
        $namespace = "App\\Models\\{$namespaceSegment}";
        $newPath = "{$basePath}/app/Models/{$namespaceSegment}/User.php";
        $contents = is_file($modelPath) ? (file_get_contents($modelPath) ?: '') : $this->defaultUserModelContents();

        $contents = preg_replace('/^namespace\s+App\\\\Models\\\\[^;]+;/m', "namespace {$namespace};", $contents) ?? $contents;
        $contents = preg_replace('/protected\s+\$table\s*=\s*\'[^\']+\';/', "protected \$table = '{$qualifiedTable}';", $contents) ?? $contents;
        $contents = $this->syncUserModelFactoryLinking($contents, $laravelMajorVersion);

        if (! is_dir(dirname($newPath))) {
            mkdir(dirname($newPath), 0777, true);
        }

        file_put_contents($newPath, $contents);

        $removedDirectories = [];

        if ($modelPath !== $newPath && is_file($modelPath)) {
            unlink($modelPath);

            $directory = dirname($modelPath);

            if ($this->isEmptyDirectory($directory)) {
                rmdir($directory);
                $removedDirectories[] = str_replace($basePath.'/', '', $directory);
            }
        }

        $this->replaceClassReferences($basePath, $namespace.'\\User', $laravelMajorVersion);

        return [
            'from' => $originalModelPath === null ? null : str_replace($basePath.'/', '', $originalModelPath),
            'to' => str_replace($basePath.'/', '', $newPath),
            'namespace' => $namespace,
            'removed_directories' => $removedDirectories,
        ];
    }

    /**
     * @param  array<string, string>  $qualifiedTables
     * @return array<int, string>
     */
    private function writeInitialSchemasMigration(string $basePath, array $qualifiedTables): array
    {
        $path = $basePath.'/database/migrations/0000_00_00_000000_create_initial_schemas.php';
        $schemas = $this->neededMigrationSchemas($basePath, $qualifiedTables);
        $upStatements = array_map(
            static fn (string $schema): string => "        DB::statement('create schema if not exists {$schema};');",
            $schemas,
        );
        $downStatements = array_map(
            static fn (string $schema): string => "        DB::statement('drop schema if exists {$schema};');",
            $schemas,
        );

        file_put_contents($path, implode("\n", [
            '<?php',
            '',
            'use Illuminate\Database\Migrations\Migration;',
            'use Illuminate\Support\Facades\DB;',
            '',
            'return new class extends Migration',
            '{',
            '    public function up(): void',
            '    {',
            ...$upStatements,
            '    }',
            '',
            '    public function down(): void',
            '    {',
            ...$downStatements,
            '    }',
            '};',
            '',
        ]));

        return $schemas;
    }

    /**
     * @param  array<string, string>  $qualifiedTables
     * @return array{updated_tables: array<int, array{file: string, table: string}>, omitted_tables: array<int, array{file: string, table: string}>, missing_migrations: array<int, string>}
     */
    private function updateDefaultLaravelTableMigrations(string $basePath, array $qualifiedTables): array
    {
        $updatedTables = [];
        $omittedTables = [];
        $missingMigrations = [];

        foreach ($this->defaultLaravelTableMigrationPatterns() as $pattern) {
            $files = glob($basePath.'/database/migrations/'.$pattern) ?: [];

            if ($files === []) {
                $missingMigrations[] = $pattern;

                continue;
            }

            foreach ($files as $path) {
                $contents = file_get_contents($path) ?: '';
                $expectedKeys = $this->expectedManagedKeysForDefaultMigration(basename($path));
                $statementIndex = 0;
                $seenOmitted = [];
                $seenUpdated = [];
                $contents = preg_replace_callback(
                    '/(Schema::(?:create|dropIfExists)\(\s*)\'([^\']+)\'/',
                    function (array $matches) use ($path, $qualifiedTables, $expectedKeys, &$statementIndex, &$seenOmitted, &$seenUpdated, &$omittedTables, &$updatedTables): string {
                        $managedKey = $this->managedKeyForMigrationTable($matches[2])
                            ?? $expectedKeys[$statementIndex % count($expectedKeys)];
                        $statementIndex++;

                        if ($managedKey === null || ! array_key_exists($managedKey, $qualifiedTables)) {
                            if (! isset($seenOmitted[$matches[2]])) {
                                $omittedTables[] = [
                                    'file' => basename($path),
                                    'table' => $matches[2],
                                ];
                                $seenOmitted[$matches[2]] = true;
                            }

                            return $matches[0];
                        }

                        $qualifiedTable = $qualifiedTables[$managedKey];

                        if (! isset($seenUpdated[$qualifiedTable])) {
                            $updatedTables[] = [
                                'file' => basename($path),
                                'table' => $qualifiedTable,
                            ];
                            $seenUpdated[$qualifiedTable] = true;
                        }

                        return "{$matches[1]}'{$qualifiedTable}'";
                    },
                    $contents,
                );

                if ($contents === null) {
                    throw new RuntimeException('Unable to update default Laravel table migration ['.basename($path).'].');
                }

                file_put_contents($path, $contents);
            }
        }

        return [
            'updated_tables' => $updatedTables,
            'omitted_tables' => $omittedTables,
            'missing_migrations' => $missingMigrations,
        ];
    }

    /**
     * @param  array<string, string>  $qualifiedTables
     * @return array<int, string>
     */
    private function neededMigrationSchemas(string $basePath, array $qualifiedTables): array
    {
        $migrationSchema = $this->schemaNameFromQualifiedTable($qualifiedTables['migrations'], '');
        $schemas = [];

        foreach ($qualifiedTables as $key => $qualifiedTable) {
            if ($key === 'migrations') {
                continue;
            }

            $schemas[] = $this->schemaNameFromQualifiedTable($qualifiedTable, '');
        }

        foreach (glob($basePath.'/database/migrations/*create_*_table.php') ?: [] as $path) {
            preg_match_all('/Schema::create\(\s*\'([^\']+)\'/', file_get_contents($path) ?: '', $matches);

            foreach ($matches[1] as $table) {
                $schemas[] = $this->schemaNameFromQualifiedTable($table, '');
            }
        }

        $schemas = array_values(array_unique(array_filter($schemas, static fn (string $schema): bool => $schema !== '' && $schema !== $migrationSchema)));

        sort($schemas);

        return $schemas;
    }

    /**
     * @return array<int, string>
     */
    private function defaultLaravelTableMigrationPatterns(): array
    {
        return [
            '*_create_users_table.php',
            '*_create_cache_table.php',
            '*_create_jobs_table.php',
        ];
    }

    private function managedKeyForMigrationTable(string $table): ?string
    {
        $tableName = $this->tableNameFromQualifiedTable($table, $table);

        foreach ($this->managedMigrationTableSettings() as $key => $setting) {
            if ($setting['default_table'] === $tableName) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<int, string|null>
     */
    private function expectedManagedKeysForDefaultMigration(string $migrationFile): array
    {
        return match (true) {
            str_ends_with($migrationFile, '_create_users_table.php') => ['users', 'password_reset_tokens', 'sessions'],
            str_ends_with($migrationFile, '_create_cache_table.php') => ['cache', 'cache_locks'],
            str_ends_with($migrationFile, '_create_jobs_table.php') => ['jobs', 'job_batches', 'failed_jobs'],
            default => [null],
        };
    }

    private function currentUserModelPath(string $basePath): ?string
    {
        $matches = glob($basePath.'/app/Models/*/User.php') ?: [];

        sort($matches);

        return $matches[0] ?? null;
    }

    private function defaultUserModelContents(): string
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

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
PHP;
    }

    private function syncUserModelFactoryLinking(string $contents, ?int $laravelMajorVersion): string
    {
        $contents = preg_replace('/^\s*protected static string \$factory = UserFactory::class;\n\n?/m', '', $contents) ?? $contents;

        if ($this->usesFactoryAttributes($laravelMajorVersion)) {
            $contents = $this->removeNewFactoryMethod($contents);
            $contents = $this->ensureUseStatement($contents, 'Illuminate\Database\Eloquent\Attributes\UseFactory');

            return $this->ensureClassAttribute($contents, 'User', 'UseFactory(UserFactory::class)');
        }

        $contents = $this->removeClassAttribute($contents, 'UseFactory');
        $contents = $this->removeUseStatement($contents, 'Illuminate\Database\Eloquent\Attributes\UseFactory');

        return $this->ensureNewFactoryMethod($contents);
    }

    private function replaceClassReferences(string $basePath, string $userClass, ?int $laravelMajorVersion): void
    {
        foreach ([
            $basePath.'/config/auth.php',
            $basePath.'/database/seeders/DatabaseSeeder.php',
            $basePath.'/database/factories/UserFactory.php',
        ] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path) ?: '';
            $contents = preg_replace_callback('/App\\\\Models\\\\[^\\\\]+\\\\User/', static fn (): string => $userClass, $contents) ?? $contents;

            if (str_ends_with($path, 'UserFactory.php')) {
                $contents = $this->syncUserFactoryReferences($contents, $userClass, $laravelMajorVersion);
            }

            file_put_contents($path, $contents);
        }
    }

    private function syncUserFactoryReferences(string $contents, string $userClass, ?int $laravelMajorVersion): string
    {
        $contents = $this->ensureUseStatement($contents, $userClass);
        $contents = preg_replace(
            '/@extends\s+\\\\Illuminate\\\\Database\\\\Eloquent\\\\Factories\\\\Factory<[^>]+>/',
            '@extends Factory<User>',
            $contents,
        ) ?? $contents;
        $contents = $this->removeFactoryModelProperty($contents);

        if ($this->usesFactoryAttributes($laravelMajorVersion)) {
            $contents = $this->ensureUseStatement($contents, 'Illuminate\Database\Eloquent\Factories\Attributes\UseModel');

            return $this->ensureClassAttribute($contents, 'UserFactory', 'UseModel(User::class)');
        }

        $contents = $this->removeClassAttribute($contents, 'UseModel');
        $contents = $this->removeUseStatement($contents, 'Illuminate\Database\Eloquent\Factories\Attributes\UseModel');

        return $this->ensureFactoryModelProperty($contents);
    }

    private function usesFactoryAttributes(?int $laravelMajorVersion): bool
    {
        return ($laravelMajorVersion ?? $this->currentLaravelMajorVersion()) >= 13;
    }

    private function currentLaravelMajorVersion(): int
    {
        if (class_exists('\Illuminate\Foundation\Application')) {
            return (int) Str::before(Application::VERSION, '.');
        }

        return 12;
    }

    private function ensureNewFactoryMethod(string $contents): string
    {
        $contents = $this->removeNewFactoryMethod($contents);
        $method = <<<'PHP'

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

PHP;

        return preg_replace(
            '/(\s+\/\*\* @use HasFactory<UserFactory> \*\/\n\s+use HasFactory, MustVerifyEmail, Notifiable;\n)/',
            '$1'.$method,
            $contents,
        ) ?? $contents;
    }

    private function removeNewFactoryMethod(string $contents): string
    {
        return preg_replace(
            '/\n\s*(?:\/\*\*.*?\*\/\s*)?protected static function newFactory\(\)(?:: [^{]+)?\s*\{\s*return [^;]+::new\(\);\s*\}\n/s',
            "\n",
            $contents,
        ) ?? $contents;
    }

    private function ensureFactoryModelProperty(string $contents): string
    {
        $property = <<<'PHP'

    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = User::class;

PHP;

        return preg_replace('/(class UserFactory extends Factory\s*\{)/', '$1'.$property, $contents, 1) ?? $contents;
    }

    private function removeFactoryModelProperty(string $contents): string
    {
        return preg_replace(
            '/\n\s*(?:\/\*\*.*?\*\/\s*)?protected \$model = [^;]+;\n/s',
            "\n",
            $contents,
        ) ?? $contents;
    }

    private function ensureUseStatement(string $contents, string $class): string
    {
        if (str_contains($contents, "use {$class};")) {
            return $contents;
        }

        if (preg_match_all('/^use [^;]+;$/m', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $lastUse = end($matches[0]);

            if ($lastUse === false) {
                return $contents;
            }

            $position = $lastUse[1] + strlen($lastUse[0]);

            return substr($contents, 0, $position)."\nuse {$class};".substr($contents, $position);
        }

        return preg_replace('/^(namespace [^;]+;\n)/m', "$1\nuse {$class};\n", $contents) ?? $contents;
    }

    private function removeUseStatement(string $contents, string $class): string
    {
        return preg_replace('/^use '.preg_quote($class, '/').";\n/m", '', $contents) ?? $contents;
    }

    private function ensureClassAttribute(string $contents, string $className, string $attribute): string
    {
        $attributePattern = preg_quote(Str::before($attribute, '('), '/');

        if (preg_match("/^#\\[{$attributePattern}\\([^\\]]+\\)\\]\nclass {$className}/m", $contents)) {
            return preg_replace(
                "/^#\\[{$attributePattern}\\([^\\]]+\\)\\]\nclass {$className}/m",
                "#[{$attribute}]\nclass {$className}",
                $contents,
            ) ?? $contents;
        }

        return preg_replace("/^class {$className}/m", "#[{$attribute}]\nclass {$className}", $contents) ?? $contents;
    }

    private function removeClassAttribute(string $contents, string $attribute): string
    {
        return preg_replace('/^#\['.preg_quote($attribute, '/').'\([^\]]+\)\]\n/m', '', $contents) ?? $contents;
    }

    private function isEmptyDirectory(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }

        return count(array_diff(scandir($directory) ?: [], ['.', '..'])) === 0;
    }

    private function ensureConfigEnvCompatibility(string $basePath): void
    {
        foreach ($this->tableSettings() as $setting) {
            $this->replaceConfigValue(
                basePath: $basePath,
                configFile: $setting['config_file'],
                anchor: $setting['anchor'],
                configKey: $setting['config_key'],
                expression: "env('{$setting['env']}', '{$setting['default_table']}')",
            );
        }
    }

    private function replaceConfigValue(
        string $basePath,
        string $configFile,
        string $anchor,
        string $configKey,
        string $expression,
    ): void {
        [$path, $lines, $index, $matches] = $this->findConfigValueLineParts($basePath, $configFile, $anchor, $configKey);

        $lines[$index] = "{$matches[1]}'{$configKey}' => {$expression},";

        file_put_contents($path, implode("\n", $lines));
    }

    private function findConfigValueLine(string $basePath, string $configFile, string $anchor, string $configKey): string
    {
        [, $lines, $index] = $this->findConfigValueLineParts($basePath, $configFile, $anchor, $configKey);

        return $lines[$index];
    }

    /**
     * @return array{0: string, 1: array<int, string>, 2: int, 3: array<int, string>}
     */
    private function findConfigValueLineParts(string $basePath, string $configFile, string $anchor, string $configKey): array
    {
        $path = $basePath.'/config/'.$configFile;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read config file [{$configFile}].");
        }

        $lines = explode("\n", $contents);
        $anchorIndex = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, $anchor)) {
                $anchorIndex = $index;

                break;
            }
        }

        if ($anchorIndex === null) {
            throw new RuntimeException("Unable to find [{$anchor}] in config file [{$configFile}].");
        }

        for ($index = $anchorIndex + 1; $index < count($lines); $index++) {
            if (! preg_match('/^(\s*)\''.preg_quote($configKey, '/').'\'\s*=>\s*.+,\s*$/', $lines[$index], $matches)) {
                continue;
            }

            return [$path, $lines, $index, $matches];
        }

        throw new RuntimeException("Unable to find [{$configKey}] after [{$anchor}] in config file [{$configFile}].");
    }

    private function writeEnvValue(string $contents, string $key, string $value): string
    {
        $safeValue = preg_match('/\s/', $value) ? '"'.$value.'"' : $value;
        $line = "{$key}={$safeValue}";

        $contents = preg_replace('/^#?\s*'.preg_quote($key, '/').'=.*$/m', $line, $contents, 1, $count);

        if ($contents === null) {
            throw new RuntimeException("Unable to update env key [{$key}].");
        }

        if ($count === 0) {
            $contents = rtrim($contents)."\n{$line}\n";
        }

        return $contents;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function removeEnvValues(string $contents, array $keys): string
    {
        foreach ($keys as $key) {
            $contents = preg_replace('/^#?\s*'.preg_quote($key, '/').'=.*(?:\R|$)/m', '', $contents) ?? $contents;
        }

        $contents = preg_replace("/\n{3,}/", "\n\n", $contents) ?? $contents;

        return $contents === '' ? '' : rtrim($contents)."\n";
    }

    private function phpString(string $value): string
    {
        return var_export($value, true);
    }
}
