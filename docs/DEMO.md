# Guide de démonstration TPE Assistant

Ce guide permet de présenter le projet à l'équipe sans envoyer de campagne réelle par erreur.

## 1. Démarrer l'environnement

```bash
docker compose up -d
```

Vérifier l'état :

```bash
docker compose ps
```

LM Studio doit être ouvert sur le Mac avec le modèle Qwen chargé et son serveur local activé.

## 2. Tester une question simple

```bash
docker compose exec app php artisan agent:chat \
  "Présente le projet TPE_MESSAGE en deux phrases pour mon équipe."
```

Le flux est :

```text
Laravel -> AgentOrchestrator -> LLMProvider -> LM Studio/Qwen -> réponse
```

## 3. Discussion interactive

Pour discuter avec l'agent pendant une session :

```bash
docker compose exec app php artisan agent:conversation
```

Taper `exit` ou `quit` pour terminer.

Pour permettre les actions CRM sur le compte collaborateur lié :

```bash
docker compose exec app php artisan agent:conversation \
  --collaborator-user=<COLLABORATOR_USER_UUID>
```

La commande suivante récupère les contacts sans modifier les données :

```text
Trouve mes clients inactifs depuis 30 jours.
```

Une formulation plus naturelle est également supportée lorsque Qwen retourne l'intention JSON attendue :

```text
Quels clients n'ont plus commandé depuis environ 30 jours ?
```

## 4. Tester l'API collaborateur directement

Cette commande utilise les identifiants de test présents dans `.env`, récupère ou renouvelle le token, puis appelle `GET /contacts`. Elle n'affiche jamais le token complet et n'envoie aucune campagne.

```bash
docker compose exec app php artisan tinker --execute="\$manager = app(\App\AI\Http\CollaboratorTokenManager::class); \$account = \$manager->login(config('collaborator.test_email'), config('collaborator.test_password')); \$token = \$manager->getValidAccessToken(\$account->collaborator_user_id); \$contacts = app(\App\AI\Http\CollaboratorApiClient::class)->get('/contacts', accessToken: \$token); echo 'Compte : ' . \$account->collaborator_user_id . PHP_EOL; echo 'Contacts récupérés : ' . count(\$contacts['data']['data'] ?? \$contacts['data'] ?? \$contacts) . PHP_EOL;"
```

## 5. Authentification web réelle

Créer un compte local :

```text
http://localhost:8080/register
```

Puis se connecter :

```text
http://localhost:8080/login
```

Le chat authentifié est disponible sur :

```text
http://localhost:8080/chat
```

L'endpoint `POST /chat` crée automatiquement une conversation persistante et enregistre les messages dans :

- `ai_conversations` ;
- `ai_messages`.

Le `conversation_id` retourné peut être renvoyé au prochain appel pour continuer la même conversation.

## 6. Workflow campagne avec confirmation

Demander à l'agent :

```text
Envoie une promotion de 15% à mes clients inactifs depuis 30 jours.
```

L'agent :

1. recherche les contacts ;
2. génère un message avec Qwen ;
3. crée une proposition `PROPOSED` ;
4. affiche son identifiant ;
5. ne déclenche aucun envoi.

Confirmer la proposition :

```bash
docker compose exec app php artisan agent:confirm <ID_PROPOSITION> \
  --collaborator-user=<COLLABORATOR_USER_UUID>
```

Cette étape crée le groupe ciblé et la campagne brouillon côté API collaborateur.

Envoyer explicitement :

```bash
docker compose exec app php artisan agent:send <ID_PROPOSITION> \
  --collaborator-user=<COLLABORATOR_USER_UUID>
```

Annuler :

```bash
docker compose exec app php artisan agent:cancel <ID_PROPOSITION> \
  --collaborator-user=<COLLABORATOR_USER_UUID>
```

Transitions :

```text
PROPOSED -> CONFIRMED -> EXECUTED
PROPOSED -> CANCELLED
```

Pour une première démonstration, utiliser les tests avec `Http::fake()` et ne lancer l'envoi réel qu'après vérification du groupe, du message, du canal et du nombre de destinataires.

## 7. Tests

Suite complète :

```bash
docker compose exec app php artisan test
```

Tests d'authentification et de mémoire :

```bash
docker compose exec app php artisan test --filter=AuthenticatedChatTest
```

Tests du workflow Agent/Tools/campagne :

```bash
docker compose exec app php artisan test --filter=AgentChatTest
```

## 8. Limites connues

- Le compte collaborateur est actuellement associé à l'utilisateur local par son email dans `linked_accounts`.
- Les permissions/tenant Laravel complets restent à brancher avant la production.
- La mémoire est persistante via l'API web ; la commande CLI conserve surtout son historique en mémoire pendant la session.
- Le parser LLM demande une intention JSON et utilise un fallback regex si Qwen ne renvoie pas un JSON valide.
- Aucun endpoint de statistiques de campagne n'a été fourni ; les statistiques sont volontairement laissées de côté.
- Les identifiants et mots de passe de test doivent rester dans `.env` et ne doivent jamais être commités.
