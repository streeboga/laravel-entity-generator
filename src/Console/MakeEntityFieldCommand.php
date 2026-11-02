<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Streeboga\EntityGenerator\Services\DefinitionStore;
use Streeboga\EntityGenerator\Services\EntitySpecFactory;
use Streeboga\EntityGenerator\Services\FieldChangeService;

/** make:entity-field — поле меняется одной командой: миграция плюс вставка по якорям. Вывод короткий. */
final class MakeEntityFieldCommand extends Command
{
    protected $signature = 'make:entity-field
        {name : Имя сущности}
        {--add= : Новое поле "due_at:date" (тип, модификаторы)}
        {--rename= : "старое:новое"}
        {--drop= : Имя поля}
        {--target= : Цель (как у make:entity)}
        {--module= : Синоним --target}
        {--package= : Синоним --target}
        {--domain= : Синоним --target}
        {--dry-run : Показать, ничего не писать}';

    protected $description = 'Добавить, переименовать или удалить поле сущности по её описанию (entities/Имя.yaml)';

    public function handle(EntitySpecFactory $specs, DefinitionStore $definitions, FieldChangeService $changes): int
    {
        try {
            $name = (string) $this->argument('name');
            $target = $this->option('target') ?? $this->option('module') ?? $this->option('package') ?? $this->option('domain') ?? config('entity-generator.defaults.target');
            $spec = $specs->make($name, $definitions->read($definitions->path((string) $target, $name)), ['target' => $target]);
            $dry = (bool) $this->option('dry-run');
            $result = match (true) {
                $this->option('add') !== null => $changes->add($spec, $specs->parser()->parse((string) $this->option('add')), $dry),
                $this->option('drop') !== null => $changes->drop($spec, (string) $this->option('drop'), $dry),
                $this->option('rename') !== null => $this->rename($changes, $spec, $dry),
                default => throw new InvalidArgumentException('Нужна одна из опций: --add, --rename, --drop.'),
            };
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ([...$result->created, ...$result->updated] as $f) {
            $this->line(($dry ? 'будет: ' : 'ок: ').$f);
        }
        foreach ($result->skipped as $f) {
            $this->line('есть: '.$f);
        }
        foreach ($result->manual as $m) {
            $this->line('вручную: '.$m);
        }

        return self::SUCCESS;
    }

    private function rename(FieldChangeService $changes, \Streeboga\EntityGenerator\Data\EntitySpec $spec, bool $dry): \Streeboga\EntityGenerator\Data\GenerationResult
    {
        [$from, $to] = array_pad(explode(':', (string) $this->option('rename'), 2), 2, '');
        if ($from === '' || preg_match('/^[a-z][a-z0-9_]*$/', $to) !== 1) {
            throw new InvalidArgumentException('--rename=старое:новое, имена в snake_case.');
        }

        return $changes->rename($spec, $from, $to, $dry);
    }
}
