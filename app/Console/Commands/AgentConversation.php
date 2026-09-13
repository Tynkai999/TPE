<?php

namespace App\Console\Commands;

use App\AI\Orchestrator\AgentOrchestrator;
use Illuminate\Console\Command;

class AgentConversation extends Command
{
    protected $signature = 'agent:conversation {--collaborator-user=}';

    protected $description = 'Ouvre une discussion interactive avec TPE Assistant';

    public function handle(AgentOrchestrator $orchestrator): int
    {
        $messages = [[
            'role' => 'system',
            'content' => 'Tu es TPE Assistant. Réponds en français, clairement et de façon concise. Tu aides les TPE en communication et marketing.',
        ]];

        $this->info('Conversation avec TPE Assistant (Qwen via LM Studio).');
        $this->line('Tapez exit ou quit pour terminer.');

        while (true) {
            $question = $this->ask('Vous');

            if ($question === null || in_array(strtolower(trim($question)), ['exit', 'quit'], true)) {
                $this->info('Conversation terminée.');

                return self::SUCCESS;
            }

            if (trim($question) === '') {
                continue;
            }

            $messages[] = ['role' => 'user', 'content' => $question];
            $answer = $orchestrator->handle(
                $question,
                $this->option('collaborator-user'),
                array_slice($messages, 1, -1),
            );
            $messages[] = ['role' => 'assistant', 'content' => $answer];

            $this->line("Agent : {$answer}");
        }
    }
}