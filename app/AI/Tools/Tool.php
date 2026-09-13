<?php

namespace App\AI\Tools;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array;
}