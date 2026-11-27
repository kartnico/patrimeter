# Changelog

Toutes les évolutions notables de Patrimeter sont consignées ici. Format :
[Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; numérotation :
[versionnage sémantique](https://semver.org/lang/fr/).

## [3.6.0] - 2026-11-27

### Modifié

- **Page Défis redessinée** : vos deux statuts en tête (**Mon niveau**, **Mon profil**), un cadran des étoiles et leur
  répartition par famille de défis, la distance au niveau suivant et au stade suivant, puis la série de saisies. Chaque
  défi montre ses paliers de gauche à droite (acquis, suivant, à venir), ce qui est déjà fait et ce qui manque pour la
  prochaine étoile ; sa règle se déplie. Repères indicatifs, à adapter à votre situation : ce n'est pas un conseil en
  investissement.
- **Premier démarrage plus clair** : un fil « Étape 2 sur 4 » en tête de chaque écran (compte, sécurité, clé de
  récupération, premiers comptes), sans le « Dernière étape » trompeur ; « Pourquoi deux secrets ? » distingue les codes
  de secours de la clé de récupération, qui s'impriment tous deux ; l'accueil recommande les questions, propose ensuite
  la pyramide et replie les trois autres façons de commencer ; un compte famille y devient un vrai choix.
- **Page Membres de la famille** : le statut du mois de chaque membre en mots (« À jour », « À mettre à jour »), son
  nouveau fichier déposé sur sa carte, « Retirer » replié sous « Plus d'actions », et les trois étapes du mois. Sous le
  choix de la vue, une ligne dit ce qu'elle additionne et signale chaque fichier à mettre à jour ; « Ce compte » devient
  « Comptes de la famille », et les soldes importés se comptent « du mois » ou « reportés ».
- CI : le test de la promotion des images Docker Hub tourne dans chaque pipeline complet, avant toute étiquette, et non
  plus seulement au moment de la publication.

### Corrigé

- Publication sur Docker Hub : le job lit la version de l'image testée avec Buildah et partage ses identifiants avec
  Skopeo. En 3.5.0, il était refusé par le registre (403) : l'image 3.5.0 n'a pas été publiée, la 3.6.0 la remplace.

## [3.5.0] - 2026-11-27

### Installation

- Docker Compose : reprenez le fichier Compose de la version pour la configuration recommandée. La sonde HTTP
  concerne le service web ; après un échec de sauvegarde ou de vérification, une nouvelle tentative a lieu après 1 h.
- Linux : réinstaller la même archive préserve les droits des données et les secrets existants.

### Modifié

- Les composants Symfony concernés passent à la version 7.4.24, qui intègre des correctifs de sécurité.
- La liste des comptes ne déchiffre que le dernier relevé applicable à chaque solde affiché.
- Une reconstruction Docker ancienne ne peut plus remplacer une reconstruction plus récente de la même version.

### Corrigé

- La date d’ouverture d’un compte ne peut plus être décalée au-delà du mois d’un relevé existant.
- La copie d’un bien vers un mois antérieur conserve la quote-part initiale du bien et de son crédit au prorata.
- Consulter les défis ou l’API ne consomme plus l’annonce des étoiles lors de la prochaine saisie mensuelle.
- Sur Linux, l’outil de mise à jour 3.5 conserve la dernière saisie validée dans une sauvegarde vérifiée,
  hors rotation. Un retour arrière refuse une copie manquante ou corrompue avant de couper le site.
- Les rappels mensuels peuvent être retentés après une panne de transport de courrier.
- Le calendrier permet de revenir au mois en cours depuis le mois suivant ; les boutons des champs mois et année
  conservent un nom accessible qui identifie le champ.

## [3.4.1] - 2026-11-26

### Installation

- Compose : définissez `PATRIMETER_IMAGE=docker.io/kartnico1/patrimeter:latest` dans le `.env` de Compose.
- Publication facultative des versions sur Docker Hub, GitHub et GitHub Pages depuis la CI GitLab privée.
- Reconstruction système quotidienne et livraison automatique des correctifs Composer vérifiés sur `stable`.

## [3.4.0] - 2026-11-24

### Installation

- **Mode démonstration désactivé par défaut** : pour garder le bouton « Essayer la démo », ajoutez `DEMO_MODE=true`
  aux réglages ([Réglages](docs/reglages.md#activer-le-mode-demonstration)).
- **Sur Linux, configuration nginx à reprendre** (relevés bancaires et import CSV jusqu'à 2 Mo, assistant
  d'installation) ; avec Docker, rien à faire :

  ```sh
  # Configuration nginx de l'application, celle de la nouvelle version, puis rechargement de nginx
  install -m 0644 /srv/patrimeter/docs/nginx/patrimeter-app.conf /etc/nginx/snippets/patrimeter-app.conf
  nginx -t && systemctl reload nginx
  ```

### Ajouté

- **Assistant d'installation** (`/installation`) : sur une instance encore sans compte (comptes de démonstration
  exceptés), la page de connexion y mène ; il vérifie la configuration vue du navigateur (HTTPS, adresse du site
  `DEFAULT_URI`, envoi des e-mails, alertes de l'administrateur), chaque point disant quoi corriger, puis crée le
  premier compte (adresse tenue pour confirmée), connecté et conduit à son application d'authentification. Un seul
  compte peut être créé ainsi, même par deux visiteurs à la fois ; ensuite, la page n'existe plus. La commande
  `app:user:create` reste disponible.
- **Indicateurs personnalisés** : vos propres totaux, des niveaux de la pyramide et des comptes choisis (chaque
  compte compté une fois), suivis chaque mois comme le patrimoine net, avec leur part du patrimoine si vous le
  souhaitez ; page **Indicateurs** (valeurs du mois, écart sur un mois, douze derniers mois de chacun), cadre **Mes
  indicateurs** sur la page Patrimoine. Chiffrés comme les autres données, ils suivent l'export (format 4, les
  fichiers 1 à 3 s'importent toujours) et la rotation des clés
  ([indicateurs](https://kartnico.github.io/patrimeter/fr/utilisation/indicateurs/)).
- **Lire un relevé bancaire** sur la fiche d'un compte : le fichier exporté par la banque (OFX ou CAMT.053) donne
  son solde de fin de période et sa date, qui remplissent le formulaire du relevé, à vérifier puis enregistrer ;
  rien n'est enregistré ni conservé avant. Fichier QIF ou CSV (sans solde), en devise étrangère ou à plusieurs
  comptes : refusé avec un message qui dit quoi exporter ; document XML sans DOCTYPE, ni accès réseau
  ([comptes](https://kartnico.github.io/patrimeter/fr/utilisation/comptes/#lire-un-releve-bancaire)).
- **Page « Réglages »** dans la documentation : changer un réglage à tout moment, sur Linux comme avec Docker
  (fichier à modifier, service à redémarrer), avec des exemples (mode démonstration, serveur d'e-mails) et les
  principaux réglages, leur valeur par défaut et leur effet.

### Modifié

- **Mises à jour entièrement automatiques** : chaque merge request de Renovate, versions majeures comprises, est
  fusionnée d'elle-même dès que son pipeline est vert ; plus de tableau de bord ni d'approbation ; le fichier de
  verrouillage des dépendances PHP est rafraîchi chaque jour. doctrine-bundle 3, qui exige PHP 8.4, est retenu
  jusqu'à la 4.0.0 ([docs/ci.md](docs/ci.md#renovate)).

### Corrigé

- **Twig 3.31.1** : correctifs de sécurité publiés les 23 et 24 novembre 2026 (échappement automatique, bac à sable),
  détectés par `composer audit`.
- **Import CSV de plus de 64 Ko** : nginx le refusait (erreur 413) alors que l'application accepte 1 Mo ; il suit
  désormais la limite de l'import de données (vérifié par le job `deployment`).

## [3.3.0] - 2026-11-24

Version de qualité : aucun changement d'écran ni de base de données, rien à faire à la mise à jour.

### Ajouté

- **Analyse SonarQube** dans la CI (job `sonarqube`) : bugs, vulnérabilités, mauvaises pratiques, duplication et
  couverture du PHP, avec la barrière de qualité du serveur ; le job échoue si elle n'est pas franchie. Sur `main` et
  dans les pipelines complets (ou à la demande, `SONARQUBE=1`) ; serveur et jeton dans les variables `SONAR_HOST_URL`
  et `SONAR_TOKEN` ; SonarScanner épinglé par sa version et son empreinte, mis à jour par Renovate
  ([docs/ci.md](docs/ci.md#sonarqube)).

### Modifié

- **Journal des versions** : uniquement des versions, sans section « Non publié » ; les changements s'écrivent sous
  la version qu'ils préparent.
- **Documentation** : « Linux » partout où le lecteur installe Patrimeter (README, accueil, prérequis, commentaires
  des fichiers nginx et php-fpm) ; Debian n'est plus cité que pour l'image de conteneur et la CI, qui en partent.
- **Configuration de Renovate migrée** (`dependencyDashboard` devient un objet), comme le demandait son tableau de
  bord ; vérifiée par `renovate-config-validator --strict`.

### Corrigé

- **Test de charge fiable** : k6 place la base, les sessions et le cache en mémoire et active OPcache comme en
  production ; il mesure l'application et non plus les blocages du disque du runner, qui le faisaient échouer sans
  aucune différence de code (p95 de 0,85 à 1,5 s pour une médiane de 25 ms). Seuils inchangés.
- **Remarques de la première analyse SonarQube** : expressions régulières au temps d'exécution non linéaire
  réécrites (séparateurs de milliers, adresse d'une instance Mastodon, encodage base64url) ; libellé du champ
  d'identifiant caché destiné aux gestionnaires de mots de passe ; la page Données reprend le formulaire commun de
  confirmation d'identité. Les règles qui contredisent le W3C et les WCAG (zones défilantes atteignables au clavier,
  graphiques décrits par `role="img"`, focus sur le premier champ des pages à un seul formulaire) sont écartées dans
  `sonar-project.properties`, chacune avec sa raison.

### Retiré

- **Page « Paquets Debian requis »**, orpheline et dépassée depuis l'installation par archive : les prérequis sont
  dans [installation.md](docs/installation.md#1-prerequis), les paquets de l'image dans `docker/Dockerfile`.
- **Propositions de la 3.0.0** (`propositions/`), livrées : elles restent dans l'historique git.

## [3.2.0] - 2026-11-24

### Installation

- **Deux installations prises en charge** : Docker (`compose.yaml`) ou Linux, à partir de l'archive d'une version ;
  l'installation depuis git n'est plus documentée. Une installation faite depuis git passe à l'archive une seule
  fois : téléchargez l'archive de cette version et son empreinte dans `/tmp`, puis, en root :

  ```sh
  # Nouveau patrimeter-update, pris dans l'archive
  ARCHIVE="$(ls /tmp/patrimeter-v*.tar.gz | sort -V | tail -n 1)"
  tar -xzf "$ARCHIVE" --strip-components=1 -C /tmp --wildcards '*/docs/update/patrimeter-update.sh'
  install -m 0755 /tmp/docs/update/patrimeter-update.sh /usr/local/sbin/patrimeter-update
  # Mise à jour depuis l'archive ; l'ancien dossier (avec .git) reste dans /srv/patrimeter.previous
  patrimeter-update "$ARCHIVE"
  ```

### Ajouté

- **Tests par propriétés** (fast-check, outil de développement) : pour tout montant tapé dans la saisie du mois, le
  champ garde exactement ce montant une fois mis en forme, et le total du niveau l'affiche.

### Modifié

- **Page « Technologies et licences »** dans la documentation : chaque projet utilisé, avec son site officiel, son rôle
  et sa licence.
- **Titres identiques d'une page à l'autre** : le nom de la page en gras, puis le mois en gris (« Saisie Novembre
  2026 » sur les trois onglets de la saisie, comme « Budget Novembre 2026 ») ; les boutons d'en-tête au même format.
- **Mois et années choisis dans un calendrier** : les champs de mois (copie d'un autre mois, relevé d'un compte,
  objectifs) s'affichent comme le sélecteur des en-têtes (« Oct. 2026 ») ; les années de naissance du foyer se
  choisissent dans un calendrier des années. Sans JavaScript, le champ texte reste.
- **Style d'épargnant proposé dès les premiers pas** : la carte « Premiers pas » invite à le choisir tant qu'aucun
  ne l'est.
- **Défis plus clairs** : score en clair (« 1 sur 3 ») et, sur chaque carte, l'échelle des étoiles, de la première en
  bas (gagnée) à la dernière en haut, chaque palier disant ce qu'il demande ; total « 8 étoiles sur 28 » et échelle
  des six niveaux ; le style d'épargnant se choisit dans Mon profil ; la clé d'accès quitte les **Premiers pas**
  (cinq étapes), elle reste dans le défi **Compte protégé**. Toujours avec le rappel « Repères indicatifs, à adapter à
  votre situation : ce n'est pas un conseil en investissement. »
- **Niveau des défis dans le cadre du patrimoine net** (« ★ Niveau 4 : Prévoyant · 15 étoiles »), avec un lien vers
  la page Défis ; calculé à partir des étoiles déjà gagnées, sans ralentir la page.
- **Plus de menu « Mes biens »** : les boutons **Comptes** et **Biens immobiliers** sont en haut de la page
  Patrimoine, à côté de **Partager** ; le foyer fiscal s'ouvre depuis la page Budget.
- **Saisie du mois à droite de tous les menus**, toujours mise en avant (en gras, encadrée) ; en rouge avec un point
  tant que le mois n'est pas saisi.
- **Page Budget alignée sur Objectifs d'épargne** : titre « Budget » et mois en gris, bouton **Configurer foyer
  fiscal et catégories** en haut, un seul bouton **+ Saisir le budget** quand le mois n'a pas de budget.
- **`patrimeter-update` installe une archive** téléchargée par l'administrateur : empreinte vérifiée, base
  sauvegardée, nouvelle version installée à côté de l'ancienne, migrations, redémarrage ;
  `patrimeter-update --rollback` remet la version et la base d'avant. Plus de git, de Composer ni d'accès à
  Packagist sur le serveur.
- **Docker** : la base est sauvegardée à chaque nouvelle version, même sans migration
  (`var/backups-before-update`, les trois dernières) ; `compose.yaml` ne construit plus l'image, il la tire du
  registre.
- **Documentation d'installation et de mise à jour réécrite** : prérequis en tête de page, commandes commentées et
  prêtes à copier, sans numéro de version à tenir à jour ; Release avec liens directs vers l'archive, son empreinte,
  `compose.yaml` et `patrimeter.env.example`.
- **Politique de sécurité** : lien direct pour signaler une faille (issue confidentielle), délais de réponse et de
  correction, divulgation coordonnée.

### Corrigé

- **Archive de livraison** : fichiers et dossiers n'étaient plus inscriptibles par le groupe (`git archive` les
  créait en 775 et 664).

## [3.1.0] - 2026-11-24

### Installation

- **Migrations** : une table pour les soldes « à affiner » et une colonne pour le taux d'épargne visé ; elles
  s'appliquent avec la mise à jour habituelle. Aucune autre adresse ni variable : nginx et `.env.local` ne changent
  pas.

### Ajouté

- **Défis dans l'API et le service MCP** : `GET /api/v1/challenges` et l'outil `get_challenges` donnent les étoiles,
  le niveau, le stade et le style d'épargnant, la série de saisies et chaque défi avec ses paliers, avec le rappel
  « pas un conseil en investissement » ; décrits dans `docs/openapi.yaml`.
- **Série de saisies dans le rappel mensuel** : avec les défis activés, l'e-mail donne la série en cours et ce que la
  saisie du mois lui apporterait (nouvelle étoile ou prochain palier), avec le rappel « pas un conseil en
  investissement » ; calculé à partir des seuls mois saisis et des étoiles gagnées, toujours sans aucun montant.
- **Questions simples pour débuter** : un cinquième choix à la première connexion, une question à la fois en mots
  courants, avec des montants par tranches, un montant exact ou « Non » ; « Pourquoi cette question ? » dit le niveau
  de la pyramide concerné ; la dernière question, facultative, choisit le style d'épargnant.
- **Soldes « à affiner »** : les premiers soldes créés par les pages de bienvenue (pyramide, saisie express,
  questions) sont marqués dans la saisie du mois jusqu'à leur prochain enregistrement (table `approximate_statements`,
  sans montant).
- **Défi « Taux d'épargne tenu »** : les mois de suite où le budget prévoit une épargne au moins égale au taux que
  l'utilisateur choisit lui-même sur la carte du défi (de 5 % à 50 %, aucun taux proposé par défaut) ; trois
  étoiles de plus (29 en tout), aussi dans l'API et le MCP.
- **Étoiles et niveau sur le rapport** : **Afficher mes étoiles** les ajoute à la carte et à l'image partagée, sur
  demande et sans aucun montant (son propre patrimoine, défis activés).

### Modifié

- **Saisie du mois plus compacte** : un bouton « = » (ou la touche `=`) sous chaque champ reprend le solde du mois
  précédent et passe au compte suivant ; un niveau dont tous les soldes sont déjà saisis ce mois-ci est replié, avec
  son total ; l'écart du patrimoine net avec le mois précédent se suit pendant la frappe.

- **Mise à jour sur une seule page** (`docs/mise-a-jour.md`) : `patrimeter-update`, vérification et retour arrière,
  commandes commentées et prêtes à copier ; l'installation y renvoie. README plus court.

### Corrigé

- **k6 de nouveau seul sur le runner** : il attend désormais tous les jobs des étapes test, e2e et security (Nuclei,
  ajouté en 2.25, analysait l'application pendant la mesure et faisait échouer le seuil p95 sans aucune erreur), et
  la publication de la documentation l'attend ; vérifié par `PerformancePipelineTest`.
- **Page Patrimoine aussi rapide qu'en 2.26** : la carte « Premiers pas » ne mesure plus que ses six étapes, par de
  simples requêtes, au lieu de tous les défis (objectifs, coussin de sécurité, séries) ; elle n'est plus calculée du
  tout une fois les six étapes faites ou la carte masquée. Les autres défis se mesurent sur la page Défis et après la
  saisie du mois.

## [3.0.0] - 2026-11-23

Premier lancement guidé et défis : un nouvel utilisateur construit sa pyramide en quelques minutes, puis des étoiles
récompensent ses bonnes habitudes. Repères indicatifs, à adapter à votre situation : ce n'est pas un conseil en
investissement.

### Installation

- **Migration** : deux tables pour les défis et les étoiles ; elles s'appliquent avec la mise à jour habituelle.
- **nginx** : `/defis` et `/bienvenue` rejoignent la liste des adresses de l'application dans
  `docs/nginx/patrimeter-app.conf` (reprendre le fichier ; il fait partie de l'image du conteneur). Sans cela, ces
  pages répondent 404.
- **Configuration** : nouvelle variable facultative `GAMIFICATION` (`true` par défaut ; `false` retire les défis de
  toute l'instance).

### Ajouté

- **Premiers comptes** (page Patrimoine, tant qu'aucun compte n'existe) : quatre façons de commencer. **Construire ma
  pyramide** montre la pyramide vide et ses six niveaux : chaque niveau propose ses comptes courants (compte courant,
  Livret A, LDDS, PEA, assurance-vie…) avec le solde d'aujourd'hui, et se colore une fois rempli. **Saisie express**
  réunit tous ces comptes sur une page. Les comptes sont créés ensemble, ouverts ce mois-ci avec leur solde, tout ou
  rien ; un crédit se saisit par son capital restant dû. Restent l'import CSV et la création compte par compte.
- **Défis et étoiles** (menu **Défis**) : huit défis de bonnes habitudes (premiers pas, série de saisies avec un mois
  oublié pardonné par an, patrimoine à jour, coussin de sécurité, sans crédit à la consommation, objectif en route,
  budget tenu à jour, compte protégé), jusqu'à trois étoiles chacun ; une étoile gagnée ne se perd jamais. Les étoiles
  donnent un niveau de 1 à 6.
- **Profil d'épargnant** : un stade calculé chaque mois (Découverte, Organisé, Protégé, Serein, Bâtisseur) et un style
  facultatif (Fourmi, Projet, Foyer, Horizon), qui change seulement l'ordre des défis. Ni rendement, ni part d'un
  niveau, ni produit, ni classement entre utilisateurs : un test d'architecture le vérifie.
- **Carte « Premiers pas »** sur la page Patrimoine : six étapes avec un lien vers chacune, une étoile chacune,
  masquable.
- **Étoiles annoncées** après la saisie du mois.
- **Défis désactivables** par chaque utilisateur (page Défis ou Mon profil), ses étoiles gardées.

### Modifié

- **Navigation** : l'entrée **Défis** suit Saisie du mois ; la barre du bas d'un téléphone s'adapte au nombre
  d'entrées.
- **Page Patrimoine sans compte** : les quatre façons de commencer remplacent « Créer un premier compte » ; ensuite, la
  carte « Premiers pas » remplace « Complétez votre patrimoine », qui reste si les défis sont désactivés.
- **Renovate** : image complète (Node, PHP et Composer déjà installés), sans jeton GitHub, chaque jour à 3 h ; l'image
  Node de la CI est cherchée sur Docker Hub ; Buildah retenu sous 1.44 et endroid/qr-code sous 6.1 (PHP 8.4 requis) ;
  notes de version non récupérées.
- **Propositions de la 3.0.0** (`propositions/3.0.0/`) : idées et maquettes du premier lancement, des défis et de
  l'interface.

### Corrigé

- **Test des écritures en rafale de la démonstration** : il tient compte du temps réel écoulé (instable sur un
  runner lent).
- **Renovate** : une mise à jour des dépendances PHP réécrit aussi `THIRD-PARTY-LICENSES.md` (sinon sa merge request
  échouait sur le test des licences).
- **CI** : Python reste en 3.14 (Semgrep ne s'installe pas encore en 3.15) et OpenSSF Scorecard en 5.6 (la 5.7
  répond 404 sur notre GitLab) ; Renovate ne les propose plus.

## [2.26.1] - 2026-11-18

### Modifié

- **Foyer fiscal** : le nombre d'adultes (un ou deux) et le nombre d'enfants (aucun à 12) se choisissent dans deux
  listes, et le formulaire n'affiche que les lignes de ces personnes, chacune avec son prénom et son année de
  naissance. Les prénoms des enfants ne se saisissent plus séparés par des virgules ; un enfant garde ses postes du
  budget quand son prénom change, et baisser le nombre d'enfants retire les derniers de la liste. Sans JavaScript,
  toutes les lignes s'affichent et seules celles jusqu'aux nombres choisis comptent.
- **Pas un conseil financier** : le README et l'accueil de la documentation le rappellent ; feuille de route 3.0.0 :
  premiers pas guidés (foyer fiscal, comptes, biens) et un peu de ludification pour accompagner l'épargnant.

## [2.26.0] - 2026-11-18

### Ajouté

- **Import CSV de comptes, crédits et biens** (Vos données, « Créer des comptes depuis un fichier CSV ») : une ligne
  par compte (nom, type, date d'ouverture ; au choix solde et son mois, niveau, nature, part d'un bien, bien et mode
  d'un crédit, date de clôture), ajoutée aux comptes existants, tout ou rien, avec la ligne en cause dans le message
  d'erreur. Modèle à télécharger dans la langue de l'interface ; fichiers de tableur acceptés tels quels (séparateur
  `;`, `,` ou tabulation, UTF-8 ou Windows-1252, colonnes en français ou en anglais, dates `JJ/MM/AAAA`, type donné
  par son code ou son libellé). Format documenté, avec la liste des codes de types (documentation, « Import CSV »).
- **Foyer fiscal** : le budget parle de foyer fiscal (« Deux adultes », sans « en couple ») et demande l'**année de
  naissance** de chaque personne ; un enfant qui a 16 ans dans l'année peut avoir ses **propres revenus**, saisis
  avec ceux des adultes et comptés dans les revenus du foyer (flux du budget, export, compte famille).
- **Menu « Mes biens »**, à côté de Saisie du mois : Comptes, Biens immobiliers, Foyer fiscal et catégories.
- **Mois en cours de la barre du haut** cliquable : il ramène au mois en cours, sur la même page quand elle affiche
  un mois.
- **Premiers pas** : la page Patrimoine vierge propose de créer un compte ou un bien immobilier (ou l'import CSV),
  puis guide vers ce qui manque tant qu'un compte et un bien n'existent pas ; la partie Budget de la saisie du mois,
  sans foyer fiscal, le dit avec un seul bouton, **Créer mon foyer fiscal**.
- **Pyramide du patrimoine** : survoler ou cliquer un niveau surligne ses lignes dans les autres cadres (détail par
  niveau, répartition des placements, depuis le début de l'année), et une ligne survolée surligne son niveau ; un
  clic garde le surlignage, Échap le retire.
- **Langue des e-mails** dans le profil : français, anglais, ou celle de l'interface au moment de l'envoi (par
  défaut) ; elle vaut pour tous les e-mails, rappel mensuel compris.
- **Codes de secours et clé de récupération** : un bouton **Copier les codes** (un code par ligne) et un bouton
  **Copier la clé**, pour les coller dans un gestionnaire de mots de passe (affichés quand le navigateur autorise la
  copie ; confirmation annoncée aux lecteurs d'écran).
- **Renovate chaque jour** (planification `RENOVATE=1`, job `renovate`, `renovate.json`) : merge requests de mise à
  jour des bibliothèques PHP et de leurs dépendances (dans les contraintes de `composer.json`, fichier verrou rafraîchi
  le lundi), des outils Node, des images et outils de la CI (Composer, k6, Nuclei, OpenSSF Scorecard, Docker Compose,
  Semgrep, Zensical, Renovate lui-même) et de l'image Debian du conteneur. Versions publiées depuis 14 jours au moins,
  une faille connue sans attendre ; versions majeures après approbation sur le tableau de bord des dépendances ;
  empreintes SHA-256 et étiquette de l'image CI recalculées par `ci/renovate-post-upgrade.sh`. Ce pipeline ne lance
  que Renovate (`RenovatePipelineTest`).
- **Feuille de route 3.0.0** : mode famille à retravailler.

### Modifié

- **Alertes de sécurité par e-mail** : après une action qui a pu fermer l'accès au compte (mot de passe changé ou
  réinitialisé, application d'authentification remplacée, compte supprimé), l'e-mail ne conseille plus de changer le
  mot de passe depuis Mon compte, une impasse pour qui ne peut plus se connecter : il invite à prévenir
  l'administrateur du serveur.
- **Sans aucun compte**, les pages Patrimoine et Saisie du mois restent sur le mois en cours (ni flèches ni
  sélecteur), celui où se créent les premiers comptes.
- **Objectifs** : le bouton « + Fixer un objectif », quand aucun objectif n'existe, devient « Voir vos objectifs ».
- **Sans aucun compte**, la saisie du mois ne propose plus de copier les soldes d'un autre mois.
- **Mois écrits avec une majuscule** quand ils sont seuls (barre du haut, titres, colonnes, calendrier), comme en
  anglais ; dans une phrase, la minuscule française reste.
- **Import CSV** : le modèle se télécharge par un bouton, présenté avant le formulaire.
- **Logo de l'en-tête et du rapport** aligné sur l'icône du site (favicon) : le même triangle à la pointe claire,
  aux mêmes couleurs, image du rapport comprise.

### Supprimé

- **Robot de dépendances maison du lundi** (job `dependency-update`, `ci/update-dependencies.sh`,
  `tools/npm-update.mjs`, `tools/dependency-mr.mjs`) : Renovate le remplace.

### Installation

- Une migration (langue des e-mails) : appliquée par la mise à jour habituelle. Sur le projet GitLab : créer la
  planification quotidienne de Renovate (*Build > Pipeline schedules*, branche `main`, `0 5 * * *`, variable `RENOVATE`
  = `1`, [docs/ci.md](docs/ci.md#renovate)) ; le jeton `DEPENDENCY_BOT_TOKEN` existant sert à Renovate ;
  `GITHUB_COM_TOKEN` (jeton GitHub sans droit) est facultatif.

## [2.25.2] - 2026-11-18

### Corrigé

- **Synchronisation Carbure** : l'adresse du service MCP que montre Carbure (`https://…/api/mcp`) était gardée telle
  quelle, et Patrimeter appelait `…/api/mcp/api/mcp`, auquel Carbure répond 404 : la liaison échouait, avec un
  message qui accusait le jeton. Cette adresse est maintenant ramenée à celle de l'instance, et une liaison déjà
  enregistrée ainsi fonctionne sans rien ressaisir. Le message distingue désormais un jeton refusé (401, 403) d'une
  adresse sans service MCP de Carbure (404, 405) ; une aide sous le champ indique que les deux adresses conviennent.

### Ajouté

- **Agents IA** : un bouton **Copier** à côté de l'adresse du service MCP et de celle de l'API REST, dans « Connecter
  un agent IA » (affiché quand le navigateur autorise la copie ; confirmation « Copié », annoncée aux lecteurs
  d'écran).

## [2.25.1] - 2026-11-18

### Corrigé

- **Budget, cadre « Tendances du budget » sur mobile** : les titres des colonnes se chevauchaient ; ils deviennent
  « Dépenses » et « Épargne » (la phrase d'introduction dit déjà qu'il s'agit d'une moyenne par mois), et un titre de
  colonne trop long passe désormais à la ligne au lieu de déborder sur sa voisine.

## [2.25.0] - 2026-11-17

Contrôles du Markdown de la documentation et scan Nuclei, de l'application en CI et de l'instance déployée.

### Installation

- **CI** (développeurs) : `PRODUCTION_URL` (`.gitlab-ci.yml`) donne l'adresse de l'instance déployée que scanne
  `nuclei-production` le lundi ; vide : aucun scan. Rien ne change pour une installation.

### Ajouté

- **Nuclei** dans la CI (ProjectDiscovery, version et empreinte figées, outil de CI seulement) : job `nuclei` sur
  l'application lancée dans le pipeline (modèles HTTP du jour, gravité « low » et plus, sans les modèles intrusifs),
  rapport transmis à la barrière de sécurité ; job `nuclei-production` sur l'instance déployée, sans compte et à débit
  réduit, le lundi et à la demande (`PRODUCTION_SCAN=1`), qui échoue sur une trouvaille High ou Critical sans jamais
  bloquer une livraison ([CI](docs/ci.md)).
- **Markdown vérifié** dans le pipeline rapide : markdownlint (`.markdownlint-cli2.jsonc`, réglé pour Zensical) sur le
  dépôt, `docs/` et `documentation/` ; les ancres des liens entre pages du site vérifiées avec la règle de Zensical
  (`DocumentationLinksTest`).

### Corrigé

- **Listes imbriquées du CHANGELOG et de la page Accessibilité** : indentées de 4 espaces, elles s'affichent enfin
  imbriquées dans le site de documentation.

### Sécurité

- **Outils de développement** : `re2js` imposé en 2.9.1 (`overrides` de `package.json`) pour l'avis GHSA-gg6h-894w-4m9c
  de markdownlint ; l'avis picomatch sans correctif reste exclu jusqu'au 1er décembre, `markdownlint-cli2` compris.

## [2.24.0] - 2026-11-17

Liste des utilisateurs pour l'administrateur ; inscriptions jamais confirmées supprimées au bout de 24 heures.

### Ajouté

- **`app:user:list`** : utilisateurs de l'instance (adresse, création, confirmation de l'adresse, second facteur,
  nombre de comptes, dernière connexion) et totaux, sans rien déchiffrer ; `--json` pour un script
  ([installation, § 6](docs/installation.md#4-base-de-donnees)).
- **Inscriptions non confirmées supprimées** : la tâche planifiée horaire efface une inscription jamais confirmée
  24 heures après le dernier lien envoyé (l'adresse redevient libre) ; jamais un compte qui a des comptes, ni un compte
  de démonstration, ni un compte sans adresse.

### Sécurité

- **Outils de développement** : l'avis GHSA-fc5q-39xc-vjpf (picomatch, ReDoS), sans version corrigée à ce jour, est
  exclu de la barrière jusqu'au 1er décembre, avec sa justification (`security/exclusions.json`) : il ne touche que
  Stylelint, Vitest et Vite, jamais la production.

## [2.23.0] - 2026-11-17

Vue des objectifs redessinée ; jetons d'API reconnaissables, origines du service MCP contrôlées, navigateurs de
confiance.

### Installation

- **Facultatif** : `MCP_ALLOWED_ORIGINS`, origines de pages web autorisées à appeler le service MCP en plus du site
  (séparées par des virgules sans espace ; vide par défaut) ([installation, § 3](docs/installation.md#3-configurer)).
  Rien à faire pour un client hors navigateur (Claude Code, script).
- **Base** : une migration ajoute la table `trusted_browsers` (jouée par la mise à jour).

### Ajouté

- **Navigateurs de confiance** : sur l'écran du code, « Faire confiance à ce navigateur pendant 30 jours » ; les
  connexions suivantes sur ce navigateur ne demandent plus que le mot de passe. Liste dans Mon compte > Connexion et
  sécurité, retrait un à un ou de tous ; un nouveau mot de passe ou la déconnexion des autres sessions les retire
  aussi ; connexion sans code notée au journal.
- **Origines du service MCP** : une requête dont l'en-tête `Origin` n'est ni le site ni une origine de
  `MCP_ALLOWED_ORIGINS` reçoit `403`, avant même le pare-feu (spécification MCP, *DNS rebinding*).

### Modifié

- **Vue des objectifs** : anneau de progression avec un repère « où vous devriez en être », statut en pastille,
  chiffres clés en tuiles (montant actuel, cible, effort nécessaire, rythme constaté), graphique de projection
  interactif (aire colorée, bulle des montants au survol, axe resserré sur les données, cible en trait fin) ; une
  phrase sous le graphique reprend les chiffres.
- **Jetons d'API reconnaissables** : `patrimeter_` suivi de 24 caractères hexadécimaux, `_` et 43 caractères ; un
  détecteur de secrets repère un jeton publié par erreur (règle `patrimeter-api-token` de `.gitleaks.toml`). Les jetons
  `pm1.…` déjà délivrés restent valides jusqu'à leur expiration.
- **Feuille de route** : assistant de première installation, sauvegarde en un fichier, import de relevés bancaires et
  indicateurs personnalisés passent en 3.0.0.

## [2.22.0] - 2026-11-17

Formulaire d'objectif plus simple (listes, champs selon les choix, mois de départ), visite guidée plus régulière,
heure et expéditeur du rappel mensuel ; CI plus rapide (image prête à l'emploi, pipeline d'étiquette réduit).

### Ajouté

- **Mois de départ d'un objectif** : ce mois-ci par défaut, ou un mois passé pour un objectif déjà commencé ; la
  valeur mesurée ce mois-là est le point de départ (recalculé si le mois de départ ou ce que l'objectif mesure change).
- **Rappel mensuel** : il part le jour choisi à partir de 9 h (heure de l'instance), plus jamais la nuit ; la section
  de « Mon compte » donne l'heure et l'adresse de l'expéditeur (`MAIL_FROM`, déjà visible dans chaque e-mail).
- **Démonstration** : lien « Foyer et catégories » dans le menu du compte, à côté de « Comptes » et « Biens
  immobiliers ».
- **Recommandations** : icône devant « Pyramide », comme devant « Épargne de précaution ».

### Modifié

- **Formulaire d'objectif** : « Cible » et « Ce que l'objectif mesure » deviennent des listes ; seuls les champs du
  choix fait s'affichent (montant ou nombre de mois de dépenses, niveau ou comptes). Sans JavaScript, tous restent
  affichés avec le cas auquel ils servent.
- **Visite guidée** : le bouton « Précédent » est toujours présent (grisé à la première étape), donc « Suivant » ne
  change plus de place d'une étape à l'autre.
- **CI** (développeurs) : les jobs PHP partent d'une **image prête à l'emploi** (PHP 8.3 à 8.5 de Surý, pcov,
  Composer), construite par le job `ci-php-image` seulement quand ce qui la compose change, et le lundi ; le
  **pipeline d'une étiquette** ne rejoue plus les contrôles déjà passés sur `main` (règle `.not-tag`) : il construit,
  analyse et publie la livraison ([CI](docs/ci.md)). Chaque pipeline contrôle la syntaxe et fait tourner toutes les
  suites PHPUnit sur **PHP 8.3, 8.4 et 8.5** (8.4 désormais aussi au lint), règle verrouillée par `PhpVersionsCiTest`.

## [2.21.0] - 2026-11-16

OpenSSF Scorecard sur le dépôt privé ; documentation, README et feuille de route relus.

### Installation

- **CI** (développeurs) : créer une fois le jeton en lecture seule d'OpenSSF Scorecard et la variable
  `SCORECARD_TOKEN` ([CI, OpenSSF Scorecard](docs/ci.md#openssf-scorecard)). Rien ne change pour une installation.

### Ajouté

- **OpenSSF Scorecard** dans chaque pipeline de `main`, celui du lundi compris : note des pratiques de sécurité du
  projet (protection des branches, revue, tests, mises à jour des dépendances, épinglage…) lue avec un jeton en
  lecture seule ; rapport dans les artefacts, **badge** dans le README (« non mesuré » sans jeton).

### Modifié

- **Documentation relue** : accueil et démarrage rapide (objectifs, tendances, démonstration, rappel de saisie), menu
  du compte (Documentation), tâches planifiées, architecture, CI et flux de travail ; README et feuille de route à
  jour (la version « Démarrage et saisie » devient la 2.22.0).

## [2.20.0] - 2026-11-16

Objectifs dans la navigation principale et dans la saisie du mois, saisie du mois rappelée dans la navigation ; comptes
et biens accessibles dans la démonstration, délai d'attente d'un nouveau compte de démonstration, lien vers la
documentation, badges de sécurité et de tests.

### Installation

- **Facultatif** : `DOCUMENTATION_URL` (adresse de la documentation d'utilisation, celle du projet par défaut ; vide :
  aucun lien) et `DOCUMENTATION_ON_LOGIN` (`true` par défaut : lien sur la page de connexion)
  ([installation, § 3](docs/installation.md#3-configurer)).

### Ajouté

- **Documentation** dans le menu du compte et sur la page de connexion, dans la langue de l'interface.
- **Badges** du README : `composer audit`, tests de bout en bout (Playwright), SAST (GitLab et Semgrep), Semgrep.

### Modifié

- **Objectifs** dans la barre de navigation principale, entre Budget et Saisie du mois : la page Objectifs montre où en
  est chacun (statut, graphique, derniers mois) ; ils ne sont plus sur la page Patrimoine ni dans Mon compte.
- **Saisie des objectifs** dans Saisie du mois, onglet **Objectifs**, à côté de Patrimoine et Budget.
- **Saisie du mois mise en évidence** dans la navigation tant qu'aucun solde du mois en cours n'est saisi (en gras,
  avec un point ; dit aussi aux lecteurs d'écran).
- **Démonstration** : le menu « Mon compte » mène aux comptes et aux biens immobiliers (au lieu du profil, fermé à la
  démonstration) ; les onglets ne montrent que ce qui lui est ouvert : ajouter un compte ou un bien y est donc
  possible.
- **Démonstration indisponible** : le message dit combien de temps attendre (limite de créations atteinte, ou
  démonstration complète jusqu'à la fin du premier compte en cours).

## [2.19.0] - 2026-11-16

Objectifs en mois de dépenses et comparés au budget, graphique de projection ; tendances sur 3, 6 et 12 mois ; ordre
du budget ; rappel mensuel par e-mail ; objectifs et budget dans l'API et le MCP.

### Installation

- **Migrations** : ordre des catégories et des postes du budget, rappels mensuels ; elles s'appliquent avec la mise à
  jour habituelle.
- **nginx** : `/rappel` (lien de désabonnement des e-mails) rejoint la liste des adresses de l'application dans
  `docs/nginx/patrimeter-app.conf` (reprendre le fichier ; il fait partie de l'image du conteneur).
- Le rappel par e-mail passe par les tâches planifiées existantes et n'envoie rien sans serveur SMTP.

### Ajouté

- **Objectifs en mois de dépenses** : la cible suit chaque mois les dépenses (besoins et envies) du budget.
- **Épargne prévue au budget** comparée à l'effort nécessaire, pour les postes d'épargne rattachés aux comptes de
  l'objectif.
- **Graphique de projection** de chaque objectif : réalisé, trajectoire prévue et projection au rythme actuel,
  distingués par le style du trait et décrits en texte.
- **Tendances** du patrimoine net (page Patrimoine) et du budget (dépenses et épargne moyennes, page Budget) sur 3, 6
  et 12 mois, comparées à la période d'avant.
- **Ordre des catégories et des postes** du budget, avec des flèches dans Foyer et catégories.
- **Rappel mensuel par e-mail** (Mon compte > Profil), au choix : le jour voulu, si les soldes du mois ne sont pas
  saisis, sans aucun montant ; lien de désabonnement sans connexion. Dans l'application, à partir du 20, la page
  Patrimoine signale les soldes du mois non saisis.
- **API et MCP** : objectifs (`GET /api/v1/goals`, outil `list_goals`) et budget du mois (`GET /api/v1/budget`, outil
  `get_budget`) en lecture.

## [2.18.0] - 2026-11-16

Objectifs d'épargne suivis chaque mois, visite guidée de la démonstration.

### Installation

- **Migration** : nouvelle table des objectifs d'épargne ; elle s'applique avec la mise à jour habituelle.
- **nginx** : `/objectifs` rejoint la liste des adresses de l'application dans `docs/nginx/patrimeter-app.conf`
  (reprendre le fichier ; il fait partie de l'image du conteneur).

### Ajouté

- **Objectifs d'épargne** (Mon compte > Objectifs) : un montant à atteindre à une date, mesuré sur le patrimoine net,
  un niveau de la pyramide ou des comptes choisis, à partir de la valeur du mois de création. Chaque mois, l'effort
  encore nécessaire est comparé au rythme des six derniers mois : **Atteint**, **En avance**, **Sur la bonne voie**,
  **À surveiller**, **En retard** ou **Trop tôt pour juger**, avec ce qui manque par mois et le mois où l'objectif
  serait atteint à ce rythme ; statut des derniers mois.
- **Carte « Objectifs d'épargne »** au tableau de bord, et message après la saisie du mois pour chaque objectif dont
  le statut change.
- **Export et import** : les objectifs voyagent avec les données (format version 3 ; les versions 1 et 2
  s'importent toujours).
- **Démonstration** : deux objectifs d'exemple, l'un en retard, l'autre en avance, et leur étape dans la visite.

- **Visite guidée de la démonstration** : elle démarre avec le compte de démonstration et présente, en treize étapes
  et sur plusieurs pages, le patrimoine net, les objectifs, la pyramide, l'historique, la saisie, les comptes, les
  biens, le budget
  et le rapport, en entourant l'élément dont elle parle. Elle s'arrête à tout moment et se relance depuis le bandeau,
  ou reprend à l'étape quittée ; sans JavaScript, elle fonctionne aussi (sans la mise en évidence).

## [2.17.0] - 2026-11-16

Mode démonstration pour essayer Patrimeter sans compte, flèche sur les budgets dépliables.

### Installation

- **Migration** : nouvelle table des comptes de démonstration ; elle s'applique avec la mise à jour habituelle.
- **Mode démonstration activé par défaut** (`DEMO_MODE=true`) : la page de connexion propose « Essayer la démo ». Sur
  une instance privée, ajoutez `DEMO_MODE=false` à `.env.local` (ou à `patrimeter.env` pour le conteneur)
  ([installation, § 3.2](docs/installation.md#3-configurer)).
- **nginx** : `/demo` rejoint les formulaires de connexion dans `docs/nginx/patrimeter-app.conf` (reprendre le
  fichier ; il fait partie de l'image du conteneur).

### Ajouté

- **Mode démonstration** : depuis la page de connexion, **Essayer la démo** ouvre aussitôt un compte temporaire, sans
  inscription, mot de passe ni code, rempli de données fictives dans la langue du visiteur (trois ans de patrimoine,
  résidence principale détenue à moitié avec son crédit, budget d'un foyer de deux adultes et un enfant). Un bandeau
  rappelle l'heure de sa suppression : au bout de 2 heures, ou dès la déconnexion, avec toutes ses données.
- **Garde-fous de la démonstration** : seuls le patrimoine, la saisie, les comptes, les biens, le budget et l'export
  sont accessibles (ni réglages de sécurité, ni API, ni import, ni compte famille, ni Carbure, ni envoi d'e-mail) ;
  2 000 lignes de données par compte, formulaires de 32 Kio, écritures limitées ; 50 comptes à la fois, 5 créations
  par adresse IP et par heure, 60 au total ; comptes expirés supprimés par les tâches planifiées.

### Modifié

- **Budget** : une flèche devant « Budget commun » et chaque « Budget propre à… » montre qu'ils se déplient ; elle
  tourne quand le détail est ouvert.
- **Données des tests de bout en bout et des captures** : les mêmes que celles de la démonstration.

## [2.16.0] - 2026-11-16

Sessions ouvertes visibles et déconnectables, passkeys (FIDO2), copie chiffrée des sauvegardes hors du serveur et
restauration d'essai mensuelle, vérification optionnelle de la dernière version, licences tierces, flux de travail
documenté ; reconstruction du lundi de l'image corrigée.

### Installation

- **Migration** : nouvelle table des sessions ouvertes ; elle s'applique avec la mise à jour habituelle.
- **Serveur Debian** : `patrimeter-update` installe et active la restauration d'essai mensuelle
  (`patrimeter-restore-check.timer`). Depuis l'archive, installez-la comme au
  [§ 1 de la sauvegarde](docs/backup.md#1-mise-en-place).
- **Facultatif** : copie chiffrée des sauvegardes hors du serveur (`apt-get install age`, variables
  `PATRIMETER_BACKUP_AGE_RECIPIENTS` et `PATRIMETER_BACKUP_COPY`, [sauvegarde, §
  2](docs/backup.md#2-copie-hors-du-serveur)) ;
  vérification quotidienne de la dernière version (`UPDATE_CHECK_URL`, `UPDATE_CHECK_TOKEN`,
  [supervision](docs/supervision.md#verifications)). Sans elles, rien ne change.
- **Conteneur** : l'image contient `age` ; les mêmes variables facultatives se renseignent dans `patrimeter.env`.

### Ajouté

- **Sessions ouvertes** (Mon compte > Connexion et sécurité) : un cadre liste, comme le journal des connexions,
  chaque appareil connecté (connexion, dernière activité, adresse IP, navigateur), la session en cours signalée.
  Après confirmation de l'identité, **Déconnecter** ferme une autre session, **Déconnecter mes autres sessions** les
  ferme toutes sauf la vôtre ; l'appareil revient à la page de connexion dès sa requête suivante.
- **Lien « Mettre à jour mon foyer »** dans la saisie du budget, à côté de l'ajout d'un poste et d'un compte.
- **Copie chiffrée des sauvegardes hors du serveur** : l'archive chiffrée avec age pour des clés publiques seulement,
  puis confiée à une commande de copie au choix (`rsync`, `rclone`…) ; une copie en clair est refusée, une copie
  manquée fait échouer la sauvegarde et prévient l'administrateur.
- **Restauration d'essai mensuelle** de la dernière archive (`patrimeter-restore --check-latest`), alerte par
  e-mail en cas d'échec ; dans le conteneur, après chaque sauvegarde.
- **Vérification quotidienne de la dernière version**, désactivée par défaut : une version plus récente est
  signalée par e-mail à l'administrateur, par `/sante` et par une métrique.
- **Licences tierces** : `THIRD-PARTY-LICENSES.md` liste le code tiers livré (dépendances Composer, Chart.js) et sa
  licence, tenu à jour par un test.
- **Flux de travail documenté** ([docs/developpement.md](docs/developpement.md)) : branches, merge requests,
  pipelines et leurs variables, publication d'une version, Release et image, à suivre à la main.
- **Badges** de qualité dans le README.

### Modifié

- **Passkeys** : l'interface précise partout « passkey (FIDO2) » ; dans « Passkeys (FIDO2) et clés de sécurité », le
  rappel de confirmer son identité avant d'ajouter ou de modifier un appareil est mis en évidence.
- **Dépendances** du 16/11 (Doctrine DBAL 4.6, PHPUnit 12.5.44, Psalm 7.7…).
- **Merge request hebdomadaire des dépendances** rédigée en anglais, comme le reste du projet.

### Corrigé

- **Image du lundi** : la reconstruction de la dernière version échouait depuis le rangement de `Dockerfile` dans
  `docker/` (version extraite qui le garde à la racine).
- **Préférences de thème et de langue** : leur durée d'un an se compte en temps réel ; elles ne dépendent plus de
  l'horloge de l'application.
- **Documentation** : nombre de types de comptes, mentions restantes de la connexion par un compte externe.

## [2.15.0] - 2026-11-16

Durcissement et rangement : liste blanche nginx et limites sur les formulaires de connexion, conteneur qui se met à
jour seul, fichiers Docker rangés dans `docker/`, feuille de route par version ; rapport en image à partager sur les
réseaux sociaux ; mois suivant affiché sur la page Patrimoine.

### Installation

- **Serveur Debian, nginx** : deux fichiers à reprendre ([installation, §
  8](docs/installation.md#6-nginx)) :
  `docs/nginx/patrimeter-limits.conf`, nouveau, à copier dans `/etc/nginx/conf.d/`, et
  `docs/nginx/patrimeter-app.conf`, à recopier dans `/etc/nginx/snippets/` ; puis `nginx -t` et
  `systemctl reload nginx`. Sans le premier, nginx refuse le second.
- **Conteneur** : `Dockerfile` et `compose.yaml` sont désormais dans `docker/`, avec le modèle `patrimeter.env.example`
  ;
  les commandes `docker compose` se lancent depuis ce dossier, où se range aussi `patrimeter.env`
  ([conteneur](docs/docker.md#2-installer)). L'image construite par la CI ne change pas d'adresse.
- **Variables booléennes** (`REGISTRATION_ENABLED`, `API_ENABLED`, `MCP_ENABLED`, `SHOW_VERSION`) : documentées avec
  `true` et `false` ; `1` et `0` restent acceptés.

### Ajouté

- **Rapport en image, à partager** : sur téléphone, la carte du rapport occupe tout l'écran ; dessous, Patrimeter en fait
  une image (PNG 1080 × 1350, dans le navigateur) à partager par le partage du téléphone, à enregistrer, ou à publier
  sur Mastodon (instance au choix), X (Twitter), Facebook, WhatsApp ou Instagram. Avec les montants, un avertissement
  rappelle qu'une image publiée les rend publics. Rien n'est envoyé par Patrimeter.
- **Liste blanche nginx** : seules les adresses de l'application atteignent PHP, toute autre reçoit une erreur 404 de
  nginx ; méthodes HTTP limitées à GET, HEAD et POST hors API et MCP ; formulaires de connexion, d'inscription, de mot
  de passe oublié et de déverrouillage limités à 30 envois par minute et par adresse (erreur 429 au-delà). Un test
  vérifie que chaque route de l'application est couverte.
- **Plafond global des inscriptions** : 30 par heure, toutes adresses confondues, en plus de la limite par adresse ;
  un script qui change d'adresse ne crée plus de comptes en masse.
- **Conteneur autonome pour les mises à jour** : avant d'appliquer les migrations d'une nouvelle version, le conteneur
  `app` sauvegarde la base dans le volume des données (`var/backups-before-migration`, trois dernières) ;
  `scheduler` et `backup` attendent que `app` ait préparé la nouvelle version.
- **Feuille de route** (`ROADMAP.md`) par version, les changements structurants en 3.0.0 ; badges de qualité du projet
  sur GitLab.

### Corrigé

- **Mois suivant sur la page Patrimoine** : un mois saisi à l'avance n'était pas proposé dans le sélecteur de mois ;
  il l'est désormais, et l'enregistrement de la saisie y mène.
- **Saisie du budget** : le titre « Revenus du mois » est hors de son cadre, comme les titres des budgets.

### Modifié

- **Rangement** : fichiers Docker dans `docker/`, liste des améliorations (`TODO.md`) remplacée par la feuille de route
  ;
  commentaires de tout le code en anglais et revus, code inutilisé depuis le retrait des comptes externes supprimé.
- **Documentation** : dernières mentions de la connexion par un compte externe retirées (API, première connexion,
  paquets Debian, architecture).

## [2.14.0] - 2026-11-16

Première version publique : connexion par compte externe retirée, script de mise à jour exécutable, documentation
d'installation et de mise à jour sans cas historiques, traductions vérifiées.

### Installation

- **Migration** : la table des comptes externes est supprimée ; elle s'applique avec la mise à jour habituelle.
- **Variables retirées** : `OAUTH_*` ne servent plus ; elles peuvent être ôtées de `.env.local`.
- **Compte créé par un fournisseur externe**, sans mot de passe : il ne peut plus se connecter directement. Sur la
  page de connexion, **Mot de passe oublié ?** avec la clé de récupération lui donne un mot de passe, données
  conservées.

### Retiré

- **Connexion par un compte externe** (Google, GitHub, OIDC), ses comptes liés dans Mon compte et la phrase de
  déverrouillage des comptes sans mot de passe. Le serveur OAuth de Patrimeter, utilisé par les agents IA et le
  service MCP, ne change pas.

### Corrigé

- **Script de mise à jour** : `docs/update/patrimeter-update.sh` (et les scripts de sauvegarde et de restauration)
  sont exécutables tels qu'ils sortent de git ; `patrimeter-update` s'installe désormais avec Patrimeter.

### Modifié

- **Documentation** d'installation et de mise à jour réécrite pour une première installation, sans les cas des
  versions précédentes ; README et documentation sans la connexion externe.
- **Traductions** : textes anglais vérifiés et harmonisés, textes devenus inutiles retirés.

## [2.13.0] - 2026-11-15

Saisies plus confortables : mois prochain, copie du budget, liens d'ajout ; résidence secondaire et biens tangibles
exotiques ; listes de types complètes au clic ; adresse e-mail pour supprimer le compte.

### Installation

- **Migration** : de nouveaux types de comptes s'ajoutent (résidence secondaire et son crédit, biens tangibles du
  niveau Exotique) et le type « Or et métaux précieux » devient « Or » ; elle s'applique avec la mise à jour
  habituelle.

### Ajouté

- **Résidence secondaire** : nouveau type de bien, au niveau Immobilier de la pyramide ; ses crédits ont leur propre
  type, crédit immobilier résidence secondaire.
- **Biens tangibles au niveau Exotique** : argent et autres métaux précieux (platine, palladium), bijoux, montres,
  œuvres d'art et objets de collection, vins et spiritueux, voiture, moto ; l'or garde son type, désormais « Or ».
- **Mois prochain** : la saisie du patrimoine, celle du budget et la page Budget vont jusqu'au mois suivant, pour
  préparer ses soldes ou son budget à l'avance ; le calendrier marque toujours le mois en cours.
- **Copier le budget d'un autre mois** : en haut de la saisie du budget, le formulaire reprend les montants en
  vigueur au mois choisi ; rien n'est enregistré avant l'enregistrement du budget.
- **Liens d'ajout dans les saisies** : ajouter un compte ou un bien immobilier (partie Patrimoine), un poste, une
  catégorie ou un compte (partie Budget), dans le cadre de copie.

### Modifié

- **Listes des types de comptes et de biens** : un clic sur le champ, ou sur sa nouvelle flèche, affiche toute la
  liste ; la frappe filtre toujours.
- **Suppression du compte** : il faut saisir l'adresse e-mail du compte pour confirmer.
- **Budget** : la rubrique « Coût des enfants » n'apparaît que lorsqu'un poste est rattaché à un enfant.

## [2.12.0] - 2026-11-15

Journal des connexions dans l'onglet Connexion et sécurité, mois en cours dans la barre du haut.

### Ajouté

- **Mois en cours** au milieu de la barre du haut, sur toutes les pages une fois connecté : en entier sur ordinateur
  (« novembre 2026 »), abrégé sur téléphone (« nov. 2026 »), où le nom Patrimeter laisse place à son seul logo.

### Modifié

- **Journal des connexions** : il quitte son onglet et devient la dernière section de l'onglet **Connexion et
  sécurité** de Mon compte ; l'adresse `/securite/journal` y mène.
- **Saisie du mois et du budget** : le champ qui reçoit le focus au clavier reste au-dessus de la barre de validation
  collée en bas de l'écran.

### Corrigé

- **Connexion par un compte externe** (Google, GitHub, OIDC) : le cookie de l'échange avec le fournisseur devient un
  cookie de session ; ses 10 minutes de validité restent vérifiées par le serveur. Son expiration ne dépend plus de
  l'horloge du navigateur.

## [2.11.0] - 2026-11-15

Navigation en trois entrées, tout le paramétrage dans Mon compte, saisie du mois en deux parties, historique en bas
de la page Patrimoine, épargne de l'année, robustesse du mot de passe en temps réel, suppression du compte.

### Ajouté

- **Depuis le début de l'année** : sous la source de la répartition des placements, ce qui a été mis ou retiré dans
  chaque niveau de la pyramide depuis fin décembre (ou janvier), et pour le patrimoine net : un aperçu de l'épargne
  de l'année, plus-values et remboursements compris
  ([patrimoine](https://kartnico.github.io/patrimeter/fr/utilisation/tableau-de-bord/#depuis-le-debut-de-lannee)).
- **Suppression du compte** : onglet **Profil** de Mon compte, après confirmation de l'identité, d'une case à cocher et
  d'un dernier message ; le compte et toutes ses données sont supprimés, un e-mail le confirme
  ([Mon compte](https://kartnico.github.io/patrimeter/fr/utilisation/securite/#supprimer-votre-compte)).
- **Saisie du mois en deux parties** : **Patrimoine** (soldes des comptes) et **Budget**, pour le même mois.
- **Robustesse du mot de passe en temps réel** : à l'inscription, au changement de mot de passe, à la
  réinitialisation et au choix d'une phrase de déverrouillage, une barre et un libellé (de Très faible à Très forte)
  estiment la robustesse comme le serveur, et chaque exigence (longueur, sans l'adresse e-mail, assez imprévisible,
  confirmation identique) est cochée ou barrée au fil de la frappe.

### Modifié

- **Menu principal** : **Patrimoine** (ancien Tableau de bord), **Budget** à sa droite, puis **Saisie du mois**.
- **Mon compte** : nouveaux onglets **Comptes**, **Biens immobiliers**, **Foyer et catégories** et **Carbure** ; ces
  pages gardent leur adresse et quittent le menu principal et la page Budget.
- **Historique** : tout en bas de la page Patrimoine, jusqu'au mois affiché ; le menu Historique disparaît et
  l'adresse `/historique` y mène.
- **Biens immobiliers** : même présentation que les comptes (un tableau par bien, statut de chaque solde, nom du bien
  ou du crédit qui ouvre sa fiche, valeur nette en bas).
- **Onglets de Mon compte** : ils passent à la ligne au lieu de défiler, pour rester tous visibles sur téléphone.

## [2.10.0] - 2026-11-14

Menu du compte et page Mon compte en onglets, onglet Agents IA, mise à jour depuis git en une commande, pied de
page avec copyright et licence, version masquable.

### Installation

- **Mise à jour depuis git par un script**, `patrimeter-update [main | vX.Y.Z]`, comme la sauvegarde : il vérifie,
  sauvegarde, met en place le nouveau code puis laisse le script de la nouvelle version faire ses étapes, et note le
  commit précédent pour le retour arrière. La première fois, lancez-le depuis `main`
  ([mise à jour](docs/mise-a-jour.md)).
- **Nouvelle variable facultative** `SHOW_VERSION` : `0` masque la version au pied des pages (affichée par défaut).

### Ajouté

- **Version masquable** : `SHOW_VERSION=0` masque la version de Patrimeter au pied des pages, page de connexion
  comprise ; elle reste affichée par défaut
  ([installation § 3](docs/installation.md#3-configurer)).
- **Menu du compte** : le bouton **Mon compte** ouvre un menu qui réunit **Mon profil**, **Agents IA** (si l'API ou le
  MCP est activé) et **Déconnexion**.
- **Page Mon compte en onglets** : **Profil**, **Connexion et sécurité**, **Agents IA** (adresses du service MCP et de
  l'API, jetons et applications) et **Journal des connexions**.
- **Pied de page** : copyright, licence (logiciel libre sous licence MIT) et lien vers le projet Patrimeter, avant
  la version.

### Modifié

- **Déconnexion** : elle passe dans le menu du compte.
- **Projet** : commits, merge requests, `TODO.md` et commentaires du code s'écrivent désormais en anglais, pour un
  projet international ; l'interface et la documentation restent en français et en anglais.

## [2.9.0] - 2026-11-14

Compte famille, recommandations du tableau de bord, budget rapproché du patrimoine en graphique, dépenses réelles
lues dans Carbure ; API et MCP désactivés par défaut.

### Installation

- **API REST et service MCP désactivés par défaut** : si vous les utilisez, ajoutez `API_ENABLED=1` et/ou
  `MCP_ENABLED=1` à `.env.local` (ou au fichier d'environnement du conteneur) **avant** la mise à jour
  ([api.md](docs/api.md#activation)).
- **Nouvelle variable facultative** `CARBURE_ALLOWED_HOSTS` : hôtes des instances Carbure que les utilisateurs
  peuvent relier (vide : toutes, réseau local compris) ; à renseigner sur une instance ouverte au public.
- **Configuration de nginx modifiée** : l'import du fichier d'un membre dans un compte famille (`/famille/membres`)
  accepte 2 Mo, comme l'import des données. Depuis git ou l'archive de la CI, recopiez
  `docs/nginx/patrimeter-app.conf` dans `/etc/nginx/snippets/` puis rechargez nginx
  ([installation § 8](docs/installation.md#6-nginx)) ; l'image de conteneur l'inclut déjà.
- Deux migrations créent les tables des membres d'un compte famille et des liens avec Carbure ; la procédure de mise à
  jour l'applique, comme
  l'image de conteneur à son démarrage. Depuis git, ne pas oublier `php bin/console asset-map:compile`. Image de
  conteneur : `docker compose pull && docker compose up -d`.

### Ajouté

- **Compte famille** : chacun des deux membres d'un couple garde son propre compte et en exporte les données chaque
  mois ; un troisième compte les importe (page **Membres de la famille**), le nouvel import remplaçant le précédent :
    - le tableau de bord, l'historique, le rapport et le budget montrent **toute la famille**, **un membre** ou **ce
      compte** ; le choix est retenu d'une page à l'autre ;
    - la famille additionne tout, chacun ayant saisi sa part de ce qui est commun (moitié du compte joint, quote-part
      de la maison, part des dépenses) ; le budget de la famille fait de chaque membre un adulte, réunit les enfants de
      même prénom et se lit en lecture seule ;
    - le fichier importé est validé comme un import ordinaire, puis gardé chiffré avec la clé du compte famille.
- **Recommandations** sur le tableau de bord, à droite de la répartition des placements : épargne de précaution
  rapportée aux dépenses du budget du mois sur une jauge en mois de dépenses, avec un constat (**Alerte** sous un
  mois, **Attention** sous la cible avec le montant qui manque, **Bon point**, ou **À noter** au-delà du double de la
  cible) ; règles de la pyramide : la base d'abord, niveau négatif, écart le plus marqué avec le repère de
  répartition.

- **Carbure** ([github.com/ltoinel/Carbure](https://github.com/ltoinel/Carbure), suivi des dépenses bancaires
  auto-hébergé) : chaque utilisateur relie sa propre instance (**Budget** → **Carbure** : adresse et jeton d'API) ;
  ses catégories de dépenses sont rapprochées de celles du budget (par nom, puis à la main), et la page du budget
  montre le prévu, le réel du mois et l'écart. Lecture seule par le serveur MCP de Carbure, sans nouvelle
  dépendance ; jeton chiffré ; un seul chemin appelé, sans redirection, délai, taille et nombre d'appels bornés.

### Modifié

- **API REST et service MCP désactivés par défaut**, chacun activé par sa variable (`API_ENABLED`, `MCP_ENABLED`),
  comme dans Carbure : un service désactivé répond 404 sur toutes ses adresses ; le serveur OAuth et les jetons
  personnels n'existent que si l'un des deux est activé.
- **Budget, section « Avec le patrimoine »** : chiffres clés (patrimoine net et son évolution, épargne de
  précaution, dépenses du mois), même jauge que le tableau de bord, barres « prévu » et « évolution du solde » pour
  chaque versement d'épargne, avec leur constat.
- **Export des données** : la lecture d'un fichier est partagée par l'import ordinaire et l'import d'un membre.
- **TODO** : idées techniques reprises de Carbure.

## [2.8.0] - 2026-11-13

Budget mensuel du foyer, rapproché du patrimoine du même mois.

### Installation

- Mise à jour ordinaire ([docs/mise-a-jour.md](docs/mise-a-jour.md)) : une migration crée les tables du budget ;
  la procédure de mise à jour l'applique, comme l'image de conteneur à son démarrage. Aucune variable ni
  configuration à changer. Depuis git, ne pas oublier `php bin/console asset-map:compile`. Image de conteneur :
  `docker compose pull && docker compose up -d`.

### Ajouté

- **Budget mensuel du foyer** (menu **Budget**), sur le modèle d'un tableur de couple :
    - foyer d'un ou deux adultes et de leurs enfants ; catégories et postes proposés, à renommer, compléter ou
      supprimer, chaque catégorie rangée dans une famille de la règle 50/30/20 (besoins, envies, épargne) et payée par
      le compte commun ou par un adulte ;
    - saisie des montants d'un mois (revenus nets de chaque adulte, montant de chaque poste), reportés sur les mois
      suivants jusqu'au prochain changement ; totaux recalculés pendant la frappe ;
    - compte commun : total du budget commun, partagé au prorata des revenus ou selon une part fixée ; dépenses et
      épargne propres à chaque adulte, et ce qui lui reste ;
    - flux des revenus en graphique (diagramme de Sankey rendu par le serveur, sans nouvelle bibliothèque), règle
      50/30/20, taux d'épargne, coût de chaque enfant ;
    - rapprochement avec le patrimoine du même mois : épargne de précaution en mois de dépenses face à une cible,
      versements d'épargne face à l'évolution du solde des comptes liés, patrimoine net ;
    - tout est chiffré avec la clé de l'utilisateur, et les routes du budget sont parcourues par le robot de
      cloisonnement.

### Modifié

- **Export des données** : format version 2, avec le budget du foyer ; un fichier de la version 1 s'importe
  toujours. Les caractères `<`, `>`, `&`, `'` et `"` sont échappés dans le fichier.
- **Navigation** : six entrées ; libellés courts sur les écrans de largeur moyenne, pour tenir sur une ligne.
- **Retour à l'affichage d'origine** pour la saisie du mois, les comptes, les biens et le budget : une carte par
  section, titres en gras, police habituelle (plus de chiffres à chasse fixe ni de petites capitales), champs
  encadrés, solde reporté en pointillés sur fond ambré. Les fonctions restent : totaux pendant la frappe, statuts,
  écart avec le mois précédent, raccourcis clavier. La nouvelle présentation est reportée à la version 3.

## [2.7.0] - 2026-11-13

Documentation et README en anglais, nouvelle présentation des comptes et des biens, montants lus dans la langue de la
page, versions à jour et revue de la qualité du code.

### Installation

- Mise à jour ordinaire ([docs/mise-a-jour.md](docs/mise-a-jour.md)) : aucune variable, configuration ni migration
  à changer. Depuis git, ne pas oublier `php bin/console asset-map:compile` : sans lui, l'ancienne présentation des
  comptes reste affichée. Image de conteneur : `docker compose pull && docker compose up -d`.
- Documentation en ligne : nouvelles adresses, `…/patrimeter/fr/` et `…/patrimeter/en/` ; les anciennes mènent au
  site français.

### Ajouté

- **Documentation et README en anglais** : la documentation en ligne existe en français et en anglais, en deux sites
  jumeaux aux mêmes chemins (`…/patrimeter/fr/` et `…/patrimeter/en/`), dont le sélecteur de langue reste sur la même
  page ; la racine ouvre la langue du navigateur et les anciennes adresses mènent au site français. Pages
  d'utilisation et d'exploitation, référence de l'API (description OpenAPI anglaise, `docs/openapi.en.yaml`),
  CHANGELOG et politique de sécurité traduits (`README.en.md`, `CHANGELOG.en.md`, `SECURITY.en.md`,
  `docs/*.en.md`) ; captures d'écran prises dans chaque langue. Les pages techniques du développement (architecture,
  CI…) restent en français. Un test vérifie que chaque page a sa traduction, avec les mêmes sections, blocs de code et
  captures, et que les commandes exécutées par la CI sont identiques dans les deux langues.

### Modifié

- **Nouvelle présentation des comptes et des biens**, dans le style de la saisie du mois :
    - liste des comptes en un tableau par niveau (statut saisi ou reporté, solde du mois en chiffres tabulaires,
      crédits marqués « dû ») ;
    - fiche d'un compte avec son rappel (type, niveau, nature, ouverture), ses relevés et ses parts en tableaux ;
    - biens en tableaux, crédits détaillés et valeur nette en pied ;
    - statut d'un solde en gabarit partagé et styles des tableaux mis en commun (`.sheet`, `.ledger`) ; les champs
      d'une ligne de formulaire ne s'étirent plus à la hauteur de leur voisin.
- **Versions à jour** :
    - CI sur Debian 13 (comme l'image de conteneur et l'installation vérifiée), Node 24 (LTS) et Python 3.14 ;
    - outils JavaScript : Vitest 5 et jsdom 30 ;
    - doctrine/doctrine-bundle 2.19.4 ;
    - les autres outils et images étaient déjà à leur dernière version publiée depuis au moins deux semaines ; les
      versions plus récentes viendront par la merge request du lundi.
- **Documentation** construite en mode strict (un lien ou une ancre cassés font échouer la construction) ; variables
  des commandes en anglais (`$TOKEN`, `$PASSWORD`, `NEW_PHP`), comme le reste du code ; guide de mise à jour : la
  version installée se lit dans `VERSION` ou au pied de page.
- **Qualité du code** : nature d'un compte et mode d'un crédit en énumérations (`Nature`, `LoanMode`) au lieu de
  chaînes ; constantes de classe typées (PHP 8.3), imposées par un test ; tests de mutation étendus au chiffrement
  (MSI de 94 %) ; exemption de `#[\Override]` limitée aux migrations publiées.

### Corrigé

- **Montants sur une page en anglais** : « 1,000 » (mille, à l'anglaise) était refusé comme montant à trois décimales.
  Les montants se lisent désormais dans la langue de la page : virgule des milliers en anglais (« 1,000 »), point des
  milliers en français (« 1.000 ») ; l'autre lecture du même texte, à trois décimales, reste refusée. Même règle
  pour les totaux recalculés pendant la frappe.
- **Message du mot de passe trop court resté en français** sur les pages en anglais (inscription, mot de passe
  oublié, changement de mot de passe) : construit avant d'être traduit, il échappait au traducteur. Un test refuse
  désormais un message de contrainte calculé ; toutes les pages, rendues en anglais, ont été relues.
- **Inscription** : la page qui suit l'envoi du formulaire disait « Si l'adresse peut être inscrite, un message vient
  d'y être envoyé », formule obscure. Elle rappelle désormais l'adresse saisie et explique les deux cas : lien de
  confirmation pour une nouvelle inscription, simple information si l'adresse a déjà un compte. La page reste la même
  dans les deux cas : elle ne révèle toujours pas qui est inscrit.

## [2.6.0] - 2026-11-11

Version affichée sur chaque page, et saisie des mois passés à partir d'un mois suivant.

### Installation

- Mise à jour ordinaire ([docs/mise-a-jour.md](docs/mise-a-jour.md)) : aucune variable, configuration ni migration
  à changer. Depuis git, ne pas oublier `php bin/console asset-map:compile`. Image de conteneur :
  `docker compose pull && docker compose up -d`.

### Ajouté

- **Version affichée** au pied de chaque page, page de connexion comprise (« Patrimeter, version X.Y.Z »). Elle vient
  du fichier `VERSION`, présent dans toute installation (git, archive, image) et accordé au CHANGELOG par un test.
- **Saisie d'un mois passé à partir d'un mois suivant** : sur un mois sans compte ouvert, la page propose de copier
  les soldes d'un mois suivant (le mois courant par défaut) ; la copie reprend aussi les comptes ouverts après le
  mois saisi, et la validation avance leur date d'ouverture au 1er de ce mois (dans la même transaction que les
  soldes). Exemple : Patrimeter commencé en novembre, octobre se remplit en copiant novembre.

## [2.5.0] - 2026-11-11

Déploiement en conteneur (Docker, Docker Compose, Podman), nouvelle saisie du mois, rapport à partager en capture
d'écran, description OpenAPI de l'API, copie d'une saisie, calendrier des mois et listes de types à saisie.

### Installation

- Mise à jour ordinaire ([docs/mise-a-jour.md](docs/mise-a-jour.md)) : aucune variable, configuration ni migration
  à changer. Depuis git, ne pas oublier `php bin/console asset-map:compile` : sans lui, l'ancienne présentation
  reste affichée.
- Nouveau : déploiement en conteneur, décrit dans [docs/docker.md](docs/docker.md).

### Ajouté

- **Pipeline du lundi** ([docs/ci.md](docs/ci.md#pipeline-du-lundi)), en plus des analyses de sécurité :
    - l'image de la dernière version est reconstruite avec les paquets Debian et PHP du jour, démarrée, analysée par
      Trivy et, si tout passe, republiée sous `vX.Y.Z`, `latest` et `vX.Y.Z-AAAAMMJJ` : les conteneurs récupèrent les
      correctifs de sécurité par `docker compose pull`, sans attendre une version ;
    - les dépendances (Composer, outils Node) sont mises à jour, vérifiées par `make lint cs stan psalm test audit js`
      et proposées dans une merge request, en brouillon si une vérification échoue. Jeton à créer une fois :
      `DEPENDENCY_BOT_TOKEN`.
- **Contrôles qualité de la CI** :
    - JavaScript et CSS : ESLint (configuration « flat ») et Prettier, Stylelint ;
    - tests unitaires du JavaScript avec Vitest ;
    - tests de mutation du code métier avec Infection (MSI d'au moins 92 %) ;
    - accessibilité de toutes les pages avec axe-core dans Playwright (WCAG 2.2 AA, thèmes clair et sombre) ;
    - migrations jouées sur une base neuve, et vérifiées à jour (`doctrine:migrations:up-to-date`) ;
    - `lint:yaml` étendu aux traductions et à la description OpenAPI ;
    - conteneur : hadolint au seuil le plus strict, `docker compose config`, construction de l'image tôt dans le
      pipeline, smoke test du conteneur démarré, Trivy sur l'image construite.
- **Description OpenAPI 3.1 de l'API REST** ([docs/openapi.yaml](docs/openapi.yaml)) : chaque opération, ses
  paramètres, ses corps de requête, ses réponses (erreurs comprises) et ses schémas. Publiée dans la documentation en
  ligne : page « Référence de l'API », générée depuis la description à chaque construction, et fichier à importer
  dans un client ou un générateur de code. Un test vérifie qu'elle couvre exactement les routes de l'API et que les
  réponses réelles respectent ses schémas.
- **Déploiement en conteneur, avec Docker et Docker Compose ou Podman** ([docs/docker.md](docs/docker.md)) :
  `Dockerfile` (Debian 13 et PHP 8.5 du dépôt Surý, les versions recommandées ; nginx et php-fpm, mêmes réglages
  qu'un serveur) et
  `compose.yaml` (application, tâches planifiées, sauvegarde quotidienne ; volumes de données et de sauvegardes).
  Migrations appliquées au démarrage, console `patrimeter-console`, contrôle de l'image à sa construction. La CI
  construit l'image avec Buildah, sans démon ni mode privilégié (job `container-image`), et la publie dans le
  registre du projet pour chaque version : `docker pull registry.example.org/kartzone/patrimeter:v2.5.0`.
- **Rapport à partager** : **Partager**, sur le tableau de bord, ouvre une carte du mois de la taille d'un écran de
  téléphone (patrimoine net, évolution sur 1 mois et 1 an, pyramide, actifs et passifs), sans menu, à capturer
  pour l'envoyer en image (WhatsApp, Signal, SMS, e-mail…). Seuls les niveaux y figurent, jamais le nom d'un
  compte ; **Masquer les montants** ne garde que les pourcentages. Rien n'est publié : la page reste privée.
- **Copie d'une saisie** : sur la page de saisie, « Copier les soldes d'un autre mois » remplit le formulaire avec
  les soldes du mois choisi (saisis ou reportés), à vérifier puis valider ; rien n'est enregistré avant. Pratique
  pour remplir une année passée à partir d'un mois déjà saisi.
- **Calendrier des champs de mois** (relevé d'un compte, part d'un bien, copie d'une saisie) : un bouton ouvre la
  grille année + mois du sélecteur de mois ; la saisie AAAA-MM au clavier reste possible.

### Modifié

- **Versions recommandées : Debian 13 et PHP 8.5.** L'image de conteneur publiée est construite avec elles, et
  l'installation documentée est vérifiée par la CI sur elles (PHP 8.3 à 8.5 restent pris en charge). La documentation
  ne cite plus de version de Debian : le dépôt PHP se configure avec le nom de code lu sur le système, la même
  procédure vaut pour toute version. La partie propre aux conteneurs LXC est retirée.
- **Nouvelle présentation de la saisie du mois** : un tableau fin par niveau (compte, statut, solde du mois
  précédent, montant), « Net du niveau » et patrimoine net du mois recalculés pendant la frappe (part des biens
  comprise), écart discret avec le mois précédent sous un montant modifié, compteur « X/N saisis » avec sa mini barre
  de progression. Montants en chiffres tabulaires, alignés sur la virgule, avec « € » partout ; crédits marqués
  « dû », leur « − » collé au montant (accepté à l'enregistrement, avec ou sans signe). Clavier : Entrée passe au
  champ suivant sans envoyer le formulaire, Maj+Entrée au précédent, Échap rétablit le montant proposé. Raccourcis et
  rappels repliés derrière une aide « ? ». Documentation et captures mises à jour (nouvelle capture de l'aide, en
  thème sombre).
- **Types de compte et de bien : liste à saisie** : on tape directement dans le champ « Type » et la liste ne garde
  que les types qui contiennent le texte (sans majuscules ni accents), groupes conservés ; souris, flèches, Entrée,
  Échap. Remplace le champ « Filtrer » séparé ; sans JavaScript, la liste complète reste.
- **Documentation en ligne** : le favicon de Patrimeter remplace celui de Zensical ; chaque renvoi « § » est un
  lien vers la section visée, et les ancres accentuées, ignorées par Zensical, sont corrigées.

### Corrigé

- **Image de conteneur à jour** (relevé par Trivy sur l'image) : les paquets de l'image de base Debian, figée par son
  empreinte, ne recevaient pas leurs correctifs de sécurité (zlib) ; chaque construction les applique désormais. Une
  version n'est plus publiée dans le registre qu'une fois le smoke test et la barrière de sécurité passés.
- **Accessibilité** (relevé par axe-core) : le nom accessible du sélecteur de mois reprend le texte affiché (WCAG
  2.5.3), et les tableaux qui défilent en largeur (journal des connexions, valeurs mensuelles) s'atteignent au
  clavier.
- Le JavaScript de l'application n'ajoute plus ses fonctions à `window`.
- **Page « Mon compte » en anglais** : la ligne « Sign in » du profil restait en français. Le texte était choisi
  avant d'être traduit, ce qui le cachait au contrôle des traductions ; un test refuse désormais cette tournure.

## [2.4.0] - 2026-11-11

Repère de répartition des placements, menu des langues, classements côte à côte, filtre des types de bien,
traductions anglaises relues et guide de mise à jour complété.

### Installation

- Mise à jour ordinaire ([docs/mise-a-jour.md](docs/mise-a-jour.md)) : aucune variable ni configuration à changer.
  Depuis git, ne pas oublier `php bin/console asset-map:compile` : sans lui, l'ancienne présentation reste affichée.

### Ajouté

- **Repère de répartition des placements**, sous la pyramide : part de chaque niveau dans vos placements (fonds
  euros, actions, immobilier, exotique), à côté de l'allocation « équilibrée » d'Avenue des Investisseurs (45 / 25 /
  25 / 5 %), avec sa source ; repère indicatif, hors résidence principale et épargne de précaution.
- **Filtre des types de bien** à la création d'un bien, comme pour les comptes : sans tenir compte des majuscules
  ni des accents.

### Modifié

- **Sélecteur de langue** : le bouton affiche la langue en cours (globe, **FR** ou **EN**) et ouvre un menu
  **Français** / **English**, la langue en cours cochée ; fonctionne sans JavaScript, se ferme au clic à côté ou
  avec Échap.
- **Classements côte à côte** : place parmi les ménages français et parmi les adultes du monde, une carte chacune
  sur la même rangée, sous la pyramide et le détail par niveau (l'une sous l'autre sur un téléphone).
- **Traductions anglaises relues** : vocabulaire de connexion et de saisie plus naturel (« Sign in », « Monthly
  update », « balances »), « fonds euros » traduit par « guaranteed funds », formulations des classements.
- **Guide de mise à jour** complété : compilation des assets parmi les étapes, section « Problèmes fréquents »
  (affichage resté ancien, `git pull` refusé, erreur 500, migration en échec). Documentation d'utilisation :
  repères, langue, questions fréquentes.

- **Dépôt rangé** : branches fusionnées supprimées (dépôt et copies locales), suppression de la branche source par
  défaut à la fusion d'une merge request ; règle des branches et des étiquettes dans `docs/ci.md`.

### Corrigé

- **Saisies simultanées refusées** (« database is locked ») quand une écriture gardait la base plus de 5 s sur un
  disque lent : SQLite attend désormais un verrou jusqu'à 15 s avant d'abandonner.

### Retiré

- `docs/plan.md`, plan historique de la v2 (plus tenu à jour, conservé dans l'historique git).
- Lien « Aller au contenu » en haut des pages : le logo Patrimeter de l'en-tête ramène au tableau de bord, et les
  zones balisées (en-tête, navigation, contenu) suffisent aux lecteurs d'écran.

## [2.3.0] - 2026-11-10

Interface en anglais, place parmi les adultes du monde, accessibilité, comptes et biens immobiliers séparés,
page « Mon compte » et guide de mise à jour.

### Installation

- Mise à jour ordinaire ([docs/mise-a-jour.md](docs/mise-a-jour.md)) : aucune variable, ni configuration nginx ou
  php-fpm à changer. La nouvelle dépendance (`symfony/translation`) est installée par Composer, ou déjà incluse
  dans l'archive de la version.

### Ajouté

- **Interface en anglais** : langue du navigateur par défaut (français sinon), bouton FR / EN à côté de celui du
  thème (choix retenu dans un cookie). Pages, messages, e-mails, montants (`€1,234.56`), dates et mois traduits ;
  composant Translation de Symfony, catalogues `translations/*.en.yaml`, test de complétude des traductions. La
  documentation, l'API et la console restent en français.
- **Place parmi les adultes du monde**, sous la place parmi les ménages français : part des adultes dont le
  patrimoine net est inférieur, échelle graduée aux seuils de tranche, seuils en euros et en dollars, source
  (UBS, Global Wealth Report 2026, patrimoine net par adulte fin 2025, converti au cours BCE du 31/12/2025).
- **Guide de mise à jour** pas à pas ([docs/mise-a-jour.md](docs/mise-a-jour.md)) : version installée, points à
  lire dans le CHANGELOG, installation d'une version précise, vérification, retour arrière. L'archive de la CI
  contient un fichier `RELEASE` (nom de l'archive et commit).
- **Filtre des types de compte** à la création d'un compte : ne garde que les types qui contiennent le texte
  saisi, sans tenir compte des majuscules ni des accents ; liste complète sans JavaScript.

### Modifié

- **Accessibilité** (WCAG 2.2 AA visé, page [Accessibilité](https://kartnico.github.io/patrimeter/fr/utilisation/accessibilite/)
  de la documentation) :
    - couleurs des niveaux revues pour rester distinctes en cas de daltonisme (protanopie, deutéranopie,
      tritanopie) et lisibles sous un texte blanc (clair) ou sombre (thème sombre, nouvelles teintes) ;
    - motif propre à chaque niveau dans le graphique de l'historique, en plus de sa couleur ;
    - bordures des champs de saisie et texte « reporté » au contraste suffisant ;
    - lien « Aller au contenu » ;
    - erreurs annoncées immédiatement aux lecteurs d'écran ;
    - test automatique des contrastes et de l'écart entre couleurs pour les daltoniens, dans les deux thèmes.
- **Comptes et biens immobiliers séparés** : un menu chacun (« Biens » dans la navigation). Les biens
  (résidence principale, location, SCPI, SCI…) se créent seulement depuis Biens immobiliers, avec leur part et
  leurs crédits : le formulaire de compte ne propose plus leurs types et refuse un compte qui deviendrait un
  bien (niveau Résidence ou Immobilier, nature actif). Un bien existant reste modifiable.
- **Page « Mon compte »** à la place de la page Sécurité (même adresse, `/securite`) : profil de l'utilisateur
  en tête, puis sécurité et données ; lien avec une icône de profil, au-dessus de « Déconnexion ».

## [2.2.0] - 2026-11-10

### Ajouté

- **Place parmi les ménages français**, sous la pyramide : part des ménages dont le patrimoine net est
  inférieur, décile, échelle graduée, seuils et source (Insee, enquête Histoire de vie et Patrimoine 2023-2024,
  patrimoine net début 2024). Calcul local, par interpolation entre les seuils publiés.

## [2.1.0] - 2026-11-10

Supervision, export et import des données, rotation de la clé serveur, rapports de violation de la CSP,
débit limité par jeton, documentation d'utilisation en ligne, bonnes pratiques de Symfony et de PHP.

### Installation

- Pour mettre à jour un serveur déjà installé : recopier le pool php-fpm (`docs/php-fpm/patrimeter.conf`,
  envoi du fichier d'import) et `docs/nginx/patrimeter-app.conf` (adresse d'import jusqu'à 2 Mo), puis
  `nginx -t && systemctl reload nginx` ; le dossier `var/uploads` est créé par la mise à jour.

### Ajouté

- **Documentation d'utilisation** publiée par GitLab Pages (Zensical) : démarrage rapide, utilisation écran
  par écran avec captures d'écran prises sur l'application à chaque pipeline de `main`, installation et
  exploitation reprises de `docs/` sans copie à maintenir.
- **Export et import des données** (page Vos données, depuis Sécurité) : fichier JSON versionné, pour passer
  d'une instance de Patrimeter à une autre ; import tout ou rien dans un compte vide (`docs/export.md`).
- **Supervision** : `/sante` (état JSON, 503 si la base ne répond pas) et `/sante/metriques` (format de
  Prometheus, lu aussi par le collecteur OpenTelemetry) : base, âge de la dernière sauvegarde et des tâches
  planifiées, place libre (`docs/supervision.md`).
- **Rapports de violation de la CSP** journalisés dans `var/log/csp-*.log` (`report-uri`, `report-to`).
- **Débit limité par jeton** sur l'API et le MCP : 120 requêtes d'affilée, puis une par seconde (`429`).
- **Rotation de `AUTH_SECRET_KEY`** sans réinitialiser les utilisateurs : `AUTH_SECRET_KEY_PREVIOUS` et
  `app:auth-secrets:rotate` (installation § 12.5).
- **Pipeline de sécurité chaque lundi** (`SECURITY_SCAN=1`), même sans nouveau code ; `main` protégée.
- Liste des améliorations à venir : `TODO.md`.
- Procédure de publication d'une version (étiquette git, Release GitLab) : `docs/ci.md`.
- `docs/choix-techniques.md` : toutes les briques techniques, les alternatives envisagées et la raison de
  chaque choix.

### Modifié

- **OPcache réglé comme le recommande Symfony** (`docs/php-fpm/patrimeter-opcache.ini`, à copier dans
  `/etc/php/<version>/fpm/conf.d/90-patrimeter.ini`, installation § 7) : 256 Mo, dates des fichiers non
  vérifiées. Après toute modification du code hors des procédures de mise à jour, redémarrer php-fpm. Sans
  préchargement : avec la liste `disable_functions` du pool, il faussait les appels de fonctions (erreur 500
  constatée par le job `deployment`, corrigée avant toute installation).
- Conteneur de services compilé en un seul fichier ; locale limitée au français.
- Contrôleurs de l'API sur `AbstractController`, comme les autres.
- Bonnes pratiques de PHP 8.3 : `#[\Override]` sur toute méthode redéfinie, exigé par PHPStan et Psalm ;
  `#[\SensitiveParameter]` sur les jetons, codes et secrets qui ne l'avaient pas.
- Services configurés par attributs à côté du code (`#[AsMiddleware]`, `#[AsAlias]`, `#[Autowire]`).
- Dépréciations : PHPUnit rapporte celles de Symfony et de Doctrine à leur appelant (recette de Symfony),
  celles de Doctrine sont activées en test ; la CI refuse les dépréciations signalées à la compilation du
  conteneur (`tools/container-deprecations.sh`).
- État des bonnes pratiques de Symfony, écarts assumés compris : `docs/architecture.md`.
- `.gitattributes` : fins de ligne LF imposées.
- PHP-CS-Fixer 3.95.30, Psalm 7.2.
- Plus aucune mention de PHP 8.2 dans le dépôt (documentation, plan, pool php-fpm) ; le polyfill de PHP 8.3
  n'est plus installé, inutile depuis que PHP 8.3 est le minimum (comme le fait le squelette de Symfony).

### Supprimé

- Table `settings`, héritée de la v1 et inutilisée depuis le passage au multi-utilisateurs (migration
  `Version20261110140000`, réversible) ; `config/preload.php`, le préchargement OPcache n'étant pas utilisé.

### Corrigé

- Changement de version de PHP (installation § 12.4) : le nouveau php-fpm, démarré par l'installation de
  ses paquets, n'était pas redémarré et ignorait le pool de Patrimeter.
- Deux signatures dépréciées par Symfony 7.4, qui auraient cassé avec Symfony 8 :
  `OwnedResourceVoter::voteOnAttribute()` (argument `Vote`) et `UserChecker::checkPostAuth()` (jeton).

## [2.0.0] - 2026-11-10

Lots 1 à 9 de la v2 (plan d'origine dans l'historique git : `docs/plan.md`, retiré après la 2.3.0) : socle technique, CI
complète, thème
clair/sombre, multi-utilisateurs avec second facteur, chiffrement des données, passkeys et clés
FIDO2, OAuth2/OIDC, biens immobiliers et quotes-parts.

### Installation

- **PHP 8.3 minimum** ; PHPUnit 12, Doctrine DBAL 4.3 ou plus.
- Installation réordonnée : tâches planifiées (§ 9), sauvegarde (§ 10), vérification (§ 11), puis mise à jour
  (§ 12 : depuis git, depuis l'archive de la CI, retour arrière, changement de version de PHP).
- Nouveaux services systemd : `patrimeter-scheduler` et le modèle `patrimeter-alert@` (installation § 9),
  réinstallés et relancés à chaque mise à jour. Nouvelle variable : `ADMIN_EMAIL`.
- **Repartir d'une base neuve** : aucune reprise des données de la v1. Les migrations refusent une
  base contenant des comptes en clair.
- Variables obligatoires en plus : `AUTH_SECRET_KEY` (`openssl rand -base64 32`) et `DEFAULT_URI`.
- **HTTPS obligatoire**, sur le serveur ou sur un proxy inverse. Derrière un proxy, `TRUSTED_PROXIES`
  est indispensable, et le proxy doit transmettre `Host`, `X-Forwarded-Proto` et `X-Forwarded-For`.
- Nouveaux paquets : `php*-curl` (OAuth) et `sqlite3` (sauvegardes). Composer vient de son phar officiel
  (`ci/install-composer.sh`), et non plus du paquet Debian.
- nginx : `docs/nginx/patrimeter.conf` est remplacé par `patrimeter-proxied.conf` ou `patrimeter-tls.conf`,
  plus la partie commune `patrimeter-app.conf` à copier dans `/etc/nginx/snippets/`.
- `bin/console app:set-password` est remplacée par `app:user:create` et `app:user:set-password`.

### Modifié

- **Connexion par adresse e-mail et mot de passe, puis code TOTP** (au lieu du mot de passe
  unique).
- Nouveaux mots de passe : 12 caractères au moins (10 auparavant) et assez imprévisibles.
- Limitation des tentatives : compteurs par adresse IP, par compte (délai sans blocage) et
  global (blocage à 100 échecs au lieu de 20, le seuil global étant désormais partagé par tous
  les utilisateurs).
- `make serve` sert les assets d'AssetMapper (routeur `tools/dev-server-router.php`).
- Application portée sur **Symfony 7.4 LTS** (Doctrine DBAL et Migrations, Twig, AssetMapper),
  sans changement visible : mêmes adresses, mêmes écrans, mêmes messages et mêmes calculs, vérifiés
  par un jeu de résultats de référence figé avec la v1.
- PHP 8.3 à 8.5, installé depuis le dépôt d'Ondřej Surý ; **PHP 8.4 recommandé**.
- Installation et mise à jour : Composer, fichier `.env.local` et console Symfony.
  `bin/console doctrine:migrations:migrate` remplace `bin/migrate.php`,
  `bin/console app:set-password` remplace `bin/set-password.php`, et `config.php` est remplacé par
  des variables d'environnement. Les migrations ne s'appliquent plus automatiquement à la première
  requête : elles font partie de la procédure de mise à jour.
- Le socket php-fpm s'appelle désormais `/run/php/patrimeter.sock`.
- Chart.js 3.9.1 → 4.6.1 (la 3.x n'est plus maintenue).

### Ajouté

- **MCP : clients décrits par un document de métadonnées** (*Client ID Metadata Documents*), lu par le HttpClient
  de Symfony derrière `NoPrivateNetworkHttpClient`, vérifié par le sérialiseur et le validateur.
- **Validation par Symfony Validator** : formulaires des comptes et des biens (contrainte `Collection`), corps des
  requêtes de l'API lus par `#[MapRequestPayload]` dans des classes à contraintes (`src/Application/Payload/`).
  Un décimal envoyé à la place d'un entier est désormais refusé (il était tronqué). L'API exige
  `Content-Type: application/json`.
- **Tâches planifiées** (symfony/scheduler, service `patrimeter-scheduler`) : purge horaire de ce qui a expiré
  (liens envoyés par e-mail, journal au-delà de 180 jours, jetons de l'API, cache de sécurité) et `PRAGMA
  optimize`, au lieu de purges faites pendant les requêtes des utilisateurs.
- **Alertes par e-mail** à `ADMIN_EMAIL` : erreur critique de l'application (Monolog, une fois par heure pour
  une même erreur) et échec d'un service, dont la sauvegarde (`OnFailure=`, modèle `patrimeter-alert@`).
- Nouvelles dépendances (Symfony) : `symfony/scheduler`, `symfony/messenger`.
- **Mots de passe divulgués refusés** (`NotCompromisedPassword` de Symfony, API de Have I Been Pwned par
  k-anonymat) ; robustesse estimée par `PasswordStrength` de Symfony, au même seuil qu'avant.
- **Limitation des tentatives sur symfony/rate-limiter** (`config/packages/rate_limiter.yaml`) : blocage après
  5 échecs par IP ou 5 codes faux sur 15 minutes, 100 échecs par heure au total, et par compte un essai par minute
  après 5 échecs. Le délai exponentiel dès le premier échec disparaît. Compteurs et codes TOTP déjà utilisés dans
  un pool de cache sur la base (table `cache_items`), conservés par `cache:clear` : un code ne peut plus resservir
  juste après une mise à jour.
- Nouvelles dépendances (Symfony) : `symfony/validator`, `symfony/rate-limiter`, `symfony/http-client`.
- **CI** : pipeline rapide (15 min environ) sur les branches et les merge requests ; pipeline complet sur
  `main`, sur une étiquette, sur une planification ou avec `FULL_PIPELINE=1`. Archive de livraison
  construite par la CI (job `release`, `docs/installation.md` § 12.2).
- **API REST et service MCP** (`docs/api.md`), avec la même authentification que le site : connexion dans le
  navigateur et consentement (serveur d'autorisation OAuth 2.1, PKCE), ou jeton personnel depuis la page
  Sécurité. Jetons porteurs par l'authentificateur natif `access_token` de Symfony ; données déchiffrées
  avec la clé portée par le jeton, sans que le serveur puisse le faire seul.
- Nouvelles dépendances : `symfony/mcp-bundle` et `symfony/psr-http-message-bridge` (Symfony), `mcp/sdk` (SDK
  officiel de MCP), `opis/json-schema`, `opis/string`, `opis/uri`, `php-http/discovery`,
  `psr/http-server-handler`, `psr/http-server-middleware`. Le bundle et le SDK sont **expérimentaux**.
- **Sauvegarde quotidienne et restauration** (`docs/backup.md`) : scripts et minuteur systemd, copie
  cohérente à chaud, contrôle d'intégrité, rotation au nombre d'archives, restauration protégée.
- **Job CI `deployment`** : exécute la documentation d'installation et de sauvegarde sur un Debian 12
  neuf, et vérifie de l'extérieur nginx, php-fpm, HTTPS, droits, restauration et mise à jour.
- **CI complète** : parcours Playwright, ZAP authentifié, charge k6, Semgrep, Trivy, npm audit,
  modèles GitLab SAST et Secret Detection, barrière de sécurité avec exclusions justifiées et datées,
  rapport de synthèse, détection des requêtes N+1, PHP 8.6 en avance de phase (`docs/ci.md`).
- Le pipeline s'arrête dès le premier job si le disque du runner est presque plein.
- **Biens immobiliers** (page Biens, depuis Comptes) : valeur du bien, part, valeur retenue, capital
  restant dû et valeur nette ; formulaire unique créant le bien et ses crédits en une seule
  transaction ; un crédit existant se rattache à un bien depuis sa page.
- **Quotes-parts** : historique par mois d'effet (100 % par défaut), en millionièmes, arrondi au
  centime le plus proche. La valeur totale du bien reste saisie ; pyramide, total et historique
  retiennent la part en vigueur chaque mois. Un crédit lié compte au prorata ou en montant propre.
- **Plusieurs utilisateurs**, strictement cloisonnés : dépôts filtrés par propriétaire, voter,
  clé étrangère composite `(account_id, user_id)`, SQL interdit hors des dépôts (règle PHPStan),
  robots de test parcourant toutes les routes avec les identifiants d'un autre utilisateur.
- **Inscription** depuis la page de connexion (désactivable, domaines autorisés configurables) :
  lien de confirmation par e-mail (24 h, usage unique) qui exige aussi le mot de passe choisi.
  Aucune réponse ne révèle si une adresse a déjà un compte.
- **Second facteur TOTP obligatoire** (QR code en SVG, clé affichée) et **10 codes de secours**
  à usage unique ; secrets TOTP chiffrés et codes protégés par la clé serveur `AUTH_SECRET_KEY`.
- **Page Sécurité** : changement du mot de passe, changement d'application d'authentification,
  nouveaux codes de secours, déconnexion des autres sessions (après confirmation du mot de
  passe de moins de 5 minutes), journal des connexions (180 jours) ; e-mails d'alerte.
- Sessions limitées à 12 heures après la connexion, en plus des 30 minutes d'inactivité.
- Envoi d'e-mails par SMTP configurable (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`,
  `SMTP_ENCRYPTION`, `MAIL_FROM`).
- **Données chiffrées** (noms, types, dates, montants) avec une clé par utilisateur, illisibles depuis
  la base, les sauvegardes ou les sessions ; **clé de récupération** affichée une fois et confirmée ;
  écran de déverrouillage. Détails : [docs/encryption.md](docs/encryption.md).
- **Passkeys et clés de sécurité FIDO2** (web-auth/webauthn-lib 5.3) : connexion sans mot de passe
  avec vérification de l'utilisateur, ou clé comme second facteur à la place du code. Avec
  l'extension PRF, la passkey déverrouille aussi les données ; sans, le mot de passe est demandé.
  Ajout, renommage et suppression depuis la page Sécurité. **HTTPS obligatoire en production.**
- **Connexion OAuth2/OIDC** (league/oauth2-client) : Google, GitHub et un fournisseur OIDC générique,
  actifs seulement s'ils sont configurés ; PKCE, state, nonce. Jamais de liaison automatique d'après
  l'adresse e-mail : elle se fait depuis la page Sécurité. Compte sans mot de passe : **phrase de
  déverrouillage** obligatoire. Le TOTP reste demandé après OAuth. Le dernier moyen de connexion ne
  peut pas être retiré.
- **Mot de passe oublié** : lien de 30 minutes ; données conservées avec la clé de récupération,
  effacées après confirmation explicite sinon.
- Commandes `app:user:create`, `app:user:set-password`, `app:user:reset-2fa`, `app:user:rotate-key`.
- Envoi des e-mails après la réponse : le temps de réponse ne révèle pas si un compte existe.
- Dépendances : scheb/2fa-bundle, scheb/2fa-totp et scheb/2fa-backup-code 7.14 (avec
  spomky-labs/otphp), endroid/qr-code 6 (avec bacon/bacon-qr-code), symfony/mailer et
  symfony/uid 7.4 ; toutes compatibles PHP 8.3 à 8.5 et sous licence MIT ou BSD.
- Documentation : parcours d'authentification ([docs/auth-flows.md](docs/auth-flows.md)) et
  modèle de données ([docs/data-model.md](docs/data-model.md)).
- **Thème clair ou sombre** : bouton dans l'en-tête de chaque page (y compris la connexion),
  thème du système par défaut, choix mémorisé un an dans un cookie. Le serveur écrit le thème
  dans la page : aucun flash au chargement, aucun script inline. Sans JavaScript, le bouton
  reste un formulaire ; avec, la bascule est instantanée et le graphique se redessine.
- Plus de 370 tests PHPUnit : unitaires, intégration, fonctionnels (requêtes HTTP réelles), non-régression
  par rapport à la v1, architecture, migrations.
- Outillage qualité : PHP-CS-Fixer (PER Coding Style 3.0), PHPStan au niveau max, Psalm avec
  analyse de taint, validation W3C du HTML rendu et du CSS.
- Intégration continue GitLab : lint, analyse statique, tests sur PHP 8.3 et 8.5,
  couverture avec seuils, W3C, `composer audit`, Gitleaks.
- En-tête HSTS en HTTPS, journal applicatif `var/log/prod.log` (14 jours).
- Documentation : architecture, intégration continue, sécurité, plan de la v2, variables
  d'environnement (`.env.example`), procédure Debian 12 réécrite.

### Corrigé

- **Restauration** : refusée tant que les tâches planifiées tournent (elles gardaient la base ouverte et
  auraient continué d'écrire dans l'ancienne) ; la procédure arrête `patrimeter-scheduler` avec php-fpm.
- **Alerte des tâches planifiées** : relancé toutes les 10 s sans limite atteignable, le service n'échouait
  jamais et son alerte ne partait pas ; il s'arrête et prévient après 5 démarrages en 10 minutes.
- **Mise à jour** (`docs/installation.md` § 12) : service arrêté pendant l'opération et cache de production
  supprimé avant toute commande (Symfony ne le reconstruit pas de lui-même, et celui de l'ancienne version
  pouvait ne plus convenir au nouveau code) ; unités systemd et scripts de sauvegarde réinstallés. Depuis
  l'archive de la CI, `/srv/patrimeter-v1` n'est plus pris pour l'archive extraite.
- **Passkey sans vérification de l'utilisateur** acceptée en réutilisant le défi du second facteur.
- `app:user:rotate-key` : les passkeys ouvraient ensuite l'ancienne clé (erreur 500) ; fonctionne aussi
  avec une phrase de déverrouillage.
- Mot de passe oublié : un code TOTP déjà utilisé était accepté.
- Saisie mensuelle : un compte absent du formulaire (créé depuis son affichage) perdait son relevé du mois.
- Montants : `0.500` était lu comme 500,00 € ; il est désormais refusé.
- Un bien avec une part ou des crédits ne peut plus changer de niveau, ni un crédit rattaché devenir un actif,
  sans les retirer d'abord : ils continuaient de s'appliquer sans rester modifiables.
- Liaison d'un compte externe depuis Sécurité : refusée par la CSP sous Chrome et Edge, et session perdue au retour.
- Biens : même règle d'ouverture et de clôture que le tableau de bord.
- Inscription : au-delà du quota, les liens déjà reçus restent valables, et le premier e-mail compte dans
  les trois par heure.
- Erreurs 500 : `/?mois=1900-03`, adresse `a..b@example.com` (qui laissait aussi une ligne orpheline), nom
  de bien ou de passkey invalide.
- CI : pipeline de merge request refusé par GitLab (jobs des modèles SAST et Secret Detection absents),
  donc aucun pipeline sur une branche dont la merge request est ouverte.
- Installation : `http2 on` refusé par le nginx 1.22 de Debian 12, connexion OAuth en erreur 500 sans
  `php-curl`, nom du journal, dépôt privé, proxy inverse absent de la procédure.

- Une clé FIDO2 sans identifiant résident était refusée en second facteur : elle ne renvoie aucun
  `userHandle`, alors que l'utilisateur est déjà identifié. Trouvé par les parcours Playwright.
- La page Comptes paraissait vide pour un utilisateur connecté : une variable de la navigation
  écrasait la liste (`templates/base.html.twig`).

### Sécurité

- Données d'un utilisateur inaccessibles aux autres (cloisonnement en cinq barrières, voir
  [docs/architecture.md](docs/architecture.md)) ; liens envoyés par e-mail construits depuis
  `DEFAULT_URI`, jamais depuis l'en-tête Host de la requête.
- Migrations écrites uniquement en SQL : la comparaison automatique de schéma de Doctrine est
  désactivée, car sous SQLite elle reconstruisait des tables et déclenchait des cascades.

## [1.0.0] - 2026-11-07

Première version : PHP natif, SQLite, mono-utilisateur.

### Ajouté

- Comptes et crédits avec type prédéfini, niveau de pyramide, nature, ouverture et archivage.
- Saisie mensuelle de tous les comptes en un formulaire, avec report automatique du dernier solde.
- Tableau de bord : pyramide SVG, patrimoine net, variation sur un mois et sur un an, sélecteur de
  mois en calendrier.
- Historique : courbe du patrimoine net ou aires par niveau, sur plusieurs périodes.
- Authentification par mot de passe unique (Argon2id), limitation des tentatives, sessions
  durcies, jeton CSRF, en-têtes de sécurité dont une CSP stricte.

[3.6.0]: https://github.com/kartnico/patrimeter/compare/v3.5.0...v3.6.0
[3.5.0]: https://github.com/kartnico/patrimeter/compare/v3.4.1...v3.5.0
[3.4.1]: https://github.com/kartnico/patrimeter/tree/v3.4.1
[3.4.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v3.3.0...v3.4.0
[3.3.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v3.2.0...v3.3.0
[3.2.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v3.1.0...v3.2.0
[3.1.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v3.0.0...v3.1.0
[3.0.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.26.1...v3.0.0
[2.26.1]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.26.0...v2.26.1
[2.26.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.25.2...v2.26.0
[2.25.2]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.25.1...v2.25.2
[2.25.1]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.25.0...v2.25.1
[2.25.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.24.0...v2.25.0
[2.24.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.23.0...v2.24.0
[2.23.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.22.0...v2.23.0
[2.22.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.21.0...v2.22.0
[2.21.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.20.0...v2.21.0
[2.20.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.19.0...v2.20.0
[2.19.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.18.0...v2.19.0
[2.18.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.17.0...v2.18.0
[2.17.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.16.0...v2.17.0
[2.16.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.15.0...v2.16.0
[2.15.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.14.0...v2.15.0
[2.14.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.13.0...v2.14.0
[2.13.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.12.0...v2.13.0
[2.12.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.11.0...v2.12.0
[2.11.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.10.0...v2.11.0
[2.10.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.9.0...v2.10.0
[2.9.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.8.0...v2.9.0
[2.8.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.7.0...v2.8.0
[2.7.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.6.0...v2.7.0
[2.6.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.5.0...v2.6.0
[2.5.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.4.0...v2.5.0
[2.4.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.3.0...v2.4.0
[2.3.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.2.0...v2.3.0
[2.2.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.1.0...v2.2.0
[2.1.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.0.0...v2.1.0
[2.0.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/e4217829...v2.0.0
[1.0.0]: https://gitlab.example.org/kartzone/patrimeter/-/commit/e4217829
