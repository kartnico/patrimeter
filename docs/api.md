# API REST et service MCP

Deux accès aux mêmes données, avec **les mêmes mécanismes d'authentification que le site**, l'utilisateur
se connectant dans son navigateur (mot de passe et TOTP, ou passkey) :

- **API REST** : `/api/v1/…`, JSON, montants en centimes (entiers), mois au format `AAAA-MM`.
- **Service MCP** : `/mcp`, pour un assistant ou un client MCP. Révision `2026-07-28`, et révisions
  antérieures pour les clients plus anciens, par [`symfony/mcp-bundle`](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html)
  (expérimental : figé dans `composer.lock`).

## Activation

L'API et le service MCP sont **désactivés par défaut**, chacun par sa variable d'environnement, comme dans
Carbure : une instance n'expose que ses pages tant que l'administrateur n'en décide pas autrement. Dans
`.env.local` ([installation § 3](installation.md#3-configurer)) ou le fichier d'environnement du
conteneur :

```sh
API_ENABLED=true   # API REST, /api/v1/…
MCP_ENABLED=true   # service MCP, /mcp
```

Un service désactivé répond 404 sur toutes ses adresses, avant le routage et le pare-feu
(`Http\ApiAvailabilitySubscriber`), métadonnées OAuth comprises. Le serveur d'autorisation OAuth (`/oauth/…`,
`/.well-known/oauth-authorization-server`) et les jetons personnels (page Mon compte) n'existent que si l'un des
deux est activé. Les jetons déjà émis restent en base et resservent si le service est réactivé.

## Authentification

Patrimeter est son propre serveur d'autorisation OAuth 2.1 : code d'autorisation avec PKCE (S256), clients
publics, jeton porteur. Le pare-feu utilise l'authentificateur natif `access_token` de Symfony.

| Accès | Obtention | Durée |
|---|---|---|
| Application, client MCP | Flux OAuth : le navigateur s'ouvre sur la connexion du site, puis le consentement | Jeton d'accès 1 h, jeton de rafraîchissement 30 jours (à usage unique) |
| Script | Jeton personnel créé depuis la page **Mon compte**, après confirmation d'identité | 30, 90 ou 365 jours |

Dans les deux cas : `Authorization: Bearer patrimeter_…`.

- **Format reconnaissable** : un jeton commence toujours par `patrimeter_`, suivi de 24 caractères hexadécimaux, de
  `_` et de 43 caractères base64url. Un détecteur de secrets repère ainsi un jeton publié par erreur (dépôt de code,
  ticket, journal) : la règle `patrimeter-api-token` de [`.gitleaks.toml`](../.gitleaks.toml) l'applique à ce dépôt,
  et son expression régulière se reprend dans tout autre outil. Les jetons de l'ancien format (`pm1.…`, avant la
  2.23.0) restent valides jusqu'à leur expiration. Un jeton exposé se retire depuis l'onglet « Agents IA ».

- **Portées** : `patrimeter.read` (par défaut), `patrimeter.write` (écriture, comprend la lecture). Sans la
  bonne portée : `403` et `WWW-Authenticate: Bearer error="insufficient_scope"`.
- **Destinataire** (RFC 8707) : un jeton OAuth ne vaut que pour sa ressource, `<DEFAULT_URI>/mcp` ou
  `<DEFAULT_URI>/api`. Un jeton personnel vaut pour les deux.
- **Fin de validité** : comme une session, un jeton tombe si le mot de passe change, si l'utilisateur
  déconnecte ses autres sessions, ou si l'administrateur réinitialise son second facteur.
- **Retrait** : page Mon compte, onglet « Agents IA », ou `POST /oauth/revoke`.
- **Chiffrement** : le jeton ouvre la clé de données de l'utilisateur pour la requête, sans que le serveur
  puisse le faire seul : la base ne contient que son empreinte et une copie de la clé enveloppée sous une clé
  dérivée de lui ([encryption.md](encryption.md)). **Qui détient un jeton lit les données en clair** :
  traitez-le comme un mot de passe.

Découverte, pour un client : une requête sans jeton répond `401` avec `WWW-Authenticate: Bearer
resource_metadata="…"`, qui mène à `/.well-known/oauth-protected-resource/mcp` (ou `/api`), puis à
`/.well-known/oauth-authorization-server`. Deux façons pour un client de se faire connaître :

- **Document de métadonnées** (*Client ID Metadata Document*, préféré par la spécification MCP) : son
  `client_id` est l'adresse HTTPS d'un document JSON (`client_id`, `redirect_uris`, `client_name`). Le serveur
  le lit avec le HttpClient de Symfony, jamais vers le réseau local ni la boucle locale, sans redirection, en
  64 Ko au plus, et le garde une heure. Le serveur doit pouvoir joindre l'adresse du document.
- **Inscription dynamique** (RFC 7591) sur `POST /oauth/register`, limitée à 10 par heure et par adresse IP.

Adresses de retour en HTTPS, ou en HTTP sur la boucle locale.

Le proxy inverse doit laisser passer `/mcp`, `/api/`, `/oauth/` et `/.well-known/oauth-`, et l'en-tête
`Authorization`. Le site restant réservé au réseau local, un client doit s'y trouver lui aussi.

## API REST

Description complète au format **OpenAPI 3.1** : [openapi.yaml](openapi.yaml), à importer dans un client (Bruno,
Insomnia, Postman…) ou un générateur de code. Sa version lisible, avec chaque opération, ses paramètres, ses réponses
et ses schémas : [référence de l'API](https://kartnico.github.io/patrimeter/fr/exploitation/api-reference/)
(documentation en ligne). Un test vérifie qu'elle couvre exactement les routes de l'API et que les réponses réelles
respectent ses schémas.

Avec `curl`, un jeton personnel dans `$TOKEN` :

```sh
curl -H "Authorization: Bearer $TOKEN" https://patrimeter.example.org/api/v1/net-worth
curl -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -X PUT \
    -d '{"amount_cents": 1234567}' https://patrimeter.example.org/api/v1/accounts/12/statements/2026-11
```

| Méthode et adresse | Rôle |
|---|---|
| `GET /api/v1/me` | Utilisateur du jeton, droit d'écriture |
| `GET /api/v1/account-types` | Types de comptes, niveau et nature par défaut |
| `GET /api/v1/net-worth?month=` | Patrimoine net, par niveau (mois courant par défaut) |
| `GET /api/v1/history?from=&to=` | Patrimoine net mois par mois |
| `GET /api/v1/properties?month=` | Biens : valeur, part, crédits, valeur nette |
| `GET /api/v1/goals?month=` | Objectifs d'épargne : valeur atteinte, effort nécessaire, rythme, statut, mois projeté |
| `GET /api/v1/challenges` | Défis et étoiles du mois : étoiles et niveau, stade et style d'épargnant, série de saisies, chaque défi avec ses paliers ; avec le rappel « pas un conseil en investissement » |
| `GET /api/v1/budget?month=` | Budget du foyer en vigueur : totaux, catégories et postes avec leur montant |
| `GET`, `POST /api/v1/accounts` | Liste, création (`name`, `type_id`, `opened_on`, et facultatifs `level`, `nature`, `initial_amount_cents`) |
| `GET`, `PUT`, `DELETE /api/v1/accounts/{id}` | Détail avec relevés et parts, modification, suppression |
| `POST /api/v1/accounts/{id}/archive`, `/unarchive` | Clôture (`closed_on`), réactivation |
| `PUT /api/v1/accounts/{id}/loan-link` | Rattache un crédit à un bien (`asset_id`, `loan_mode` : `prorata` ou `own`), ou le détache (`asset_id` nul) |
| `GET /api/v1/accounts/{id}/statements` | Relevés |
| `PUT`, `DELETE /api/v1/accounts/{id}/statements/{mois}` | Relevé d'un mois (`amount_cents`). Un crédit a un montant positif : le capital restant dû |
| `GET /api/v1/accounts/{id}/shares` | Parts d'un bien |
| `PUT`, `DELETE /api/v1/accounts/{id}/shares/{mois}` | Part à partir d'un mois (`share_millionths`, de 1 à 1 000 000) |

Les corps de requête sont en JSON (`Content-Type: application/json`, sinon `415`), lus et validés par
`#[MapRequestPayload]` et les contraintes de Symfony. Un nombre décimal là où un entier est attendu est
refusé, jamais tronqué.

Erreurs au format `application/problem+json` (RFC 9457) : `{"title", "status"}`, et `errors` (champ →
message) pour une donnée refusée (`422`). Le compte d'un autre utilisateur répond `404`, comme un compte
inexistant.

## Service MCP

Adresse à donner au client : `https://patrimeter.example.org/mcp`. Il ouvre le navigateur pour la connexion
et le consentement. Par exemple avec Claude Code :

```sh
claude mcp add --transport http patrimeter https://patrimeter.example.org/mcp
```

| Outil | Portée | Rôle |
|---|---|---|
| `get_net_worth` | lecture | Patrimoine net d'un mois, par niveau |
| `get_history` | lecture | Patrimoine net mois par mois |
| `list_accounts`, `get_account` | lecture | Comptes, valeurs ; relevés et parts d'un compte |
| `list_properties` | lecture | Biens et valeur nette |
| `list_goals` | lecture | Objectifs d'épargne et où en est chacun |
| `get_challenges` | lecture | Défis et étoiles du mois, avec le rappel « pas un conseil en investissement » |
| `get_budget` | lecture | Budget du foyer du mois |
| `list_account_types` | lecture | Types de comptes |
| `create_account`, `save_statement`, `delete_statement` | écriture | Création d'un compte, relevé d'un mois |

Un outil d'écriture demandé avec un jeton de lecture répond `403` : le client redemande alors l'accès avec
la portée d'écriture. Un assistant qui appelle ces outils **transmet vos données à son fournisseur** : le
consentement concerne aussi cela.

**Origine** : comme le demande la spécification MCP contre le *DNS rebinding*, une requête qui porte un en-tête
`Origin` (envoyé par un navigateur) n'est acceptée que s'il vaut l'adresse du site (`DEFAULT_URI`) ou l'une des
origines de `MCP_ALLOWED_ORIGINS` ; sinon : `403`, avant même la lecture du jeton. Un client hors navigateur
(Claude Code, script) n'envoie pas cet en-tête et n'est pas concerné.

## Limites

- Débit par jeton : 120 requêtes d'affilée, puis une par seconde (API et MCP confondus). Au-delà, `429 Too
  Many Requests` avec `Retry-After` (secondes) et `{"title": "Trop de requêtes", "status": 429,
  "retry_after": …}`.
- Le journal des connexions note l'accès accordé ou retiré, pas chaque appel.
