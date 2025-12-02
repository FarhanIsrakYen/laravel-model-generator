<?php
namespace YourVendor\LaravelEntityMaker;

use Illuminate\Support\ServiceProvider;
use FarhanIsrakYen\LaravelModelMaker\Commands\MakeModelCommand.php

class ServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            MakeModelCommand::class,
        ]);
    }

    public function boot()
    {
        //
    }
}
