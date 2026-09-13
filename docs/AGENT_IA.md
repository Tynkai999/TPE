# Agent IA TPE Assistant

## 1. Objectif

L'application Laravel expose un agent IA pour les TPE. L'agent répond en français et doit progressivement pouvoir gérer des contacts, créer des campagnes et analyser leurs résultats via l'API collaborateur.

Le flux actuel est :

```text
Commande Artisan agent:chat
        |
        v
AgentChat
        |
        v
AgentOrchestrator
        |
        v
LLMProvider
        |
        v
LMStudioProvider -> LM Studio
```

Le flux d'accès à l'API collaborateur est séparé :

```text
CollaboratorTokenManager
        |
        v
CollaboratorApiClient -> API collaborateur
        |
        v
linked_accounts (tokens persistés)
```

## 2. Démarrage Docker

Les services utilisés sont :

- `app` : PHP/Laravel.
- `nginx` : serveur HTTP, exposé sur `http://localhost:8080`.
- `postgres` : base PostgreSQL.

Commandes principales :

```bash
docker compose build --no-cache app
docker compose up -d --build
docker compose ps
```

Le port PostgreSQL du poste hôte est `5434` et le port PostgreSQL entre conteneurs reste `5432`.

### Conflit de port PostgreSQL

L'ancien mapping `5433:5432` entrait en conflit avec le conteneur `saas_message_db`, qui occupait déjà le port `5433`. Le mapping actuel est donc :

```yaml
ports:
  - "5434:5432"
```

Dans Laravel, la connexion Docker doit utiliser :

```env
DB_HOST=postgres
DB_PORT=5432
```

Le port `5434` sert uniquement aux connexions depuis le Mac hôte.

## 3. Connexion au LLM

### Contrat

`app/AI/LLM/LLMProvider.php` définit l'interface commune :

```php
public function chat(array $messages): string;
```

### Implémentation LM Studio

`app/AI/LLM/LMStudioProvider.php` envoie les messages vers :

```text
/v1/chat/completions
```

La configuration se trouve dans `config/ai.php` :

```php
'lm_studio' => [
    'base_url' => env('LM_STUDIO_BASE_URL', 'http://host.docker.internal:1234'),
    'model'    => env('LM_STUDIO_MODEL', 'qwen2.5-7b-instruct'),
],
```

Le binding est déclaré dans `app/Providers/AppServiceProvider.php` afin que Laravel injecte une instance de `LMStudioProvider` quand du code demande `LLMProvider`.

## 4. Orchestration de l'agent

`app/AI/Orchestrator/AgentOrchestrator.php` :

1. reçoit le message utilisateur ;
2. ajoute le prompt système de TPE Assistant ;
3. transmet la conversation au `LLMProvider` ;
4. retourne la réponse du modèle.

Le prompt système indique notamment que l'agent doit répondre en français, clairement et brièvement.

La commande associée est définie dans `app/Console/Commands/AgentChat.php` :

```bash
docker compose exec app php artisan agent:chat "Bonjour, présente-toi en une phrase."
```

Pour une démonstration interactive avec l'équipe, utiliser :

```bash
docker compose exec app php artisan agent:conversation
```

Pour permettre à cette conversation d'utiliser les actions CRM du compte collaborateur :

```bash
docker compose exec app php artisan agent:conversation \
        --collaborator-user=<UUID>
```

Cette commande ouvre une discussion continue avec Qwen via LM Studio et conserve l'historique en mémoire pendant la session. Taper `exit` ou `quit` pour terminer.

Exemples de questions à poser :

```text
Présente le projet TPE_MESSAGE en deux phrases.
Comment l'agent protège-t-il les tokens API ?
Quelle est la différence entre PROPOSED et CONFIRMED ?
Comment préparer une campagne pour des clients inactifs ?
```

Le mode conversationnel peut maintenant exécuter une recherche CRM non destructive avec le contexte du compte collaborateur. Une demande de campagne crée une proposition `PROPOSED`; la confirmation et l'envoi restent des étapes séparées :

```text
agent:conversation
        ↓
SearchContactsTool / GenerateMessageTool
        ↓
PROPOSED
        ↓
agent:confirm
        ↓
agent:send
```

Le LLM ne déclenche donc jamais directement l'envoi d'une campagne.

## 5. Corrections de namespaces et de syntaxe

Plusieurs erreurs bloquaient le démarrage :

- `LLMProvider` était déclaré avec `use` dans le corps de `AppServiceProvider`, ce qui le faisait interpréter comme un trait. Les imports ont été déplacés en haut du fichier.
- `AgentOchestrator` comportait une faute d'orthographe dans l'import et dans la déclaration de classe. Le nom correct est `AgentOrchestrator`.
- Le test `AgentChatTest` ne terminait pas l'appel `assertExitCode(0)` par un point-virgule.

Validation du test :

```bash
docker compose exec app php artisan test --filter=AgentChatTest
```

Résultat attendu :

```text
PASS Tests\Feature\AgentChatTest
Tests: 1 passed
```

## 6. Comptes collaborateur liés

La migration `2026_09_09_135450_create_linked_accounts_table.php` crée la table `linked_accounts` avec :

- `collaborator_user_id` : UUID utilisateur côté API collaborateur ;
- `collaborator_email` : adresse du compte lié ;
- `access_token` : token d'accès ;
- `refresh_token` : token de renouvellement ;
- `expires_at` : date d'expiration du token ;
- timestamps Laravel.

Appliquer la migration :

```bash
docker compose exec app php artisan migrate
```

Ne jamais versionner de vrais tokens ou mots de passe dans le dépôt.

## 7. Client API collaborateur

`app/AI/Http/CollaboratorApiClient.php` centralise les appels HTTP vers l'API collaborateur :

```php
$client->get('/contacts', accessToken: $token);
$client->post('/auth/login', [
    'email' => $email,
    'password' => $password,
]);
```

Le client :

- utilise l'URL de base configurée ;
- ajoute automatiquement `Accept: application/json` ;
- ajoute le bearer token lorsqu'il est fourni ;
- lève une exception pour les réponses HTTP en erreur.

La configuration se trouve dans `config/collaborator.php` :

```php
'base_url' => env('COLLABORATOR_API_BASE_URL'),
```

La variable correspondante doit être définie dans `.env` :

```env
COLLABORATOR_API_BASE_URL=http://adresse-de-l-api/api/v1
```

Comme `baseUrl` est un paramètre `string`, Laravel ne peut pas l'injecter automatiquement. Le binding explicite est donc présent dans `AppServiceProvider` :

```php
$this->app->singleton(
    \App\AI\Http\CollaboratorApiClient::class,
    fn () => new \App\AI\Http\CollaboratorApiClient(
        baseUrl: config('collaborator.base_url'),
    ),
);
```

## 8. Gestion des tokens

`app/AI/Http/CollaboratorTokenManager.php` :

- appelle `/auth/login` ;
- crée ou met à jour le compte dans `linked_accounts` ;
- vérifie l'expiration du token ;
- renouvelle automatiquement le token via `/auth/refresh` ;
- retourne un token valide aux futurs Tools de l'agent.

Le manager est enregistré comme singleton :

```php
$this->app->singleton(\App\AI\Http\CollaboratorTokenManager::class);
```

L'API de connexion renvoie actuellement les tokens directement à la racine de la réponse. Le code accepte aussi une réponse encapsulée dans `data` :

```php
$data = $response['data'] ?? $response;
```

## 9. Vérification de l'authentification

Utiliser les identifiants stockés dans la configuration d'environnement, sans afficher le token :

```bash
docker compose exec app php artisan tinker --execute="\$manager = app(\App\AI\Http\CollaboratorTokenManager::class); \$account = \$manager->login(config('collaborator.test_email'), config('collaborator.test_password')); echo 'Compte lié : ' . \$account->collaborator_user_id . PHP_EOL; \$token = \$manager->getValidAccessToken(\$account->collaborator_user_id); echo 'Token valide obtenu : oui' . PHP_EOL; \$contacts = app(\App\AI\Http\CollaboratorApiClient::class)->get('/contacts', accessToken: \$token); echo 'Contacts récupérés : ' . count(\$contacts['data'] ?? \$contacts) . PHP_EOL;"
```

Validation obtenue pendant la mise en place :

```text
Compte lié : <UUID utilisateur>
Token valide obtenu : oui
Contacts récupérés : 1
```

## 10. Provider Laravel actuel

`AppServiceProvider::register()` enregistre actuellement :

- `LLMProvider` vers `LMStudioProvider` ;
- `ToolRegistry` en singleton ;
- `CollaboratorApiClient` en singleton avec son URL de base ;
- `CollaboratorTokenManager` en singleton.

`CollaboratorAuthenticator` a été supprimé : le flux retenu utilise uniquement `CollaboratorTokenManager`, lié au compte Laravel réellement connecté.

## 11. Commandes de diagnostic

Syntaxe PHP d'un fichier :

```bash
docker compose exec app php -l chemin/du/fichier.php
```

État des conteneurs :

```bash
docker compose ps
docker ps --format 'table {{.Names}}\t{{.Ports}}'
```

Tests :

```bash
docker compose exec app php artisan test
docker compose exec app php artisan test --filter=AgentChatTest
```

Recharger la configuration après une modification de `.env` :

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan config:cache
```

## 12. Prochaines étapes

### Jour 2 réalisé

Le premier vertical slice des Tools est en place :

- `Tool` définit le contrat commun ;
- `ToolRegistry` enregistre les Tools disponibles ;
- `ToolExecutor` exécute un Tool uniquement par son nom enregistré ;
- `SearchContactsTool` récupère le token du compte collaborateur puis appelle `GET /contacts` ;
- `AgentOrchestrator` reconnaît le scénario des contacts inactifs depuis un nombre de jours ;
- le contexte `collaborator_user_id` est obligatoire, ce qui évite l'utilisation implicite d'un token global.

Le scénario Artisan est disponible ainsi :

```bash
docker compose exec app php artisan agent:chat "Trouve mes clients inactifs depuis 30 jours." --collaborator-user=<UUID>
```

Les tests couvrent le scénario avec `Http::fake()` et le refus d'une requête sans contexte collaborateur :

```bash
docker compose exec app php artisan test --filter=AgentChatTest
```

Le nom du filtre `inactive_days` devra être confirmé avec la documentation exacte de l'API collaborateur avant mise en production.

### Suite prévue

### Jour 3 commencé

Le premier workflow de campagne est maintenant disponible :

```text
Recherche des contacts
        ↓
Génération du message par le LLM
        ↓
AiCommand(status=PROPOSED)
        ↓
Confirmation demandée
```

La table `ai_commands` conserve l'audience, l'offre, le brouillon et le nombre estimé de destinataires. Aucun appel d'envoi n'est effectué à cette étape.

Commande de démonstration :

```bash
docker compose exec app php artisan agent:chat \
  "Envoie une promotion de 15% à mes clients inactifs depuis 30 jours." \
  --collaborator-user=<UUID>
```

La documentation de l'API campagne confirme maintenant le payload de création et d'envoi.

La confirmation est maintenant disponible :

```bash
docker compose exec app php artisan agent:confirm <ID_PROPOSITION> \
        --collaborator-user=<UUID>
```

Une proposition appartenant à un autre compte est refusée. L'annulation suit le même contrôle :

```bash
docker compose exec app php artisan agent:cancel <ID_PROPOSITION> \
        --collaborator-user=<UUID>
```

Transitions actuellement implémentées :

```text
PROPOSED -> CONFIRMED
PROPOSED -> CANCELLED
```

Après confirmation, l'application crée un groupe ciblé avec les UUID des contacts trouvés, crée une campagne brouillon avec `POST /campaigns`, puis conserve l'identifiant de campagne dans `ai_commands.campaign_id`. L'envoi explicite utilise :

```bash
docker compose exec app php artisan agent:send <ID_PROPOSITION> \
        --collaborator-user=<UUID>
```

Cette commande appelle `POST /campaigns/{campaign}/send` et passe la commande à `EXECUTED` uniquement si l'API répond sans erreur.

La documentation fournie ne contient pas d'endpoint de statistiques de campagne. La récupération et l'analyse des résultats restent donc à implémenter lorsque cet endpoint sera disponible.

### Suite générale

1. Ajouter les Tools de l'agent pour exploiter `CollaboratorTokenManager` et `CollaboratorApiClient`.
2. Remplacer le compte de test par l'utilisateur Laravel authentifié.
3. Ajouter des tests unitaires du client API avec `Http::fake()`.
4. Ajouter des tests du renouvellement de token et des réponses API en erreur.
5. Ne jamais afficher ou journaliser les tokens complets.
6. Vérifier que les tokens persistés sont chiffrés avant une mise en production.
