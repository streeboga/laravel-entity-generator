<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Data;

/** Итог запуска: что создано, что пропущено (уже есть), предупреждения и что осталось вручную. */
final class GenerationResult
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $skipped = [];

    /** @var list<string> */
    public array $updated = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $manual = [];

    /** Фрагмент маршрутов, если его не дописали в файл. */
    public ?string $routes = null;
}
