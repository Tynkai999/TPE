<?php

namespace App\Console\Commands;

use App\AI\Tools\CreateCampaignTool;
use App\Models\AiCommand;
use Illuminate\Console\Command;
use LogicException;

class ConfirmAiCommand extends Command
{
    protected $signature = 'agent:confirm
                            {aiCommand : Identifiant de la proposition}
                            {--collaborator-user= : Identifiant du compte collaborateur}';

    protected $description = 'Confirme une proposition de campagne sans déclencher encore son envoi';

    public function handle(CreateCampaignTool $campaignTool): int
    {
        $userId = $this->option('collaborator-user');
        $command = AiCommand::query()
            ->whereKey($this->argument('aiCommand'))
            ->where('collaborator_user_id', $userId)
            ->first();

        if (!$command) {
            $this->error('Proposition introuvable ou non autorisée.');

            return self::FAILURE;
        }

        if ($command->status !== 'PROPOSED') {
            $this->error('Seule une proposition PROPOSED peut être confirmée.');

            return self::FAILURE;
        }

        try {
            $parameters = $command->parameters;
            $campaign = $campaignTool->execute([
                'collaborator_user_id' => $command->collaborator_user_id,
                'contact_ids'          => $parameters['contact_ids'] ?? [],
                'name'                 => 'Campagne IA #' . $command->id,
                'content'              => $parameters['message'] ?? '',
                'message_channel'      => $parameters['message_channel'] ?? 'sms',
                // Reprise idempotente : réutilise le groupe déjà créé si la campagne avait échoué.
                'existing_group_id'    => $parameters['group_id'] ?? null,
            ]);

            $command->update([
                'campaign_id' => $campaign['campaign']['id'],
                'parameters' => array_merge($parameters, [
                    'group_id' => $campaign['group_id'],
                ]),
            ]);
            $command->confirm();
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Création de la campagne impossible : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Proposition #{$command->id} confirmée.");
        $this->line("Brouillon campagne créé : {$command->campaign_id}. Aucun envoi n'est déclenché.");

        return self::SUCCESS;
    }
}