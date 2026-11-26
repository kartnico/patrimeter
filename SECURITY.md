# Sécurité

## Signaler une vulnérabilité

Signalez toute vulnérabilité par un **signalement privé** sur GitHub :
<https://github.com/kartnico/patrimeter/security/advisories/new>.

- **Réponse** sous 7 jours : accusé de réception, puis évaluation de la gravité.
- **Correctif** publié dans une nouvelle version dès que possible, au plus tard sous 90 jours pour une faille
  confirmée ; le CHANGELOG et la Release la mentionnent.
- **Divulgation coordonnée** : merci de ne publier aucun détail avant la correction ; la personne qui a signalé la
  faille est créditée si elle le souhaite.

Seule la dernière version publiée reçoit des correctifs de sécurité.

## État actuel

Patrimeter est **multi-utilisateurs** : comptes cloisonnés, mot de passe et second facteur TOTP
obligatoires, données patrimoniales chiffrées avec une clé par utilisateur
([docs/encryption.md](docs/encryption.md)), passkeys et clés FIDO2.
Parcours d'authentification : [docs/auth-flows.md](docs/auth-flows.md). Sauvegardes :
[docs/backup.md](docs/backup.md).

### Ce qu'on protège

- Les données patrimoniales de chaque utilisateur : comptes, montants, historique, invisibles
  et inaccessibles pour les autres utilisateurs.
- L'accès aux comptes : mot de passe, second facteur, sessions.
- La confidentialité des inscriptions : l'application ne révèle pas si une adresse a un compte.

### Hypothèses

- L'administrateur du serveur est de confiance (voir les limites ci-dessous).
- HTTPS est obligatoire en production, sur le serveur ou sur un proxy inverse déclaré dans
  `TRUSTED_PROXIES` : passkeys et cookie `__Host-` n'existent pas sans lui.
- Les dépendances viennent de sources reconnues (dépôt Surý, Packagist, `composer.lock` versionné).

## Mesures en place

| Menace | Mesure | Où |
|---|---|---|
| Accès aux données d'un autre utilisateur | Propriétaire exigé par chaque méthode des dépôts et filtré dans chaque requête (test d'architecture) ; voter sur chaque compte lu ; SQL interdit hors des dépôts (règle PHPStan) ; clé étrangère composite `(account_id, user_id)` ; compte d'autrui introuvable (404, sans révéler son existence) ; robots de test sur toutes les routes | `AccountRepository`, `StatementRepository`, `OwnedResourceVoter`, `IsolationTest` |
| Devinette du mot de passe | Argon2id (64 Mio, 4 passes) ; 12 caractères au moins, `PasswordStrength` de Symfony (entropie ≥ 80 bits) et `NotCompromisedPassword` (absent des fuites connues) ; limiteurs de symfony/rate-limiter : 5 échecs par IP sur 15 min, puis blocage ; par compte, 5 échecs puis un essai par minute, sans blocage pour qu'un tiers ne puisse pas bloquer l'utilisateur ; 100 échecs par heure au total ; compteurs dans la base, sans adresse e-mail en clair ; aucune vérification pendant un blocage | `AuthThrottle`, `config/packages/rate_limiter.yaml`, `PasswordRules` |
| Mot de passe compromis | Second facteur TOTP obligatoire (RFC 6238, 6 chiffres, 30 s, tolérance ± 15 s) ; code déjà utilisé refusé (rejeu) ; 5 codes faux bloquent le compte 15 min ; aucune page accessible avant le code | scheb/2fa-bundle, `AuthenticationSubscriber`, `EnrollmentRequiredSubscriber` |
| Téléphone perdu | 10 codes de secours à usage unique (≈ 50 bits chacun), affichés une seule fois, consommés de façon atomique ; rappel quand il en reste 3 ou moins ; réinitialisation par l'administrateur en dernier recours | `BackupCodes`, `app:user:reset-2fa` |
| Hameçonnage | Passkeys et clés FIDO2 liées au domaine (`DEFAULT_URI`), défi à usage unique, compteur vérifié ; gestion sous confirmation d'identité | `WebAuthn`, `WebAuthnController` |
| Jeton d'API volé ou réutilisé | Jeton de 256 bits dont seule l'empreinte est stockée ; destinataire vérifié (RFC 8707) ; portée de lecture par défaut ; accès d'une heure, rafraîchissement à usage unique et rejeu qui retire toute l'autorisation ; invalidé avec les sessions ; retrait depuis Mon compte ; e-mail d'alerte ; préfixe fixe `patrimeter_` et longueur fixe, pour qu'un détecteur de secrets repère un jeton publié par erreur (règle `patrimeter-api-token` de `.gitleaks.toml`) | `ApiTokens`, `ApiTokenHandler`, `.gitleaks.toml` |
| Abus d'un jeton d'API valide (boucle d'un client, jeton volé) | Débit limité par jeton sur l'API et le MCP : 120 requêtes d'affilée, puis une par seconde ; au-delà, `429` avec `Retry-After` | `ApiSubscriber`, `config/packages/rate_limiter.yaml` |
| Requête forgée vers l'intérieur par un document de métadonnées de client OAuth | `NoPrivateNetworkHttpClient` de Symfony (ni réseau local, ni boucle locale, y compris après résolution DNS), sans redirection, 64 Ko au plus, délai de 10 s ; document qui doit se désigner lui-même | `ClientMetadataDocuments` |
| Détournement du flux OAuth de l'API | Inscription limitée, adresses de retour exactes (HTTPS ou boucle locale), PKCE S256 obligatoire, code à usage unique de 60 s, `state` et `iss` renvoyés, consentement explicite derrière la connexion complète | `OAuthServerController`, `ClientRedirect` |
| Requête depuis un autre site vers MCP | Hôte vérifié (DNS rebinding) ; en-tête `Origin` refusé en `403`, avant même le pare-feu, s'il n'est ni le site ni une origine de `MCP_ALLOWED_ORIGINS` ; aucun cookie : jeton porteur uniquement | `config/packages/mcp.yaml`, `ApiSubscriber` |
| Vol d'une sauvegarde | Données patrimoniales chiffrées dans l'archive ; archives réservées à root (`0600`, dossier `0700`) ; `AUTH_SECRET_KEY` hors de l'archive, à conserver à part ; copie hors du serveur chiffrée avec age pour des clés publiques seulement, jamais en clair. Restent lisibles : adresses e-mail, empreintes de mots de passe, journal des connexions et sessions ouvertes | `docs/backup/`, `docs/backup.md` |
| Fuite de la base, des sauvegardes ou des sessions | Données chiffrées (XChaCha20-Poly1305) avec une clé par utilisateur, enveloppée par son mot de passe (Argon2id) et sa clé de récupération ; chiffré lié à sa ligne et à son propriétaire ; en session, clé enveloppée sous une clé qui ne vit que dans un cookie `HttpOnly` | `Vault`, `DataKeys`, dépôts |
| Fuite de la base | Secrets TOTP chiffrés (XChaCha20-Poly1305) et codes de secours en HMAC-SHA256, avec des sous-clés de `AUTH_SECRET_KEY` (environnement uniquement) ; jetons envoyés par e-mail stockés sous forme d'empreinte | `AuthSecrets`, `OneTimeTokens` |
| Fuite de `AUTH_SECRET_KEY` | Rotation sans réinitialiser les utilisateurs : ancienne clé dans `AUTH_SECRET_KEY_PREVIOUS` le temps de rechiffrer les secrets TOTP (`app:auth-secrets:rotate`) et de faire régénérer les codes de secours (`docs/installation.md` [§ 12.1](docs/installation.md#changer-la-cle-auth_secret_key)) | `AuthSecrets`, `AuthSecretsRotateCommand` |
| Fuite d'un export de données | Fichier en clair, par nature : export après confirmation d'identité de moins de 5 minutes, avertissement à l'écran ; import tout ou rien, dans un compte vide, avec les validations de l'API | `DataTransferController`, `DataTransfer` |
| Énumération des comptes | Même réponse et même coût (un hachage Argon2id) pour une adresse inconnue ou un mot de passe faux ; inscription d'une adresse existante : même page, e-mail d'information à son titulaire ; adresse non confirmée signalée seulement avec le bon mot de passe | `PasswordLoginAuthenticator`, `Registration` |
| Inscription au nom d'autrui | Lien de confirmation (24 h, usage unique) **et** mot de passe de l'inscription exigés ; une nouvelle inscription d'une adresse non confirmée invalide les anciens liens ; limites par IP (10 par heure), toutes adresses confondues (30 par heure) et par compte (3 e-mails par heure) | `Registration`, `RegistrationController`, `AuthThrottle` |
| Création de comptes en masse, essais de mots de passe en rafale | Inscriptions plafonnées par IP et au total ; échecs de connexion limités par IP, par compte et au total ; dans nginx, 30 envois de formulaire de connexion par minute et par adresse (429) | `AuthThrottle`, `docs/nginx/patrimeter-limits.conf` |
| Compte de démonstration détourné (stockage, envoi d'e-mails, accès durable) | `DEMO_MODE` : compte temporaire sans mot de passe ni adresse réelle, clé de données seulement en session ; limité au patrimoine, au budget et à l'export (ni réglages de sécurité, ni API, ni import, ni e-mail) ; 2 000 lignes, formulaires de 32 Kio, écritures limitées ; 50 comptes à la fois, 5 créations par IP et par heure, 60 au total ; supprimé à la déconnexion ou au bout de 2 heures | `DemoAccounts`, `DemoGuard` |
| Rappel par e-mail détourné | Rappel mensuel au choix de l'utilisateur, sans montant ni statut (le serveur ne lit rien hors session), une fois par mois au plus, jamais pour un compte de démonstration ; lien de désabonnement à usage unique, seule son empreinte est gardée, page puis bouton (un analyseur de liens ne désabonne personne) | `EntryReminders`, `ReminderController` |
| Requêtes vers des adresses inconnues (sondes, exploits) | nginx ne transmet à PHP que les adresses de l'application (liste blanche testée) et n'accepte PUT, DELETE et OPTIONS que pour l'API et le MCP ; le reste reçoit 404 ou 403 sans exécuter de code | `docs/nginx/patrimeter-app.conf`, `NginxAllowlistTest` |
| Liens empoisonnés (en-tête Host) | Liens des e-mails construits depuis `DEFAULT_URI`, jamais depuis la requête | `PublicUrl` |
| Vol ou fixation de session | Nouvel identifiant à la connexion, cookie `HttpOnly`, `SameSite=Strict`, `Secure` en HTTPS (préfixe `__Host-` en production) ; expiration après 30 min d'inactivité et 12 h au plus ; secret TOTP jamais écrit en session ; sessions fermées au changement de mot de passe ; sessions ouvertes listées (adresse IP, navigateur, dernière activité), à déconnecter une à une ou toutes ensemble, effet à la requête suivante | `framework.yaml`, `SessionIdleTimeoutSubscriber`, `User`, `UserSessions` |
| Navigateur de confiance détourné | Case à cocher explicite, jamais par défaut ; cookie aléatoire `HttpOnly`, `SameSite=Strict`, `Secure` et préfixe `__Host-` en HTTPS, dont seule l'empreinte est stockée ; valable 30 jours, pour ce compte seul, et seulement avec les identifiants du moment (nouveau mot de passe ou sessions fermées : caduc) ; dispense du code, jamais du mot de passe ; listé et retiré depuis Mon compte ; connexion sans code notée au journal | `TrustedBrowsers`, `SecondFactorCondition` |
| Session volée utilisée pour prendre le compte | Mot de passe actuel exigé pour le changer ; confirmation d'identité de moins de 5 minutes avant de changer d'application TOTP, de régénérer les codes ou de fermer les sessions ; e-mail d'alerte après chaque changement | `Reauthentication`, `SecurityController` |
| Requêtes intersites (CSRF) | Jeton vérifié sur **tous** les POST avant tout contrôleur, régénéré à la connexion ; déconnexion et code TOTP en POST avec jeton | `CsrfProtectionSubscriber` |
| Injection de script (XSS) | Échappement automatique de Twig ; CSP stricte sans `unsafe-inline` (aucun script ni style inline, QR code en SVG sans style) ; données JSON encodées sans caractère pouvant fermer une balise ; toute violation de la CSP signalée par le navigateur (`report-uri`, `report-to`) et journalisée dans `var/log/csp-*.log`, adresses sans requête et jetons masqués, débit limité par IP | `SecurityHeadersSubscriber`, `HistoryController`, `QrCodeSvg`, `CspReportController` |
| Injection SQL | Requêtes préparées uniquement, SQL confiné à `src/Infrastructure/Persistence/` ; analyse de taint (Psalm) en CI | dépôts |
| Redirection ouverte | Adresses de retour limitées aux chemins internes | `InternalPath`, `TwoFactorRedirects` |
| Intégration dans un cadre (clickjacking) | `frame-ancestors 'none'`, `X-Frame-Options: DENY` | en-têtes |
| Fuites par le navigateur | `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, `Permissions-Policy`, COOP/CORP | en-têtes |
| Interception (si HTTPS) | HSTS d'un an | en-têtes |
| Exposition de fichiers | Seul `public/` est servi ; fichiers cachés et scripts PHP autres que le contrôleur frontal refusés par nginx ; `open_basedir` et fonctions d'exécution désactivées dans php-fpm | `docs/nginx`, `docs/php-fpm` |
| Messages d'erreur trop bavards | Pages d'erreur génériques en production, détails uniquement dans `var/log/prod.log` ; échec d'envoi d'e-mail journalisé sans le contenu du message | `templates/bundles/TwigBundle/Exception/`, `AccountMailer` |
| Secrets dans le dépôt | Secrets uniquement dans `.env.local` (non versionné, 0640) ; clé de développement (nulle) refusée en production ; Gitleaks sur tout l'historique en CI | `.env.example`, `AuthSecrets`, CI |
| Dépendances vulnérables | `composer audit` en CI ; **pipeline de sécurité chaque lundi à 6 h** (`composer audit`, `npm audit`, Trivy, Semgrep, Gitleaks, SAST et détection de secrets de GitLab, ZAP, Nuclei, et Nuclei sur l'instance déployée), même sans nouveau code, échec signalé par e-mail par GitLab ; **Renovate chaque jour** : merge requests de mise à jour (PHP, Node, images et outils de la CI, image du conteneur), versions publiées depuis 14 jours, une faille connue sans attendre ; pratiques du projet notées par **OpenSSF Scorecard** dans chaque pipeline de `main` ; images CI épinglées par empreinte ; Chart.js copié avec empreinte vérifiée | CI (`docs/ci.md`), `assets/vendor/chart.js/README.md` |
| Modification du code hors revue | Branche `main` protégée : aucun push direct ni forcé, fusion par merge request seulement | réglages du projet GitLab |

## Journal des connexions

Chaque utilisateur voit, sur sa page Mon compte, ses 30 derniers événements (180 jours conservés) :
connexions, mots de passe et codes faux, codes de secours utilisés, confirmations d'identité,
changements de mot de passe, d'application ou de codes, fermeture des autres sessions. Seuls le
type, la réussite, l'adresse IP, le navigateur et la date sont enregistrés, jamais un secret.

## Limites connues

- **Administrateur actif** : le chiffrement protège contre un accès passif (base, sauvegardes,
  sessions, journaux). Un administrateur qui modifie le code peut capturer un mot de passe à la
  connexion suivante ; aucune application web n'y échappe.
- **Métadonnées visibles de l'administrateur** : adresses e-mail, nombre de comptes, de relevés et de quotes-parts,
  mois saisis, nombre de catégories et de postes du budget et mois où ses montants ont été enregistrés, nombre de
  membres d'un compte famille, nombre d'objectifs d'épargne, ordre des catégories et des postes du budget, jour du
  rappel mensuel choisi, présence d'un lien avec Carbure, dates techniques, journal des connexions, sessions
  ouvertes et navigateurs de confiance (adresse IP, navigateur, dates). La commande `app:user:list` en montre une partie
  (adresses, dates, nombre de comptes, dernière connexion), sans rien déchiffrer.
- **Carbure** : l'adresse de l'instance vient de l'utilisateur. Patrimeter n'appelle que `…/api/mcp`, en POST, sans
  redirection, avec délai et taille bornés, ne renvoie jamais la réponse, et limite les appels par utilisateur ;
  `CARBURE_ALLOWED_HOSTS` restreint les hôtes sur une instance ouverte au public.
- **Mot de passe perdu sans clé de récupération** : les données sont définitivement perdues.
- **Mode démonstration** : désactivé par défaut ; activé (`DEMO_MODE=true`), il laisse quiconque atteint la page de
  connexion créer un compte temporaire. Ne l'activez que sur une instance consacrée à la démonstration
  ([réglages](docs/reglages.md)).
- **Mots de passe divulgués** : la vérification interroge l'API de Have I Been Pwned (5 premiers caractères de
  l'empreinte SHA-1 seulement). Serveur sans accès sortant ou service injoignable : elle est sautée, et seule
  l'entropie est contrôlée.
- **Sans HTTPS**, le mot de passe, le code et le cookie de session circulent en clair sur le
  réseau. Derrière un proxy inverse, le dernier tronçon jusqu'au serveur reste en HTTP : à réserver
  à un réseau de confiance, et à limiter à l'adresse du proxy (`allow` du vhost).
- **Jeton d'API** : qui le détient lit les données en clair jusqu'à son expiration ou son retrait. Un client MCP
  transmet ce qu'il lit à son fournisseur.
- **Bibliothèques expérimentales** : `symfony/mcp-bundle` et `mcp/sdk` ne garantissent pas encore leur
  compatibilité entre versions ; le service MCP est vérifié en CI à chaque mise à jour.
- **Alertes par e-mail** : le message d'une erreur critique part à `ADMIN_EMAIL`. Il ne contient ni secret
  ni donnée patrimoniale (journaux filtrés, données chiffrées), mais peut citer une adresse IP ou un chemin.
- **Restauration d'une sauvegarde** : les mots de passe, TOTP et clés de récupération reviennent à leur
  état d'alors, et ce qui a été supprimé depuis réapparaît ([docs/backup.md](docs/backup.md)).
- **Limitation par adresse IP** : derrière un proxy inverse, `TRUSTED_PROXIES` et `X-Forwarded-For`
  sont indispensables, sinon toutes les tentatives semblent venir du proxy et partagent le même
  compteur.
- **Blocage global** : 100 échecs en une heure, toutes adresses confondues, bloquent toutes les
  connexions jusqu'à ce que les plus anciens sortent de cette heure glissante ; c'est volontaire
  (attaque répartie), au prix d'un déni de service possible.
