<?php

namespace App\Console\Commands;

use App\AI\Http\CollaboratorTokenManager;
use App\AI\Orchestrator\AgentOrchestrator;
use App\Models\AiCommand;
use App\Models\LinkedAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AgentInteractiveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:interactive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Démarre une session de chat interactive avec l\'assistant IA.';

    /**
     * Execute the console command.
     */
    public function handle(CollaboratorTokenManager $tokenManager, AgentOrchestrator $orchestrator): int
    {
        $this->info('Bienvenue dans l\'Assistant IA TPE Messagerie');
        $this->line('================================================');

        // 1. Authentification
        $email = $this->ask('Email collaborateur', 'deraousmane2004@example.com');
        $password = $this->secret('Mot de passe collaborateur (password123)');

        if (!$password) {
            $password = 'password123'; // fallback pour la démo
        }

        $this->info('Connexion à l\'API ...');

        try {
            $account = $tokenManager->login($email, $password);
            $userId = $account->collaborator_user_id;
            $this->info('Connexion réussie ! (ID: ' . $userId . ')');
        } catch (\Exception $e) {
            $this->error('Échec de la connexion : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->line('================================================');
        $this->info('Mode Chat Activé. Tapez "exit" ou "quit" pour quitter.');
        $this->info('Commandes spéciales: /confirm <id> | /cancel <id>');
        $this->line('');

        $conversation = [];

        // 2. Boucle Interactive
        while (true) {
            $input = $this->ask('Vous');

            if (empty($input)) {
                continue;
            }

            $lowerInput = strtolower(trim($input));
            if (in_array($lowerInput, ['exit', 'quit', '/q'])) {
                $this->info('Au revoir !');
                break;
            }

            // Gestion des raccourcis de confirmation / annulation
            if (Str::startsWith($lowerInput, '/confirm') || Str::startsWith($lowerInput, 'confirm')) {
                $commandId = trim(Str::replaceFirst('/confirm', '', $lowerInput));
                $commandId = trim(Str::replaceFirst('confirm', '', $commandId));
                
                if (empty($commandId)) {
                    $commandId = $this->ask('Quel est l\'ID de la proposition à confirmer ? (ex: 14)');
                }
                
                // Sécurité : on ne garde que les chiffres (au cas où l'utilisateur tape "/confirm 18" ou "#18")
                $commandId = preg_replace('/[^0-9]/', '', $commandId);

                if (!empty($commandId)) {
                    $this->call('agent:confirm', ['aiCommand' => $commandId, '--collaborator-user' => $userId]);
                }
                $this->line('');
                continue;
            }

            if (Str::startsWith($lowerInput, '/cancel') || Str::startsWith($lowerInput, 'cancel')) {
                $commandId = trim(Str::replaceFirst('/cancel', '', $lowerInput));
                $commandId = trim(Str::replaceFirst('cancel', '', $commandId));

                if (empty($commandId)) {
                    $commandId = $this->ask('Quel est l\'ID de la proposition à annuler ? (ex: 14)');
                }
                
                // Sécurité : on ne garde que les chiffres
                $commandId = preg_replace('/[^0-9]/', '', $commandId);

                if (!empty($commandId)) {
                    $this->call('agent:cancel', ['aiCommand' => $commandId, '--collaborator-user' => $userId]);
                }
                $this->line('');
                continue;
            }

            if (Str::startsWith($lowerInput, '/send') || Str::startsWith($lowerInput, 'send')) {
                $commandId = trim(Str::replaceFirst('/send', '', $lowerInput));
                $commandId = trim(Str::replaceFirst('send', '', $commandId));

                if (empty($commandId)) {
                    $commandId = $this->ask('Quel est l\'ID de la proposition à envoyer ? (ex: 14)');
                }
                
                // Sécurité : on ne garde que les chiffres
                $commandId = preg_replace('/[^0-9]/', '', $commandId);

                if (!empty($commandId)) {
                    $this->call('agent:send', ['aiCommand' => $commandId, '--collaborator-user' => $userId]);
                }
                $this->line('');
                continue;
            }

            // Chat avec l'IA
            try {
                $this->line('<fg=gray>Agent réfléchit...</>');
                $response = $orchestrator->handle($input, $userId, $conversation);
                
                // Mise à jour de l'historique
                $conversation[] = ['role' => 'user', 'content' => $input];
                $conversation[] = ['role' => 'assistant', 'content' => $response];
                
                // Affichage propre de la réponse
                $this->line("<fg=cyan>Agent :</> {$response}");
            } catch (\Exception $e) {
                $this->error("Erreur de l'agent : " . $e->getMessage());
            }

            $this->line(''); // Ligne vide pour aérer
        }

        return Command::SUCCESS;
    }
}

