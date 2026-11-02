<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

/** Имя файла миграции: уже есть с любой меткой времени — берём его (повтор не плодит копии). */
final readonly class MigrationNamer
{
    public function __construct(private Filesystem $files) {}

    /** Метка не раньше последней миграции той же таблицы: порядок create → add → rls держится и при быстрых запусках. */
    public function resolve(string $pattern, Carbon $stamp, string $table = ''): string
    {
        $found = $this->files->glob(base_path(str_replace('{TS}', '*', $pattern)));
        if ($found !== []) {
            return ltrim(str_replace(base_path(), '', $found[0]), '/');
        }
        if ($table !== '') {
            foreach ($this->files->glob(base_path(dirname($pattern)).'/*'.$table.'*.php') as $file) {
                $at = Carbon::createFromFormat('Y_m_d_His', substr(basename($file), 0, 17));
                $at !== false && $at->gte($stamp) && $stamp = $at->copy()->addSecond();
            }
        }

        return str_replace('{TS}', $stamp->format('Y_m_d_His'), $pattern);
    }

    public function exists(string $pattern): bool
    {
        return $this->files->glob(base_path(str_replace('{TS}', '*', $pattern))) !== [];
    }
}
