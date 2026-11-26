# Démarrage rapide

## Installer Patrimeter (administrateur)

Deux façons, au choix :

- **Avec Docker** : `compose.yaml`, un fichier de configuration, puis `docker compose up -d`
  ([Installer avec Docker](exploitation/docker.md)).
- **Sur Linux** : l'archive d'une version, avec PHP et nginx ([Installer sur Linux](exploitation/installation.md)).

Dans les deux cas, il faut un accès **HTTPS** (proxy inverse ou certificat) et, idéalement, un serveur **SMTP** pour
les e-mails. À la première visite, l'**assistant d'installation** vérifie la configuration et crée le premier compte,
le vôtre.

**Mettre à jour** : une commande, la base étant sauvegardée automatiquement avant ([mise à
jour](exploitation/mise-a-jour.md)).

## Commencer à suivre son patrimoine (utilisateur)

1. **Créez votre compte** depuis la page de connexion (« Créer un compte »), puis confirmez votre adresse
   avec le lien reçu par e-mail. → [Première connexion](utilisation/premiere-connexion.md)
2. **Configurez votre application d'authentification** (Google Authenticator, Aegis, 1Password…) en
   scannant le QR code, et notez vos **codes de secours**.
3. **Notez votre clé de récupération** : c'est elle qui sauve vos données si vous oubliez votre mot de passe.
4. **Créez vos comptes et vos crédits** : compte courant, livrets, assurance-vie, PEA, crédit à la
   consommation… Vos **biens immobiliers** et leurs crédits se créent dans l'onglet **Biens immobiliers** de Mon compte.
   → [Comptes](utilisation/comptes.md)
5. **Saisissez leurs soldes** du mois. → [Saisie du mois](utilisation/saisie.md)
6. **Regardez votre pyramide** sur la page Patrimoine, puis revenez chaque mois : le menu Saisie du mois vous le
   rappelle tant que le mois n'est pas saisi. → [Patrimoine et historique](utilisation/tableau-de-bord.md)
7. **Fixez-vous des objectifs** (Saisie du mois, onglet Objectifs) et suivez-les dans le menu **Objectifs** ; et, si
   vous le voulez, le **budget du foyer**. → [Objectifs d'épargne](utilisation/objectifs.md),
   [budget](utilisation/budget.md)

Pour découvrir Patrimeter sans compte : **Essayer la démo** sur la page de connexion, si l'instance la propose
([essayer sans compte](utilisation/premiere-connexion.md#essayer-sans-compte)).

![Saisie du mois sur téléphone](captures/saisie-mobile.png)
