<?php

namespace App\Console\Commands;

use App\Models\AiCommand;
use Illuminate\Console\Command;
use LogicException;

class CancelAiCommand extends Command
{
    protected $signature = 'agent:cancel
                            {aiCommand : Identifiant de la proposition}
                            {--collaborator-user= : Identifiant du compte collaborateur}';

    protected $description = 'Annule une proposition de campagne';

    public function handle(): int
    {
        $command = AiCommand::query()
            ->whereKey($this->argument('aiCommand'))
            ->where('collaborator_user_id', $this->option('collaborator-user'))
            ->first();

        if (!$command) {
            $this->error('Proposition introuvable ou non autorisée.');

            return self::FAILURE;
        }

        try {
            $command->cancel();
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Proposition #{$command->id} annulée.");

        return self::SUCCESS;
    }
}