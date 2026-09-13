<?php

namespace App\Console\Commands;

use App\AI\Tools\SendCampaignTool;
use App\Models\AiCommand;
use Illuminate\Console\Command;

class SendAiCampaign extends Command
{
    protected $signature = 'agent:send
                            {aiCommand : Identifiant de la proposition confirmée}
                            {--collaborator-user= : Identifiant du compte collaborateur}';

    protected $description = 'Envoie une campagne confirmée';

    public function handle(SendCampaignTool $sendCampaign): int
    {
        $command = AiCommand::query()
            ->whereKey($this->argument('aiCommand'))
            ->where('collaborator_user_id', $this->option('collaborator-user'))
            ->first();

        if (!$command) {
            $this->error('Proposition introuvable ou non autorisée.');

            return self::FAILURE;
        }

        if ($command->status !== 'CONFIRMED' || !$command->campaign_id) {
            $this->error('La proposition doit être confirmée et posséder un brouillon campagne.');

            return self::FAILURE;
        }

        try {
            $sendCampaign->execute([
                'collaborator_user_id' => $command->collaborator_user_id,
                'campaign_id' => $command->campaign_id,
            ]);
        } catch (\Throwable $exception) {
            $this->error("Envoi de la campagne impossible : {$exception->getMessage()}");

            return self::FAILURE;
        }

        $command->update([
            'status' => 'EXECUTED',
            'executed_at' => now(),
        ]);

        $this->info("Campagne {$command->campaign_id} envoyée.");

        return self::SUCCESS;
    }
}
