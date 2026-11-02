<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Streeboga\EntityGenerator\Data\EntitySpec;
use Symfony\Component\Yaml\Yaml;

/** YAML-описание сущности лежит в цели (`targets.<цель>.definitions`) и обновляется командами. */
final readonly class DefinitionStore
{
    public function __construct(private Filesystem $files, private FieldParser $parser) {}

    public function path(string $target, string $name): string
    {
        $dir = (string) config("entity-generator.targets.{$target}.definitions", 'entities');

        return base_path($dir.'/'.$name.'.yaml');
    }

    /** @return array<string, mixed> */
    public function read(string $path): array
    {
        if (! $this->files->exists($path)) {
            throw new RuntimeException("Описание не найдено: {$path}");
        }
        $data = Yaml::parse($this->files->get($path));

        return is_array($data) ? $data : throw new RuntimeException("Описание {$path} не разобрано.");
    }

    public function write(EntitySpec $spec): string
    {
        $path = $this->path($spec->target, $spec->name);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, Yaml::dump($this->toArray($spec), 4, 2));

        return $path;
    }

    /** @return array<string, mixed> */
    public function toArray(EntitySpec $spec): array
    {
        $fields = [];
        foreach ($spec->fields as $field) {
            $fields[$field->name] = $this->parser->format($field);
        }

        return array_filter([
            'name' => $spec->name,
            'table' => $spec->table,
            'target' => $spec->target,
            'layout' => $spec->layout,
            'owner' => $spec->owner,
            'id_type' => $spec->idType,
            'public_id' => $spec->publicId,
            'soft_deletes' => $spec->softDeletes,
            'policy' => $spec->policy,
            'test' => $spec->test,
            'builder' => $spec->builder,
            'filament' => $spec->filament,
            'actions' => $spec->actions,
            'permissions' => $spec->permissions === [] ? null : $spec->permissions,
            'fields' => $fields,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
