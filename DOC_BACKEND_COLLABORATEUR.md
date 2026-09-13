# Spécifications d'Intégration & Rapport de Recette — Agent IA <-> Backend Messagerie

**Date de mise à jour :** 11 Septembre 2026  
**Destinataires :** Équipe Backend "Plateforme Messagerie"  
**Objet :** Document de référence détaillant la consommation de votre API par l'Agent IA (TPE_MESSAGE_IA) et les retours de recette (bugs identifiés).

---

## 1. Architecture et Cycle de vie de l'Agent IA

Notre Agent IA agit comme un client API automatisé au nom de l'utilisateur final. Le cycle de vie complet d'une requête d'IA (génération de campagne) déclenche jusqu'à 4 appels HTTP successifs vers votre backend :

1. **Extraction du contexte** : `GET /contacts`
2. **Création de la cible (si nécessaire)** : `POST /groups`
3. **Affectation des contacts** : `POST /groups/{group}/contacts`
4. **Création du brouillon** : `POST /campaigns`

Notre système gère ses propres états (`PROPOSED`, `CONFIRMED`, `EXECUTED`). **Aucun appel d'écriture (`POST`) n'est fait sans la confirmation manuelle de l'utilisateur.**

---

## 2. Format des requêtes envoyées par l'IA

Nous avons mis à jour notre client API pour nous conformer strictement à la version de votre documentation générée le 11/09/2026 (Architecture Multi-tenant).

### A. Récupération des contacts
```http
GET /api/v1/contacts
Authorization: Bearer <token>
```
*Attente de l'IA :* Nous lisons le tableau imbriqué `data.contacts.data` pour fournir un contexte local au LLM.

### B. Création de Campagne (Le format attendu)
Conformément à la nouvelle spécification multi-canal, nous envoyons un tableau `channels`.

```http
POST /api/v1/campaigns
Authorization: Bearer <token>
Content-Type: application/json

{
  "name": "Nom généré par l'IA",
  "content": "Contenu marketing généré...",
  "channels": ["sms"], 
  "group_id": "uuid-du-groupe" 
}
```
*Note :* L'agent IA n'envoie **plus** la clé `message_channel`. Le passage au tableau `channels` est pleinement géré de notre côté.

---

## 3. 🚨 Rapport de Bugs Actuels (Action Requise Backend)

Au cours de nos tests d'intégration automatisés (E2E) réalisés contre votre environnement de développement, nous avons détecté des erreurs SQL critiques (HTTP 500) bloquant la création de campagnes ciblées.

Ces erreurs découlent manifestement de votre récente refonte vers l'architecture `tenant_id`. **Le schéma de la base de données ne correspond plus aux requêtes Eloquent/SQL de vos contrôleurs.**

### Bug 1 : `user_id` manquant sur la gestion des Groupes / Contacts
Lors de la tentative d'ajout de contacts à un groupe spécifique, votre API crashe car elle cherche à valider l'ancienne clé `user_id`.

- **Endpoint concerné :** `POST /groups/{group}/contacts` (ou lors d'un `GET /contacts` conditionnel).
- **Code HTTP retourné :** `500 Internal Server Error`
- **Trace d'erreur SQL reçue :**
  ```json
  {
    "message": "SQLSTATE[42703]: Undefined column: 7 ERREUR: la colonne « user_id » n'existe pas
    LINE 1: ...s \"aggregate\" from \"contacts\" where \"id\" = $1 and \"user_id\" = $2...",
    "exception": "Illuminate\\Database\\QueryException",
    "file": "D:\\wamp64\\www\\Messagerie\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Connection.php"
  }
  ```
- **Action requise de votre côté :** 
  Mettre à jour le code de vos requêtes (ex: `$group->contacts()->where(...)` ou vos FormRequests) pour utiliser `tenant_id` au lieu de `user_id` lors de la vérification d'appartenance d'un contact.

### Bug 2 : Tolérance au `tenant_id` lors de l'auth
Occasionnellement, lors du refresh ou d'un appel `GET /contacts`, votre système a soulevé l'erreur :
`Undefined column: 7 ERREUR: la colonne « tenant_id » n'existe pas`
- **Action requise :** S'assurer que toutes les migrations de base de données (ajout de la colonne `tenant_id` sur toutes les tables impactées) ont bien été exécutées sur l'environnement ciblé (`192.168.11.112`).

---

## 4. Idempotence et Résilience

Pour garantir qu'une erreur réseau (ou une erreur 500 de votre API) ne corrompe pas le travail de l'utilisateur :
- Notre backend IA sauvegarde l'état de chaque étape.
- Si la requête `POST /groups` réussit, mais que la requête `POST /campaigns` échoue (ex: à cause du bug SQL mentionné), l'agent IA mémorise l'ID du groupe créé. 
- Lors du ré-essai par l'utilisateur (via `/confirm`), l'IA n'essaiera pas de recréer le groupe (évitant ainsi l'erreur de contrainte d'unicité `23505` sur le nom du groupe).

*Cependant, pour que notre système puisse finaliser l'envoi, les erreurs SQL de votre côté doivent être corrigées.*

**Nous restons à disposition pour refaire une passe de tests E2E complets dès que le correctif sur `user_id` sera déployé sur votre environnement !**

