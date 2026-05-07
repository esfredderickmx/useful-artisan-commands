<?php

namespace EsFredDerick\UsefulArtisanCommands;

use EsFredDerick\UsefulArtisanCommands\Commands\ConfigureDatabaseCommand;
use EsFredDerick\UsefulArtisanCommands\Commands\ConfigureSchemaDefaultsCommand;
use EsFredDerick\UsefulArtisanCommands\Commands\MakeActionCommand;
use EsFredDerick\UsefulArtisanCommands\Commands\MakeDataCommand;
use Illuminate\Support\ServiceProvider;

class UsefulArtisanCommandsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigureDatabaseCommand::class,
                ConfigureSchemaDefaultsCommand::class,
                MakeActionCommand::class,
                MakeDataCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/stubs/action.stub' => $this->app->basePath('stubs/action.stub'),
                __DIR__.'/stubs/data.stub' => $this->app->basePath('stubs/data.stub'),
            ], 'useful-artisan-commands-stubs');
        }
    }
}
