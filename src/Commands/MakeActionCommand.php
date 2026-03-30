<?php

namespace EsFredDerick\UsefulArtisanCommands\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:action')]
class MakeActionCommand extends GeneratorCommand
{
    protected $name = 'make:action';

    protected $description = 'Create a new action class';

    protected $type = 'Action';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/action.stub');
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

        if (! str_ends_with($name, 'Action')) {
            $name .= 'Action';
        }

        return $name;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Actions';
    }

    public function handle(): ?bool
    {
        if (parent::handle() === false) {
            return false;
        }

        if ($this->option('data')) {
            $dataName = Str::beforeLast($this->getNameInput(), 'Action');

            $this->call('make:data', [
                'name' => $dataName,
                '--force' => $this->option('force'),
            ]);
        }

        return null;
    }

    protected function getOptions(): array
    {
        return [
            ['data', 'd', InputOption::VALUE_NONE, 'Create a new data transfer object class for the action'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the action even if the action already exists'],
        ];
    }
}
