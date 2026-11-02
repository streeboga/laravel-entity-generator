<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Console;

use Illuminate\Console\Command;
use Streeboga\EntityGenerator\Services\DefinitionStore;
use Streeboga\EntityGenerator\Services\EntitySpecFactory;
use Streeboga\EntityGenerator\Services\PromptBuilder;

/** entity:prompt — Markdown-описание сущности для агента фронтенда (только чтение описания). */
final class EntityPromptCommand extends Command
{
    protected $signature = 'entity:prompt {name : Имя сущности} {--target= : Цель} {--module=} {--package=} {--domain=}';

    protected $description = 'Напечатать Markdown с полями, валидацией, действиями, маршрутами и форматами запроса и ответа';

    public function handle(EntitySpecFactory $specs, DefinitionStore $definitions, PromptBuilder $prompt): int
    {
        $name = (string) $this->argument('name');
        $target = $this->option('target') ?? $this->option('module') ?? $this->option('package') ?? $this->option('domain') ?? config('entity-generator.defaults.target');
        try {
            $spec = $specs->make($name, $definitions->read($definitions->path((string) $target, $name)), ['target' => $target]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line($prompt->build($spec));

        return self::SUCCESS;
    }
}
