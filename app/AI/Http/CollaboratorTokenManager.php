<?php

namespace App\AI\Http;

use App\Models\LinkedAccount;
use Carbon\Carbon;
use RuntimeException;

class CollaboratorTokenManager
{
    public function __construct(
        private readonly CollaboratorApiClient $client,
    ) {}

    /**
     * Connecte un utilisateur au système collaborateur et sauvegarde ses tokens.
     * Retourne le LinkedAccount créé/mis à jour.
     */
    public function login(string $email, string $password): LinkedAccount
    {
        $response = $this->client->post('/auth/login', [
            'email'    => $email,
            'password' => $password,
        ]);

        $data = $response['data'] ?? $response;

        if (!$data || !isset($data['access_token'], $data['user']['id'])) {
            throw new RuntimeException(
                'Connexion échouée ou 2FA requise (vérifier la réponse : ' . json_encode($response) . ')'
            );
        }

        return LinkedAccount::updateOrCreate(
            ['collaborator_user_id' => $data['user']['id']],
            [
                'collaborator_email' => $email,
                'access_token'        => $data['access_token'],
                'refresh_token'       => $data['refresh_token'],
                'expires_at'          => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
            ],
        );
    }

    /**
     * Retourne un access_token valide pour cet utilisateur, en le rafraîchissant
     * automatiquement si nécessaire. C'est la méthode que les Tools utiliseront.
     */
    public function getValidAccessToken(string $collaboratorUserId): string
    {
        $account = LinkedAccount::where('collaborator_user_id', $collaboratorUserId)->first();

        if (!$account) {
            throw new RuntimeException("Aucun compte lié pour l'utilisateur {$collaboratorUserId}. Connexion requise.");
        }

        // Marge de sécurité de 60s pour éviter d'utiliser un token expiré pile au moment de l'appel
        if ($account->expires_at->subSeconds(60)->isFuture()) {
            return $account->access_token;
        }

        return $this->refresh($account);
    }

    private function refresh(LinkedAccount $account): string
    {
        $response = $this->client->post('/auth/refresh', [
            'refresh_token' => $account->refresh_token,
        ]);

        $data = $response['data'] ?? $response;

        if (!isset($data['access_token'])) {
            throw new RuntimeException("Échec du rafraîchissement du token pour {$account->collaborator_user_id}.");
        }

        $account->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
            'expires_at'    => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $account->access_token;
    }
}