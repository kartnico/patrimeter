# Parcours d'authentification

Toutes les pages sont rendues par le serveur ; chaque formulaire porte le jeton CSRF de la
session et chaque redirection après un POST est un 303.

## Inscription et confirmation de l'adresse

La confirmation exige le lien reçu par e-mail **et** le mot de passe choisi : quelqu'un qui
inscrirait l'adresse d'autrui ne peut pas faire activer son compte par la victime.

```mermaid
sequenceDiagram
    actor V as Visiteur
    participant A as Patrimeter
    participant M as Boîte e-mail
    V->>A: POST /inscription (e-mail, mot de passe ×2)
    Note over A: validation, limite par IP (10/h)<br/>hachage Argon2id dans tous les cas
    alt adresse nouvelle
        A->>M: lien de confirmation (24 h, usage unique)
    else adresse non confirmée
        A->>A: nouveau mot de passe, anciens liens invalidés
        A->>M: nouveau lien (3 e-mails/h au plus)
    else adresse déjà inscrite
        A->>M: « vous avez déjà un compte »
    end
    A-->>V: 303 → « Vérifiez votre messagerie » (même page dans les trois cas, adresse saisie rappelée)
    V->>A: GET /inscription/confirmation/{jeton}
    A-->>V: formulaire « mot de passe de l'inscription »
    V->>A: POST mot de passe
    A->>A: jeton consommé, adresse confirmée, connexion
    A-->>V: 303 → configuration du TOTP
```

Une inscription jamais confirmée est supprimée par la tâche planifiée horaire (`Registration::purgeUnconfirmed`)
24 heures après le dernier lien envoyé, sauf si elle a des comptes ; jamais un compte de démonstration (sa propre
durée de vie) ni un compte sans adresse. L'adresse redevient libre.

## Première connexion : configuration du TOTP

Obligatoire : tant qu'elle n'est pas faite, toute autre page renvoie vers elle
(`EnrollmentRequiredSubscriber`) et les dépôts refusent l'utilisateur (`CurrentUser`).

```mermaid
sequenceDiagram
    actor U as Utilisateur
    participant A as Patrimeter
    U->>A: GET /securite/application
    A->>A: secret aléatoire (160 bits) en session, chiffré
    A-->>U: QR code (otpauth://) et clé en clair groupée par 4
    U->>A: POST code à 6 chiffres
    alt code valide
        A->>A: secret chiffré enregistré, 10 codes de secours générés (HMAC)
        A-->>U: codes de secours, affichés une seule fois
    else code faux
        A-->>U: 422, même secret, nouvel essai
    end
```

## Connexion

```mermaid
sequenceDiagram
    actor U as Utilisateur
    participant A as Patrimeter
    U->>A: POST /connexion (e-mail, mot de passe, jeton CSRF)
    Note over A: limitation par IP, par compte et globale<br/>adresse inconnue = même réponse, même coût
    alt mot de passe faux
        A-->>U: 401 « Adresse e-mail ou mot de passe incorrect »
    else adresse non confirmée (mot de passe correct)
        A-->>U: 403, lien de confirmation renvoyé
    else TOTP pas encore configuré
        A-->>U: 303 → configuration du TOTP
    else navigateur de confiance (cookie valable, mêmes identifiants)
        A-->>U: 303 → page demandée, sans code
    else
        A-->>U: 303, puis /connexion/code (aucune autre page accessible)
        U->>A: POST code TOTP ou code de secours
        Note over A: 5 codes faux : compte bloqué 15 min<br/>code TOTP déjà utilisé refusé
        A-->>U: 303 → page demandée au départ (chemin interne) ou tableau de bord
    end
```

**Navigateur de confiance** (`TrustedBrowsers`, `SecondFactorCondition`) : la case « Faire confiance à ce
navigateur » cochée avec un code valide, le serveur pose un cookie aléatoire `<sélecteur>.<secret>` (`HttpOnly`,
`SameSite=Strict`, `Secure` et préfixe `__Host-` en HTTPS) pour 30 jours et ne garde que l'empreinte du secret, avec
l'empreinte des identifiants du moment. À la connexion suivante, un cookie valable pour ce compte et ces identifiants
dispense du code (le mot de passe reste exigé) ; un nouveau mot de passe ou la fermeture des autres sessions
(renouvellement du tampon de session) le rendent caduc. Chaque navigateur se retire depuis Connexion et sécurité.

## Passkeys (FIDO2) et clés de sécurité

- **Passkey** : identifiant découvrable, vérification de l'utilisateur exigée, aucun second facteur
  demandé. La sortie PRF ouvre les données si l'appareil la fournit.
- **Clé de sécurité** : en second facteur seulement, à la place du code, sur la même page et avec la
  même limitation. Une passkey peut aussi servir de second facteur.
- Défi aléatoire à usage unique en session, propre à chaque cérémonie ; origine limitée à `DEFAULT_URI` ; compteur vérifié.

## API et service MCP

Mêmes facteurs que le site : le client ne voit jamais le mot de passe. Détail : [api.md](api.md).

```mermaid
sequenceDiagram
    actor U as Utilisateur
    participant C as Client (API, MCP)
    participant A as Patrimeter
    C->>A: requête sans jeton
    A-->>C: 401, WWW-Authenticate : resource_metadata
    C->>A: métadonnées, inscription (RFC 7591)
    C->>U: ouvre le navigateur (PKCE, state, resource)
    U->>A: GET /oauth/authorize
    Note over U,A: connexion habituelle si besoin :<br/>mot de passe et TOTP, ou passkey
    A-->>U: consentement
    U->>A: POST Autoriser
    A-->>U: page intermédiaire vers le client (code, state, iss)
    U->>C: code
    C->>A: POST /oauth/token (code, vérificateur PKCE)
    A-->>C: jeton d'accès 1 h, jeton de rafraîchissement
    C->>A: requêtes avec Authorization : Bearer
```

Le départ vers le client passe par une page, et non une redirection : Chromium applique la CSP
`form-action` aux redirections qui suivent un formulaire.

## Étapes obligatoires après la connexion

`AccountGateSubscriber` les impose dans l'ordre, en redirigeant toute autre requête : configurer le
TOTP, déverrouiller les données si la clé de session est perdue (cookie effacé), noter la clé de
récupération (affichée une fois, confirmée par ses deux derniers groupes).

## Mot de passe oublié

```mermaid
flowchart TD
    D[Demande : même réponse que l'adresse existe ou non] --> L[Lien par e-mail : 30 min, usage unique]
    L --> F[Nouveau mot de passe]
    F --> K{Clé de récupération ?}
    K -->|oui, avec code valide| A[Données conservées, TOTP conservé]
    K -->|oui, sans code : téléphone perdu| B[Données conservées, TOTP à reconfigurer]
    K -->|non| C{Code valide et perte acceptée ?}
    C -->|oui| E[Données effacées, nouvelle clé à la connexion]
    C -->|non| R[Refus : rien n'est modifié]
```

## Actions sensibles

```mermaid
flowchart TD
    S[Page Sécurité] --> P{Changer le mot de passe}
    P -->|mot de passe actuel + nouveau ×2| C[Empreinte remplacée]
    C --> O[Autres sessions fermées, celle-ci conservée, e-mail d'alerte]
    S --> R{Autre action sensible ?}
    R -->|confirmation de moins de 5 min| A[Changer d'application TOTP<br/>Nouveaux codes de secours<br/>Déconnecter les autres sessions]
    R -->|sinon| F[Confirmer son mot de passe]
    F --> A
```

## Fin de session

- Déconnexion (POST avec jeton), possible aussi pendant la saisie du code.
- 30 minutes sans activité, ou 12 heures après la connexion.
- Changement du mot de passe, « déconnecter mes autres sessions », réinitialisation par
  l'administrateur : l'utilisateur, rechargé à chaque requête, ne correspond plus à celui de la
  session (empreinte du mot de passe ou tampon de session différents).
