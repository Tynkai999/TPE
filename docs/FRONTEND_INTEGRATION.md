# Contrat d'intégration frontend

## Base

En développement, l'application est disponible sur :

```text
http://localhost:8080
```

Les routes web utilisent l'authentification Laravel par session et le cookie CSRF. Les appels `fetch` doivent envoyer :

```http
Accept: application/json
Content-Type: application/json
X-CSRF-TOKEN: <token de la page>
```

## Résumé des endpoints

| Méthode | Route | Auth | Description |
|---|---|---|---|
| POST | `/register` | invité | Création de compte Laravel |
| POST | `/login` | invité | Connexion Laravel |
| POST | `/logout` | connecté | Déconnexion |
| POST | `/collaborator-account` | connecté | Liaison du compte à l'API collaborateur |
| POST | `/chat` | connecté | Envoi d'un message à l'agent IA |
| POST | `/ai-commands/{id}/confirm` | connecté | Confirme une proposition de campagne |
| POST | `/ai-commands/{id}/cancel` | connecté | Annule une proposition |
| POST | `/ai-commands/{id}/send` | connecté | Envoie une campagne confirmée |

Toutes ces routes sont limitées en fréquence (`throttle`) : 10 requêtes/minute pour l'authentification et la liaison de compte, 20/minute pour les actions sur une proposition, 30/minute pour `/chat`. Une réponse `429` signifie que la limite a été atteinte ; le frontend doit afficher un message d'attente plutôt que de relancer immédiatement.

## Authentification locale

```http
POST /register
POST /login
POST /logout
```

Le frontend doit rediriger l'utilisateur vers `/login` lorsqu'il reçoit une réponse `401` sur une route protégée.

## Lier le compte API collaborateur

Un utilisateur Laravel doit d'abord relier son compte à son compte de la plateforme collaborateur :

```http
POST /collaborator-account
```

Body :

```json
{
  "email": "utilisateur@example.com",
  "password": "mot-de-passe"
}
```

Réponse `201` :

```json
{
  "message": "Compte collaborateur lié.",
  "account": {
    "collaborator_user_id": "uuid",
    "email": "utilisateur@example.com"
  }
}
```

Le mot de passe est utilisé côté backend pour obtenir les tokens. Les tokens ne sont jamais retournés au frontend.

Une activation 2FA du compte collaborateur doit être traitée séparément avant la liaison, car la liaison actuelle attend une connexion sans challenge 2FA.

## Envoyer un message à l'agent

```http
POST /chat
```

Body pour une nouvelle conversation :

```json
{
  "message": "Liste mes contacts"
}
```

Body pour continuer une conversation :

```json
{
  "conversation_id": 12,
  "message": "Trouve ceux qui sont inactifs depuis 30 jours"
}
```

Réponse :

```json
{
  "conversation_id": 12,
  "answer": "J'ai trouvé 1 contacts dans votre compte collaborateur."
}
```

La conversation et les messages sont enregistrés côté Laravel. Le `collaborator_user_id` vient de la liaison du compte authentifié, jamais d'un champ envoyé par le frontend.

Exemples de demandes :

```text
Liste mes contacts
Trouve mes clients inactifs depuis 30 jours
Quels clients n'ont plus commandé depuis environ 30 jours ?
Envoie une promotion de 15% à mes clients inactifs depuis 30 jours
```

## Workflow campagne

Une demande de campagne crée une `AiCommand` avec le statut `PROPOSED`. L'agent ne l'envoie pas automatiquement.

### Confirmer

```http
POST /ai-commands/{id}/confirm
```

Réponse :

```json
{
  "message": "Proposition confirmée. La campagne est encore en brouillon.",
  "status": "CONFIRMED",
  "campaign_id": "uuid-campagne"
}
```

Cette action appelle l'API collaborateur :

1. `POST /groups` ;
2. `POST /groups/{group}/contacts` ;
3. `POST /campaigns` avec `name`, `content`, `message_channel` et `group_id`.

Canaux acceptés :

```text
sms
email
whatsapp
```

### Annuler

```http
POST /ai-commands/{id}/cancel
```

Réponse :

```json
{
  "message": "Proposition annulée.",
  "status": "CANCELLED"
}
```

### Envoyer après confirmation

```http
POST /ai-commands/{id}/send
```

Réponse :

```json
{
  "message": "Campagne envoyée.",
  "status": "EXECUTED"
}
```

Cette action appelle uniquement :

```http
POST /campaigns/{campaign}/send
```

Le frontend doit toujours afficher un écran de confirmation avant cet appel. Une commande ne peut pas être envoyée si elle n'est pas `CONFIRMED` et si elle ne possède pas de `campaign_id`.

## Statuts

```text
PROPOSED  -> CONFIRMED -> EXECUTED
PROPOSED  -> CANCELLED
```

## Erreurs principales

```text
401 : utilisateur Laravel non connecté
404 : commande inexistante ou appartenant à un autre compte
409 : transition de statut invalide
422 : validation ou erreur de l'API collaborateur
429 : trop de requêtes (throttle)
```

## Limitations actuelles à connaître côté frontend

- **Le modèle IA tourne actuellement en local** (LM Studio sur la machine de développement du backend), pas sur un serveur dédié. Les réponses de `/chat` peuvent donc être plus lentes qu'en production et le endpoint peut renvoyer une erreur 500 si le LLM local n'est pas démarré. Prévoir un état de chargement et un message d'erreur générique, sans dépendre d'un temps de réponse fixe.
- **Aucun endpoint d'analyse/statistiques n'est disponible pour l'instant** : l'API collaborateur n'expose pas encore de route analytics/résultats de campagne exploitable par l'agent. Une demande utilisateur du type « analyse les résultats de ma campagne » sera traitée comme une question générale par le LLM, sans données réelles. Ne pas construire d'écran de statistiques tant que ce point n'est pas confirmé avec le backend collaborateur.
- Les contacts en opt-out sont désormais automatiquement exclus par le backend lors de la création d'une campagne ; le frontend n'a rien à gérer de ce côté.

## Vérification locale

```bash
docker compose exec app php artisan test
```

Tests du contrat frontend :

```bash
docker compose exec app php artisan test --filter=FrontendCampaignApiTest
```

Test réel en lecture seule de l'API collaborateur :

```bash
docker compose exec app php artisan tinker --execute="\$manager = app(\App\AI\Http\CollaboratorTokenManager::class); \$account = \$manager->login(config('collaborator.test_email'), config('collaborator.test_password')); \$token = \$manager->getValidAccessToken(\$account->collaborator_user_id); \$contacts = app(\App\AI\Http\CollaboratorApiClient::class)->get('/contacts', accessToken: \$token); echo 'Contacts récupérés : ' . count(\$contacts['data']['data'] ?? \$contacts['data'] ?? \$contacts) . PHP_EOL;"
```

Aucun test automatique ne déclenche un envoi réel. Pour un test réel, vérifier manuellement le canal, le groupe et les destinataires avant d'appeler `/send`.
