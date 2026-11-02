<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Data;

/** Файл к записи: слой, путь от корня проекта, содержимое, цель (для проверки импортов). */
final readonly class GeneratedFile
{
    public function __construct(
        public string $layer,
        public string $path,
        public string $content,
        public string $target,
    ) {}
}
