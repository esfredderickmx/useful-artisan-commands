<?php

namespace EsFredDerick\UsefulArtisanCommands\Commands;

use EsFredDerick\UsefulArtisanCommands\Concerns\InteractsWithEnvFile;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[AsCommand(name: 'app:configure-database')]
class ConfigureDatabaseCommand extends Command
{
    use InteractsWithEnvFile;

    protected $name = 'app:configure-database';

    protected $description = 'Configure the PostgreSQL database connection';

    public function handle(): int
    {
        intro('PostgreSQL Database Setup');

        note('Enter your PostgreSQL connection details. Press Enter to accept the defaults.');

        $host = text(
            label: 'Host',
            default: $this->currentEnvValue('DB_HOST', '127.0.0.1'),
            required: true,
        );

        $port = text(
            label: 'Port',
            default: $this->currentEnvValue('DB_PORT', '5432'),
            required: true,
            validate: fn (string $value) => is_numeric($value) ? null : 'Port must be a number.',
        );

        $database = text(
            label: 'Database name',
            default: $this->currentEnvValue('DB_DATABASE', 'laravel'),
            required: true,
        );

        $username = text(
            label: 'Username',
            default: $this->currentEnvValue('DB_USERNAME', 'postgres'),
            required: true,
        );

        $pass = password(
            label: 'Password',
            hint: 'Leave empty if no password is required.',
        );

        $migrationsTable = text(
            label: 'Migrations table',
            default: $this->currentEnvValue('DB_MIGRATIONS_TABLE', 'migrations'),
            required: true,
            hint: 'Use schema.table notation to store migrations in a specific schema (e.g. database.migrations).',
        );

        $this->writeEnvValues([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $pass,
            'DB_MIGRATIONS_TABLE' => $migrationsTable,
        ]);

        // Ensure a blank line before DB_CONNECTION for readability.
        $envFile = $this->laravel->environmentFilePath();
        $contents = file_get_contents($envFile) ?: '';
        $contents = preg_replace("/(?<!\n)\nDB_CONNECTION=/", "\n\nDB_CONNECTION=", $contents);
        file_put_contents($envFile, $contents);

        outro("Database configuration saved. Connecting to «{$database}»…");

        return self::SUCCESS;
    }
}
