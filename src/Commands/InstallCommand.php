<?php

namespace LatidoFlow\Laravel\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'latidoflow:install
        {--force : Overwrite an existing LatidoFlow configuration file}';

    protected $description = 'Publish LatidoFlow configuration and show scheduler setup instructions';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $configurationAlreadyExists = is_file(config_path('latidoflow.php'));
        $arguments = [
            '--tag' => 'latidoflow-config',
        ];

        if ($force) {
            $arguments['--force'] = true;
        }

        if ($this->call('vendor:publish', $arguments) !== self::SUCCESS) {
            $this->components->error('LatidoFlow configuration could not be published.');

            return self::FAILURE;
        }

        if ($configurationAlreadyExists && ! $force) {
            $this->components->warn('Existing LatidoFlow configuration was left unchanged. Use --force to replace it.');
        } else {
            $this->components->info('LatidoFlow config published.');
        }
        $this->line('Add this to routes/console.php or your scheduler bootstrap:');
        $this->line('use Illuminate\Support\Facades\Schedule;');
        $this->line("Schedule::command('latidoflow:sync')->hourly()->name('LatidoFlow definition sync');");
        $this->line('Then run: php artisan latidoflow:verify');

        return self::SUCCESS;
    }
}
