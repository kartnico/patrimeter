# Patrimeter

Patrimeter suit votre **patrimoine net mois par mois** et l'affiche en **pyramide** : de la résidence
principale, à la base, jusqu'aux placements les plus risqués, au sommet. Chaque mois, vous saisissez les
soldes de vos comptes et de vos crédits ; Patrimeter calcule le reste.

![Page Patrimoine : pyramide du patrimoine net](captures/tableau-de-bord-ordinateur.png)

## Ce que fait Patrimeter

- **Pyramide en six niveaux** : résidence principale, épargne de précaution, fonds euros, actions,
  immobilier, exotique. Chaque niveau montre sa valeur nette (actifs moins crédits) et sa part du total.
- **Saisie mensuelle** sur un seul écran ; un compte sans relevé ce mois-ci garde son dernier solde connu. Le menu
  Saisie du mois est mis en évidence tant que le mois n'est pas saisi, et un rappel par e-mail est possible.
- **Historique**, en bas de la page Patrimoine : évolution du patrimoine net et de chaque niveau, en graphique et
  en tableau, et ce qui a été mis ou retiré dans chaque niveau depuis le début de l'année.
- **Rapport à partager** : le patrimoine du mois sur une carte qui occupe l'écran du téléphone, avec ou sans les
  montants, en capture d'écran ou en image (Mastodon, X, Facebook, WhatsApp, Instagram…).
- **Biens immobiliers** détenus en partie (indivision, couple…), avec leurs crédits liés.
- **[Objectifs d'épargne](utilisation/objectifs.md)** : un montant à atteindre à une date (ou des mois de dépenses du
  budget) ; chaque mois, Patrimeter compare votre rythme à l'effort nécessaire et dit si vous êtes sur la bonne voie,
  graphique de projection à l'appui.
- **Tendances** du patrimoine et du budget sur 3, 6 et 12 mois, comparées à la période d'avant.
- **[Budget du foyer](utilisation/budget.md)** : budget commun versé sur le compte commun et part de chaque adulte,
  dépenses et épargne propres à chacun, flux des revenus comparés à la règle 50/30/20, rapprochés du patrimoine du
  mois.
- **[Compte famille](utilisation/famille.md)** : chacun garde son compte, et un compte famille réunit chaque mois leurs
  données pour une vue de la famille.
- **[Carbure](utilisation/carbure.md)** : dépenses réelles lues dans votre instance Carbure, face au budget prévu.
- **Recommandations** : épargne de précaution rapportée aux dépenses du mois, sur une jauge, avec une alerte sous la
  cible.
- **Repères** : répartition de vos placements comparée à une allocation équilibrée, place de votre patrimoine
  parmi les ménages français et parmi les adultes du monde.
- **Sur téléphone comme sur ordinateur**, en français ou en anglais, en thème clair ou sombre, avec la couleur
  d'accent et la densité de votre choix, accessible (contrastes, daltonisme, clavier).
- **API et service MCP** pour vos propres outils ou un assistant, avec la même authentification.

> **Pas un conseil financier.** Patrimeter est un outil de suivi : ses repères (pyramide, répartition des
> placements, épargne de précaution, objectifs) sont indicatifs et ne constituent ni un conseil financier ni un
> conseil en investissement, réservé aux professionnels agréés. Adaptez-les à votre situation.

## Vos données restent les vôtres

- Application **auto-hébergée** : vos données ne quittent pas votre serveur.
- Données patrimoniales **chiffrées avec une clé propre à chaque utilisateur**, elle-même protégée par son
  mot de passe : ni l'administrateur du serveur, ni une sauvegarde volée ne permettent de les lire.
- Connexion **à deux facteurs obligatoire** (application d'authentification), passkeys (FIDO2) et clés de sécurité
  possibles ; sessions ouvertes visibles et déconnectables depuis Mon compte.

## Par où commencer

- Vous découvrez l'application : [démarrage rapide](demarrage-rapide.md), ou **Essayer la démo** sur la page de
  connexion d'une instance qui la propose ([essayer sans compte](utilisation/premiere-connexion.md#essayer-sans-compte)).
- Vous l'installez : sur un serveur Linux ([installation](exploitation/installation.md)) ou en conteneur, avec Docker
  ou Podman ([déploiement en conteneur](exploitation/docker.md)) ; vous le mettez à jour :
  [mise à jour](exploitation/mise-a-jour.md).
- Vous l'utilisez déjà : [utilisation](utilisation/comptes.md) et [questions fréquentes](utilisation/questions.md).
  Dans l'application, **Documentation** (menu du compte, et page de connexion) ramène ici.
