<?php

namespace App\AI\Tools;

class ToolExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $arguments): array
    {
        return $this->registry->get($toolName)->execute($arguments);
    }
}