<?php

namespace App\Console\Commands;

use App\AI\Orchestrator\AgentOrchestrator;
use Illuminate\Console\Command;

class AgentChat extends Command
{
    protected $signature = 'agent:chat {message} {--collaborator-user=}';
    protected $description = "Envoie un message a l'agent IA et affiche sa reponse";

    public function handle(AgentOrchestrator $orchestrator): int
    {
        try {
            $this->line('Agent : ' . $orchestrator->handle(
                $this->argument('message'),
                $this->option('collaborator-user'),
            ));
        } catch (\App\AI\Exceptions\LLMUnavailableException $e) {
            $this->line("Agent : Le serveur d'IA local n'est pas joignable (LM Studio éteint ou inaccessible).");
        }

        return self::SUCCESS;
    }
}