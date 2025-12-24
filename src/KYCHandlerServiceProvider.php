<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC;

use Illuminate\Support\ServiceProvider;
use LBHurtado\FormHandlerKYC\Console\InstallKycHandlerCommand;

/**
 * KYC Handler Service Provider
 * 
 * Registers the KYC handler with the form flow system.
 */
class KYCHandlerServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__.'/../config/kyc-handler.php',
            'kyc-handler'
        );
        
        // Register KYCHandler as singleton
        $this->app->singleton(KYCHandler::class, function ($app) {
            return new KYCHandler();
        });
    }
    
    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallKycHandlerCommand::class,
            ]);
        }
        
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/kyc-handler.php' => config_path('kyc-handler.php'),
        ], 'kyc-handler-config');
        
        // Publish frontend assets (Vue components)
        $this->publishes([
            __DIR__.'/../stubs/resources/js/pages/form-flow/kyc' => resource_path('js/pages/form-flow/kyc'),
        ], 'kyc-handler-stubs');
        
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/kyc.php');
        
        // Auto-register handler with form-flow-manager
        $this->registerHandler();
    }
    
    /**
     * Register the KYC handler with form-flow-manager
     */
    protected function registerHandler(): void
    {
        // Get current handlers from config
        $handlers = config('form-flow.handlers', []);
        
        // Add KYC handler
        $handlers['kyc'] = KYCHandler::class;
        
        // Update config
        config(['form-flow.handlers' => $handlers]);
    }
}
