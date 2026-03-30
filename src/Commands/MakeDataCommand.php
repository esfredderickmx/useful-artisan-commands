<?php

namespace EsFredDerick\UsefulArtisanCommands\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:data')]
class MakeDataCommand extends GeneratorCommand
{
    protected $name = 'make:data';

    protected $description = 'Create a new data transfer object class';

    protected $type = 'Data';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/data.stub');
    }

    protected function resolveStubPath(string $stub): string
    {
        $customPath = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($customPath)
            ? $customPath
            : __DIR__.'/../'.ltrim($stub, '/');
    }

    protected function getNameInput(): string
    {
        $name = trim($this->argument('name'));

        if (! str_ends_with($name, 'Data')) {
            $name .= 'Data';
        }

        return $name;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Data';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the data class even if the class already exists'],
        ];
    }
}
