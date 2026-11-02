<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Streeboga\EntityGenerator\Data\GenerationResult;
use Streeboga\EntityGenerator\Services\DefinitionStore;
use Streeboga\EntityGenerator\Services\EntityGeneratorService;
use Streeboga\EntityGenerator\Services\EntitySpecFactory;

/** make:entity — точка входа: разобрать опции, позвать сервис генерации, напечатать итог. */
final class MakeEntityCommand extends Command
{
    protected $signature = 'make:entity
        {name? : Имя сущности, StudlyCase (или в --from)}
        {--preset=* : Пресет из config (повторяется)}
        {--fields= : Поля: "number:string,total:money:required,status:enum(draft,paid)"}
        {--from= : YAML-описание сущности}
        {--target= : Цель из config targets (app, пакет)}
        {--module= : Синоним --target}
        {--package= : Синоним --target (acme/billing)}
        {--domain= : Синоним --target (домен)}
        {--layout= : Раскладка слоёв: laravel, ddd, своя}
        {--owner= : none, user или свой из config owners}
        {--table= : Имя таблицы}
        {--public-id= : Префикс публичного ключа}
        {--soft-deletes : Мягкое удаление}
        {--actions= : Действия: index,show,store,update,destroy}
        {--builder : QueryBuilder}
        {--filament : Ресурс панели оператора}
        {--no-policy : Без политики}
        {--no-test : Без теста}
        {--routes : Дописать маршруты в файл}
        {--test-first : Сначала только падающий тест}
        {--dry-run : Показать, ничего не писать}
        {--force : Перезаписать существующие файлы}';

    protected $description = 'Создать сущность (миграция, модель, DTO, запросы, репозиторий, сервис, контроллер, ресурс, политика, фабрика, тест) из стабов';

    public function handle(EntitySpecFactory $specs, EntityGeneratorService $generator, DefinitionStore $definitions): int
    {
        try {
            $yaml = $this->option('from') === null ? [] : $definitions->read(base_path((string) $this->option('from')));
            $spec = $specs->make($this->argument('name'), $yaml, $this->given());
            $result = $generator->generate($spec, (bool) $this->option('dry-run'), (bool) $this->option('force'), (bool) $this->option('routes'), (bool) $this->option('test-first'));
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->report($result, (bool) $this->option('dry-run'));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function given(): array
    {
        $target = $this->option('target') ?? $this->option('module') ?? $this->option('package') ?? $this->option('domain');
        $on = static fn (bool $v, mixed $value): mixed => $v ? $value : null;

        return [
            'preset' => (array) $this->option('preset'), 'fields' => $this->option('fields'), 'target' => $target, 'layout' => $this->option('layout'),
            'owner' => $this->option('owner'), 'table' => $this->option('table'), 'public_id' => $this->option('public-id'), 'actions' => $this->option('actions'),
            'soft_deletes' => $on((bool) $this->option('soft-deletes'), true), 'builder' => $on((bool) $this->option('builder'), true),
            'filament' => $on((bool) $this->option('filament'), true), 'policy' => $on((bool) $this->option('no-policy'), false), 'test' => $on((bool) $this->option('no-test'), false),
        ];
    }

    private function report(GenerationResult $r, bool $dry): void
    {
        $this->line($dry ? 'Пробный запуск, ничего не записано:' : 'Готово:');
        foreach ($r->created as $f) {
            $this->line(($dry ? '  будет создан  ' : '  создан        ').$f);
        }
        foreach ($r->updated as $f) {
            $this->line('  обновлён      '.$f);
        }
        foreach ($r->skipped as $f) {
            $this->line('  уже есть      '.$f.' (--force перезапишет)');
        }
        foreach ($r->warnings as $w) {
            $this->warn($w);
        }
        if ($r->routes !== null) {
            $this->line('');
            $this->line($r->routes);
        }
        if ($r->manual !== []) {
            $this->line('Осталось вручную:');
            foreach ($r->manual as $m) {
                $this->line('  - '.$m);
            }
        }
    }
}
