<?php

namespace EsFredDerick\UsefulArtisanCommands\Commands;

use EsFredDerick\UsefulArtisanCommands\Concerns\InteractsWithEnvFile;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

#[AsCommand(name: 'app:config-db')]
class ConfigureDatabaseCommand extends Command
{
    use InteractsWithEnvFile;

    protected $name = 'app:config-db';

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

        $configuration = $this->reviewDatabaseConfiguration([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $pass,
        ]);

        $this->writeEnvValues([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $configuration['host'],
            'DB_PORT' => $configuration['port'],
            'DB_DATABASE' => $configuration['database'],
            'DB_USERNAME' => $configuration['username'],
            'DB_PASSWORD' => $configuration['password'],
        ]);

        // Ensure a blank line before DB_CONNECTION for readability.
        $envFile = $this->laravel->environmentFilePath();
        $contents = file_get_contents($envFile) ?: '';
        $contents = preg_replace("/(?<!\n)\nDB_CONNECTION=/", "\n\nDB_CONNECTION=", $contents);
        file_put_contents($envFile, $contents);

        outro("Database configuration saved. Connecting to «{$configuration['database']}»…");

        return self::SUCCESS;
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $configuration
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function reviewDatabaseConfiguration(array $configuration): array
    {
        $fieldLabels = [
            'host' => 'Host',
            'port' => 'Port',
            'database' => 'Database name',
            'username' => 'Username',
            'password' => 'Password',
        ];

        while (true) {
            $this->showDatabaseConfiguration($configuration);

            if (! confirm(
                label: 'Do you want to correct any database details before saving?',
                default: false,
            )) {
                return $configuration;
            }

            $fields = multiselect(
                label: 'Which fields do you want to correct?',
                options: $fieldLabels,
                required: true,
                hint: 'Use space to select one or more fields.',
            );

            foreach ($fields as $field) {
                $configuration[$field] = $this->askDatabaseField($field, $configuration[$field]);
            }
        }
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $configuration
     */
    private function showDatabaseConfiguration(array $configuration): void
    {
        table(
            headers: ['Field', 'Value'],
            rows: [
                ['Host', $configuration['host']],
                ['Port', $configuration['port']],
                ['Database name', $configuration['database']],
                ['Username', $configuration['username']],
                ['Password', $configuration['password'] === '' ? '(empty)' : '[hidden]'],
            ],
        );
    }

    private function askDatabaseField(string $field, string $currentValue): string
    {
        return match ($field) {
            'host' => text(
                label: 'Host',
                default: $currentValue,
                required: true,
            ),
            'port' => text(
                label: 'Port',
                default: $currentValue,
                required: true,
                validate: fn (string $value) => is_numeric($value) ? null : 'Port must be a number.',
            ),
            'database' => text(
                label: 'Database name',
                default: $currentValue,
                required: true,
            ),
            'username' => text(
                label: 'Username',
                default: $currentValue,
                required: true,
            ),
            'password' => password(
                label: 'Password',
                hint: 'Leave empty if no password is required or to clear the current password.',
            ),
            default => $currentValue,
        };
    }
}
