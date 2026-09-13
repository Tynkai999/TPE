<?php

namespace App\AI\Tools;

use InvalidArgumentException;

class ToolRegistry
{
    /**
     * @var array<string, Tool>
     */
    private array $tools = [];

    /**
     * @param iterable<Tool> $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): Tool
    {
        return $this->tools[$name]
            ?? throw new InvalidArgumentException("Tool inconnu : {$name}");
    }

    /**
     * @return array<string, Tool>
     */
    public function all(): array
    {
        return $this->tools;
    }
}