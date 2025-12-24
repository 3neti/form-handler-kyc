<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC\Console;

use Illuminate\Console\Command;

/**
 * Install KYC Handler Command
 * 
 * Installs required UI dependencies and publishes assets.
 */
class InstallKycHandlerCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kyc-handler:install {--force : Overwrite existing files}';

    /**
     * The console command description.
     */
    protected $description = 'Install KYC handler UI dependencies and assets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Installing KYC Handler...');
        
        // Publish Vue components
        $this->publishAssets();
        
        $this->newLine();
        $this->info('✓ KYC Handler installed successfully!');
        $this->line('  Run "npm run build" to compile frontend assets.');
        
        return self::SUCCESS;
    }
    
    /**
     * Publish package assets
     */
    protected function publishAssets(): void
    {
        $this->line('  • Publishing Vue components...');
        
        $this->call('vendor:publish', [
            '--tag' => 'kyc-handler-stubs',
            '--force' => $this->option('force'),
        ]);
    }
}
