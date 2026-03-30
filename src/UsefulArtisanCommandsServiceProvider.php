<?php

namespace EsFredDerick\UsefulArtisanCommands;

use Illuminate\Support\ServiceProvider;
use EsFredDerick\UsefulArtisanCommands\Commands\ConfigureDatabaseCommand;
use EsFredDerick\UsefulArtisanCommands\Commands\MakeActionCommand;
use EsFredDerick\UsefulArtisanCommands\Commands\MakeDataCommand;

class UsefulArtisanCommandsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigureDatabaseCommand::class,
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
