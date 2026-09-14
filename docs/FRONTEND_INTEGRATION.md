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

## Prérequis d'Authentification

L'authentification (login, register, logout) est **gérée par le backend de Dera**.
Ce module IA suppose que l'utilisateur est déjà authentifié au sein de la session Laravel courante (ou via un jeton si configuré autrement dans votre application). Le frontend doit rediriger l'utilisateur vers votre page de login s'il reçoit une réponse `401` sur les routes du module.

## Résumé des endpoints (Module IA)

| Méthode | Route | Auth | Description |
|---|---|---|---|
| POST | `/collaborator-account` | connecté | Liaison du compte à l'API collaborateur |
| GET | `/chat/conversations` | connecté | Liste l'historique des conversations de l'utilisateur |
| GET | `/chat/conversations/{id}`| connecté | Récupère les messages d'une conversation spécifique |
| POST | `/chat` | connecté | Envoi d'un message à l'agent IA |
| POST | `/ai-commands/{id}/confirm` | connecté | Confirme une proposition de campagne |
| POST | `/ai-commands/{id}/cancel` | connecté | Annule une proposition |
| POST | `/ai-commands/{id}/send` | connecté | Envoie une campagne confirmée |

Toutes ces routes sont limitées en fréquence (`throttle`) : 10 requêtes/minute pour la liaison de compte, 20/minute pour les actions sur une proposition, 30/minute pour `/chat`. Une réponse `429` signifie que la limite a été atteinte ; le frontend doit afficher un message d'attente plutôt que de relancer immédiatement.

## Lier le compte API de Dera

Un utilisateur Laravel doit d'abord relier son compte à son compte de la plateforme principale :

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

## Historique des conversations

### Lister les conversations
```http
GET /chat/conversations
```

Réponse `200` :
```json
{
  "conversations": [
    {
      "id": 12,
      "title": "Fais une proposition de campagne email...",
      "created_at": "2023-10-27T10:00:00.000000Z",
      "updated_at": "2023-10-27T10:05:00.000000Z"
    }
  ]
}
```

### Charger les messages d'une conversation
```http
GET /chat/conversations/{id}
```

Réponse `200` :
```json
{
  "conversation": {
    "id": 12,
    "title": "Fais une proposition de campagne email...",
    "created_at": "2023-10-27T10:00:00.000000Z",
    "updated_at": "2023-10-27T10:05:00.000000Z",
    "messages": [
      {
        "id": 45,
        "role": "user",
        "content": "Fais une proposition de campagne email...",
        "created_at": "2023-10-27T10:00:00.000000Z"
      },
      {
        "id": 46,
        "role": "assistant",
        "content": "J'ai analysé votre site...",
        "created_at": "2023-10-27T10:01:00.000000Z"
      }
    ]
  }
}
```

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
  "answer": "J'ai trouvé 1 contacts dans votre compte collaborateur.",
  "proposal_id": null
}
```

**Note sur `proposal_id` :** 
Lorsque l'IA génère une campagne, elle renvoie le texte de la campagne avec un ID (ex: `#30`). Le backend extrait automatiquement ce numéro et le renvoie dans le champ `proposal_id`. 
Le frontend peut s'en servir pour afficher des boutons d'actions ("Confirmer", "Annuler") directement dans l'UI du chat.

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

Aucun test automatique ne déclenche un envoi réel. Pour un test réel, vérifier manuellement le canal, le groupe et les destinataires avant d'appeler `/send`.
