# Accessibilité

Patrimeter vise le niveau AA des règles WCAG 2.2 (reprises par le RGAA). Aucun audit externe n'a été mené :
cette page décrit ce qui est fait et vérifié, et les limites connues. Signalez toute difficulté à
l'administrateur de votre instance.

## Vue : malvoyance et daltonisme

- **Contrastes** : au moins 4,5:1 pour tout texte, 3:1 pour les bordures des champs, le contour de focus et
  les éléments graphiques, dans le thème clair comme dans le thème sombre, et pour chacune des quatre couleurs
  d'accent.
- **Daltonisme** : les six couleurs de la pyramide restent distinctes deux à deux en simulation de
  protanopie, deutéranopie et tritanopie. Surtout, **aucune information ne repose sur la couleur seule** :
    - chaque étage de la pyramide porte son nom, son montant et sa part ;
    - dans le graphique de l'historique, chaque niveau a aussi son **motif** (hachures, points, lignes…), et le
      tableau des valeurs mensuelles donne les mêmes chiffres ;
    - les hausses et les baisses portent leur signe (+ ou −), les soldes reportés la mention « reporté », les
      crédits de la saisie la mention « dû », le signe « − » et un soulignement en pointillés, les erreurs de saisie
      un message écrit.
- **Thème sombre** au choix (bouton du thème), sinon celui du système ; **couleur d'accent** et **densité
  d'affichage** au choix aussi ([apparence](securite.md#apparence)).
- **Agrandissement** : la page suit le zoom et la taille de texte du navigateur ; conçue d'abord pour le
  mobile (vérifiée à 390 pixels de large), elle se réorganise sur une colonne au lieu de défiler à
  l'horizontale.
- **Lecteurs d'écran** : langue déclarée, titres hiérarchisés, zones de navigation nommées, champs associés à
  leur libellé, messages annoncés (confirmations et erreurs), pyramide et échelles décrites par un texte
  alternatif, graphique doublé d'un tableau.

Ces contrôles des couleurs sont automatiques : un test de l'intégration continue échoue si une couleur
modifiée descend sous ces seuils.

## Audition : surdité et malentendance

Patrimeter ne diffuse **aucun son, aucune vidéo, aucun contenu audio** : toute l'information est écrite.

- Les confirmations, alertes et erreurs s'affichent à l'écran, en texte ; les liens de confirmation, de
  réinitialisation et les alertes d'administration arrivent **par e-mail**.
- L'authentification ne demande **ni appel, ni code vocal, ni CAPTCHA audio** : application d'authentification
  (code affiché), codes de secours, passkeys ou clés de sécurité.
- Aucune action n'est limitée dans le temps par un signal sonore ; la session expire après une période
  d'inactivité, avec retour à la page de connexion.

## Clavier et motricité

- Tout se fait au clavier ; le focus est toujours visible (contour épais).
- Le logo **Patrimeter**, en tête de chaque page, ramène à la page Patrimoine. L'en-tête, la navigation et le contenu
  sont des zones balisées : un lecteur d'écran passe directement au contenu.
- Les champs de saisie mesurent 44 pixels de haut (38 en densité compacte), sauf ceux de la saisie du mois, plus
  compacts pour tenir sur une ligne de tableau (au moins 24 pixels, comme les boutons et liens isolés).
- Saisie du mois : **Entrée** passe au champ suivant, **Maj + Entrée** au précédent, **Échap** rétablit le montant
  proposé ([saisie du mois](saisie.md#saisir-vite-au-clavier)).
- Les animations (graphiques, menus qui se déplient, messages qui apparaissent, couleurs des boutons au survol)
  sont coupées si le système demande de réduire les animations.

## Limites connues

- Le graphique de l'historique est une image pour les lecteurs d'écran : son contenu est dans le tableau
  placé dessous.
- L'interface et cette documentation existent en français et en anglais ; les pages techniques destinées au
  développement de Patrimeter (architecture, intégration continue…) n'existent qu'en français.
