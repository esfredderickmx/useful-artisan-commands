<?php

namespace EsFredDerick\UsefulArtisanCommands\Concerns;

trait InteractsWithEnvFile
{
    /**
     * Read the current value of an env key from the .env file.
     */
    protected function currentEnvValue(string $key, string $default = ''): string
    {
        $contents = file_get_contents($this->laravel->environmentFilePath()) ?: '';

        if (preg_match("/^#?\s*{$key}=(.*)$/m", $contents, $matches)) {
            return trim($matches[1], '"\'') ?: $default;
        }

        return $default;
    }

    /**
     * Write multiple env values in a single pass to avoid
     * re-reading stale content between writes.
     *
     * @param  array<string, string>  $values
     */
    protected function writeEnvValues(array $values): void
    {
        $envFile = $this->laravel->environmentFilePath();
        $contents = file_get_contents($envFile) ?: '';

        foreach ($values as $key => $value) {
            $contents = $this->writeEnvValue($contents, $key, $value);
        }

        file_put_contents($envFile, $contents);
    }

    /**
     * Write a single key=value pair into env file contents.
     * Replaces active or commented-out lines, or appends if not found.
     */
    protected function writeEnvValue(string $contents, string $key, string $value): string
    {
        $safeValue = preg_match('/\s/', $value) ? '"'.$value.'"' : $value;
        $line = "{$key}={$safeValue}";

        $contents = preg_replace("/^#?\s*{$key}=.*$/m", $line, $contents, 1, $count);

        if ($count === 0 || $contents === null) {
            $contents = rtrim($contents)."\n{$line}\n";
        }

        return $contents;
    }
}
