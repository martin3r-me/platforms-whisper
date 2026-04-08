<?php

namespace Platform\Whisper;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class WhisperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whisper.php', 'whisper');
    }

    public function boot(): void
    {
        // Morph-Map-Alias für Organization-Verknüpfungen
        Relation::morphMap([
            'whisper_recording' => \Platform\Whisper\Models\WhisperRecording::class,
        ]);

        if (
            config()->has('whisper.routing') &&
            config()->has('whisper.navigation') &&
            Schema::hasTable('modules')
        ) {
            PlatformCore::registerModule([
                'key'        => 'whisper',
                'title'      => 'Whisper',
                'routing'    => config('whisper.routing'),
                'guard'      => config('whisper.guard'),
                'navigation' => config('whisper.navigation'),
                'sidebar'    => config('whisper.sidebar'),
            ]);
        }

        if (PlatformCore::getModule('whisper')) {
            ModuleRouter::group('whisper', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/whisper.php' => config_path('whisper.php'),
        ], 'config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'whisper');

        $this->registerLivewireComponents();
        $this->registerTools();
    }

    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            $registry->register(new \Platform\Whisper\Tools\WhisperOverviewTool());
            $registry->register(new \Platform\Whisper\Tools\ListRecordingsTool());
            $registry->register(new \Platform\Whisper\Tools\GetRecordingTool());
            $registry->register(new \Platform\Whisper\Tools\UpdateRecordingTool());
            $registry->register(new \Platform\Whisper\Tools\DeleteRecordingTool());
            $registry->register(new \Platform\Whisper\Tools\SearchRecordingsTool());
            $registry->register(new \Platform\Whisper\Tools\GetTranscriptTool());
            $registry->register(resolve(\Platform\Whisper\Tools\AskRecordingQuestionTool::class));
        } catch (\Throwable $e) {
            \Log::warning('Whisper: Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\Whisper\\Livewire';
        $prefix = 'whisper';

        if (!is_dir($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            if (!class_exists($class)) {
                continue;
            }

            $aliasPath = str_replace(['\\', '/'], '.', Str::kebab(str_replace('.php', '', $relativePath)));
            $alias = $prefix . '.' . $aliasPath;

            Livewire::component($alias, $class);
        }
    }
}
