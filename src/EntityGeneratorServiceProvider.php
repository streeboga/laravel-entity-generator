<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator;

use Illuminate\Support\ServiceProvider;
use Streeboga\EntityGenerator\Console\MakeEntityCommand;
use Streeboga\EntityGenerator\Console\MakeEntityFieldCommand;
use Streeboga\EntityGenerator\Console\EntityPromptCommand;

final class EntityGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $defaults = require __DIR__.'/../config/entity-generator.php';
        // Рекурсивно: проект указывает только то, что меняет; списки (actions, middleware) заменяются целиком.
        $this->app['config']->set('entity-generator', $this->merge($defaults, (array) $this->app['config']->get('entity-generator', [])));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->commands([MakeEntityCommand::class, MakeEntityFieldCommand::class, EntityPromptCommand::class]);
        $this->publishes([__DIR__.'/../config/entity-generator.php' => config_path('entity-generator.php')], 'entity-generator-config');
        $this->publishes([__DIR__.'/../stubs' => base_path('stubs/entity')], 'entity-generator-stubs');
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value) ? $this->merge($base[$key], $value) : $value;
        }

        return $base;
    }
}
