<?php

namespace EsFredDerick\UsefulArtisanCommands\Commands;

use EsFredDerick\UsefulArtisanCommands\Services\SchemaDefaultsConfigurationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

#[AsCommand(name: 'schemas:config-defaults')]
class ConfigureSchemaDefaultsCommand extends Command
{
    protected $signature = 'schemas:config-defaults
        {--c|clean-env : Remove managed schema table env keys from local .env files after saving config-file defaults}';

    protected $description = 'Configure PostgreSQL schema-qualified defaults for Laravel framework tables';

    public function __construct(
        private readonly SchemaDefaultsConfigurationService $schemaDefaults,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        intro('PostgreSQL Schema Defaults');

        note('Configure the schema-qualified tables Laravel uses for migrations, queues, cache, sessions, and password reset tokens.');
        warning('Prefer running this command when starting a new project, before migration history matters. It rewrites starter migrations so existing migration history should stay untouched.');

        $configurationScope = select(
            label: 'What do you want to configure?',
            options: [
                'schemas' => 'Schema names only',
                'tables' => 'Schema and table names',
            ],
            default: 'schemas',
            hint: 'Choose table names too when your project renames Laravel default tables.',
        );

        $configureTables = $configurationScope === 'tables';

        $targetChoice = select(
            label: 'Where should the changes be applied?',
            options: [
                'env' => 'Env file',
                'config' => 'Config files',
            ],
            default: 'env',
            hint: 'Env files keep config portable. Config files bake the values into the starter kit defaults.',
        );
        $target = $targetChoice === 'config' ? 'Config files' : 'Env file';
        $hasTeamMigrations = $this->schemaDefaults->hasTeamStarterMigrations($this->basePath());

        $usersInAuthenticationSchema = select(
            label: 'Should the users table live in the authentication schema too?',
            options: [
                'yes' => 'Yes, keep users with authentication tables',
                'no' => 'No, configure users separately',
            ],
            default: 'no',
            hint: 'Yes makes users share the same schema as sessions and password reset tokens.',
        ) === 'yes';

        $envFile = $target === 'Env file' ? $this->selectEnvFile() : '.env';
        $currentTables = $this->currentQualifiedTables($envFile, $target, $hasTeamMigrations);
        $teamsInUsersSchema = true;

        if ($hasTeamMigrations) {
            $teamsInUsersSchema = select(
                label: 'Where should the team tables live?',
                options: [
                    'users' => 'Same schema as users',
                    'separate' => 'Separate team schema',
                ],
                default: $this->defaultTeamSchemaPlacement($currentTables),
                hint: 'Same follows the final users schema, including when users share the authentication schema.',
            ) === 'users';
        }

        $schemaNames = [];

        foreach ($this->schemaDefaults->schemaGroups() as $key => $group) {
            $schemaNames[$key] = $this->askIdentifier(
                label: $group['label'],
                default: $this->defaultSchemaForGroup($key, $currentTables),
                hint: 'Use the schema name only. The command will compose schema.table for you.',
            );
        }

        if (! $usersInAuthenticationSchema) {
            $schemaNames['users'] = $this->askIdentifier(
                label: 'Users table schema name',
                default: $this->schemaDefaults->schemaNameFromQualifiedTable(
                    qualifiedTable: $currentTables['users'],
                    default: $this->schemaDefaults->userTableSetting()['default_schema'],
                ),
                hint: 'This schema controls where the User model table lives.',
            );
        }

        if ($hasTeamMigrations && ! $teamsInUsersSchema) {
            $schemaNames['teams'] = $this->askIdentifier(
                label: $this->schemaDefaults->teamTableSchemaSetting()['label'],
                default: $this->schemaDefaults->schemaNameFromQualifiedTable(
                    qualifiedTable: $currentTables['teams'],
                    default: $this->schemaDefaults->teamTableSchemaSetting()['default_schema'],
                ),
                hint: 'This schema controls teams, team members, and team invitations.',
            );
        }

        $tableNames = [];

        foreach ($this->schemaDefaults->tableSettings() as $key => $setting) {
            $default = $this->schemaDefaults->tableNameFromQualifiedTable(
                qualifiedTable: $currentTables[$key],
                default: $setting['default_table'],
            );

            $tableNames[$key] = $configureTables
                ? $this->askIdentifier($setting['label'], $default, 'Use the table name only, without the schema prefix.')
                : $default;
        }

        $tableNames['users'] = $configureTables
            ? $this->askIdentifier(
                label: $this->schemaDefaults->userTableSetting()['label'],
                default: $this->schemaDefaults->tableNameFromQualifiedTable(
                    qualifiedTable: $currentTables['users'],
                    default: $this->schemaDefaults->userTableSetting()['default_table'],
                ),
                hint: 'Use the table name only, without the schema prefix.',
            )
            : $this->schemaDefaults->tableNameFromQualifiedTable(
                qualifiedTable: $currentTables['users'],
                default: $this->schemaDefaults->userTableSetting()['default_table'],
            );

        if ($hasTeamMigrations) {
            foreach ($this->schemaDefaults->teamTableSettings() as $key => $setting) {
                $default = $this->schemaDefaults->tableNameFromQualifiedTable(
                    qualifiedTable: $currentTables[$key],
                    default: $setting['default_table'],
                );

                $tableNames[$key] = $configureTables
                    ? $this->askIdentifier($setting['label'], $default, 'Use the table name only, without the schema prefix.')
                    : $default;
            }
        }

        $qualifiedTables = [];

        foreach ($this->schemaDefaults->tableSettings() as $key => $setting) {
            $qualifiedTables[$key] = $schemaNames[$setting['schema_group']].'.'.$tableNames[$key];
        }

        $userSchema = $usersInAuthenticationSchema ? $schemaNames['authentication'] : $schemaNames['users'];
        $qualifiedTables['users'] = $userSchema.'.'.$tableNames['users'];

        if ($hasTeamMigrations) {
            $teamSchema = $teamsInUsersSchema ? $userSchema : $schemaNames['teams'];

            foreach ($this->schemaDefaults->teamTableSettings() as $key => $setting) {
                $qualifiedTables[$key] = $teamSchema.'.'.$tableNames[$key];
            }
        }

        table(
            headers: ['Setting', 'Table'],
            rows: array_map(
                static fn (string $key, string $table): array => [$key, $table],
                array_keys($qualifiedTables),
                $qualifiedTables,
            ),
        );

        if ($target === 'Config files') {
            $this->schemaDefaults->applyToConfigFiles($this->basePath(), $qualifiedTables);

            if ($this->option('clean-env')) {
                $cleanedEnvFiles = $this->schemaDefaults->cleanEnvFiles($this->basePath());

                info($cleanedEnvFiles === []
                    ? 'No residual schema table env keys were found.'
                    : 'Cleaned residual schema table env keys from '.implode(', ', $cleanedEnvFiles).'.');
            }
        } else {
            $this->schemaDefaults->applyToEnvFile($this->basePath(), $envFile, $qualifiedTables);

            if ($this->option('clean-env')) {
                warning('The -c option is only applied when saving to config files, so the selected env file keeps its schema table keys.');
            }
        }

        $migrationSync = $this->schemaDefaults->syncDefaultMigrations($this->basePath(), $qualifiedTables);
        $userModelSync = $this->schemaDefaults->syncUserModel($this->basePath(), $qualifiedTables['users'], $this->laravelMajorVersion());

        info('Initial schemas migration will create: '.implode(', ', $migrationSync['schemas']).'.');
        info("User model synced to {$userModelSync['to']}.");

        if ($migrationSync['updated_tables'] !== []) {
            table(
                headers: ['Migration', 'Configured table'],
                rows: array_map(
                    static fn (array $table): array => [$table['file'], $table['table']],
                    $migrationSync['updated_tables'],
                ),
            );
        }

        if ($migrationSync['omitted_tables'] !== []) {
            warning('Some tables in default Laravel migrations were left unchanged because this command has no configuration for them.');

            table(
                headers: ['Migration', 'Omitted table'],
                rows: array_map(
                    static fn (array $table): array => [$table['file'], $table['table']],
                    $migrationSync['omitted_tables'],
                ),
            );
        }

        if ($migrationSync['missing_migrations'] !== []) {
            warning('Some default Laravel migration files were not found: '.implode(', ', $migrationSync['missing_migrations']).'.');
        }

        outro($target === 'Config files'
            ? 'Schema-qualified defaults saved to config files.'
            : "Schema-qualified defaults saved to {$envFile}.");

        return self::SUCCESS;
    }

    private function selectEnvFile(): string
    {
        $envFiles = $this->schemaDefaults->availableEnvFiles($this->basePath());

        if ($envFiles === []) {
            return '.env';
        }

        if (count($envFiles) === 1) {
            info("Using {$envFiles[0]}.");

            return $envFiles[0];
        }

        table(
            headers: ['Available env files'],
            rows: array_map(static fn (string $file): array => [$file], $envFiles),
        );

        $envFile = select(
            label: 'Which env file should be updated?',
            options: array_combine($envFiles, $envFiles),
            default: $envFiles[0],
            hint: '.env.example is intentionally ignored.',
        );

        return is_string($envFile) ? $envFile : $envFiles[0];
    }

    /**
     * @return array<string, string>
     */
    private function currentQualifiedTables(string $envFile, string $target, bool $includeTeamTables = false): array
    {
        $tables = [];

        foreach ($this->schemaDefaults->tableSettings() as $key => $setting) {
            $tables[$key] = $target === 'Config files'
                ? $this->schemaDefaults->currentConfigValue($this->basePath(), $key, $envFile)
                : $this->schemaDefaults->currentEnvValue(
                    basePath: $this->basePath(),
                    envFile: $envFile,
                    key: $setting['env'],
                    default: $this->schemaDefaults->defaultQualifiedTable($key),
                );
        }

        $tables['users'] = $this->schemaDefaults->currentUserQualifiedTable($this->basePath());

        if ($includeTeamTables) {
            $tables = [
                ...$tables,
                ...$this->schemaDefaults->currentTeamQualifiedTables($this->basePath()),
            ];
        }

        return $tables;
    }

    /**
     * @param  array<string, string>  $currentTables
     */
    private function defaultSchemaForGroup(string $group, array $currentTables): string
    {
        foreach ($this->schemaDefaults->tableSettings() as $key => $setting) {
            if ($setting['schema_group'] !== $group) {
                continue;
            }

            return $this->schemaDefaults->schemaNameFromQualifiedTable(
                qualifiedTable: $currentTables[$key],
                default: $this->schemaDefaults->schemaGroups()[$group]['default'],
            );
        }

        return $this->schemaDefaults->schemaGroups()[$group]['default'];
    }

    /**
     * @param  array<string, string>  $currentTables
     */
    private function defaultTeamSchemaPlacement(array $currentTables): string
    {
        $userSchema = $this->schemaDefaults->schemaNameFromQualifiedTable(
            qualifiedTable: $currentTables['users'],
            default: $this->schemaDefaults->userTableSetting()['default_schema'],
        );
        $teamSchema = $this->schemaDefaults->schemaNameFromQualifiedTable(
            qualifiedTable: $currentTables['teams'],
            default: $userSchema,
        );

        return $teamSchema === $userSchema ? 'users' : 'separate';
    }

    private function askIdentifier(string $label, string $default, string $hint = ''): string
    {
        return text(
            label: $label,
            default: $default,
            required: true,
            validate: fn (string $value): ?string => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', trim($value))
                ? null
                : 'Use an unquoted PostgreSQL identifier, such as queue or job_batches.',
            hint: $hint,
            transform: fn (string $value): string => trim($value),
        );
    }

    private function basePath(): string
    {
        return $this->laravel->basePath();
    }

    private function laravelMajorVersion(): int
    {
        return (int) strtok($this->laravel->version(), '.');
    }
}
