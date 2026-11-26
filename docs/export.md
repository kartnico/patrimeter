# Export et import des données

Un utilisateur peut exporter toutes ses données dans un fichier, puis les importer sur une autre instance
de Patrimeter (déménagement, nouveau serveur) : page **Vos données**, depuis Mon compte.

## Contenu du fichier

JSON en UTF-8, **en clair** : le serveur déchiffre les données avec la clé de l'utilisateur connecté. Il
faut donc le garder en lieu sûr et le supprimer après usage. L'export exige une confirmation récente de
l'identité (mot de passe, 5 minutes).

```json
{
  "format": "patrimeter",
  "version": 4,
  "exported_on": "2026-11-13",
  "accounts": [
    {
      "ref": 12,
      "name": "Maison",
      "type": "residence_principale",
      "level": "residence",
      "nature": "asset",
      "opened_on": "2020-03-01",
      "closed_on": null,
      "linked_asset_ref": null,
      "loan_mode": null,
      "statements": [{"month": "2026-10", "amount_cents": 32000000}],
      "shares": [{"month": "2020-03", "share_millionths": 500000}]
    }
  ],
  "budget": {
    "adults": ["Flo", "Nico"],
    "adult_birth_years": [1985, 1983],
    "children": [{"ref": 1, "name": "Eva", "birth_year": 2009}],
    "precaution_months": 4,
    "categories": [
      {
        "name": "Épargne du foyer",
        "group": "savings",
        "adult": null,
        "lines": [{"ref": 31, "name": "Épargne de précaution", "child_ref": null, "account_ref": 14}]
      }
    ],
    "months": [
      {
        "month": "2026-11",
        "incomes": [{"adult": 1, "amount_cents": 186000}, {"adult": 2, "amount_cents": 414300}],
        "split_rule": "fixed",
        "first_adult_share_millionths": 275000,
        "amounts": [{"line_ref": 31, "amount_cents": 30000}],
        "child_incomes": [{"child_ref": 1, "amount_cents": 45000}]
      }
    ]
  },
  "goals": [
    {
      "name": "Apport",
      "target_cents": 2000000,
      "target_month": "2028-06",
      "scope": "accounts",
      "level": null,
      "account_refs": [14],
      "start_month": "2026-05",
      "start_cents": 600000
    }
  ],
  "indicators": [
    {
      "name": "Retraite",
      "levels": ["actions"],
      "account_refs": [14],
      "share_of_net_worth": true
    }
  ]
}
```

- `ref` : identifiant du compte dans le fichier ; `linked_asset_ref` y renvoie pour un crédit lié à un bien.
- `type` : code du type de compte (identique sur toutes les instances), et non son numéro.
- Montants en centimes, parts en millionièmes (500 000 = 50 %), comme dans l'[API](api.md).
- `budget` : le budget du foyer (guide d'utilisation, page Budget du foyer), ou `null`. Adultes numérotés dans
  l'ordre (1, puis 2 pour un couple) ; `group` vaut `needs`, `wants` ou `savings` (règle 50/30/20) ; `adult` d'une
  catégorie : `null` pour le budget commun, sinon le numéro de l'adulte. `child_ref` et `account_ref` d'un poste
  renvoient aux enfants et aux comptes du fichier, `line_ref` d'un montant à un poste. `split_rule` : `income`
  (au prorata des revenus) ou `fixed` (part fixée du premier adulte, `first_adult_share_millionths`).
  Depuis Patrimeter 2.26 : `adult_birth_years` (dans l'ordre des adultes) et `birth_year` d'un enfant, années de
  naissance (`null` si inconnue) ; `child_incomes` d'un mois, revenus d'un enfant en âge de travailler (16 ans dans
  l'année), `child_ref` renvoyant à l'enfant. Absents d'un fichier plus ancien, qui s'importe tel quel.
- `goals` : les [objectifs d'épargne](https://kartnico.github.io/patrimeter/fr/utilisation/objectifs/) ;
  `scope` vaut `net` (patrimoine net), `level` (niveau `level`) ou `accounts` (comptes du fichier, `account_refs`) ;
  le point de départ (`start_month`, `start_cents`) est gardé tel quel.
- `indicators` (depuis Patrimeter 3.4) : les
  [indicateurs personnalisés](https://kartnico.github.io/patrimeter/fr/utilisation/indicateurs/), leurs
  niveaux (`levels`) et leurs comptes du fichier (`account_refs`).
- `<`, `>`, `&`, `'` et `"` sont écrits `\u003C`, `\u003E`… : du texte saisi ne peut jamais être pris pour du
  HTML ; le fichier se relit à l'identique.

Ne sont pas exportés : le compte utilisateur lui-même (adresse, mot de passe, second facteur, passkeys,
jetons d'API), qui se recrée sur l'autre instance.

## Import

- Dans un compte **encore vide** : l'import ne fusionne pas avec des comptes existants, ni avec un budget existant.
- Un fichier de la version 1 (Patrimeter 2.7 et avant, sans budget), 2 (Patrimeter 2.17 et avant, sans objectifs) ou
  3 (Patrimeter 3.3 et avant, sans indicateurs) s'importe toujours.
- **Tout ou rien** : au moindre refus (type inconnu, mois hors de la période d'ouverture…), rien n'est
  importé et le compte en cause est cité.
- Mêmes contrôles que l'API : les données sont rechiffrées avec la clé de l'utilisateur qui importe.
- Fichier de 2 Mo au plus. Avec l'import d'un membre dans un compte famille, ce sont les seules requêtes que nginx
  accepte au-delà de 64 Ko (`location ~ "^/donnees/import(-csv)?$"`, `location ~ "^/famille/membres(/[0-9]{1,9})?$"` et,
  pour un relevé bancaire, `location ~ "^/comptes/[0-9]{1,9}/releves/fichier$"`), et les seules pour lesquelles php-fpm
  accepte un fichier (`var/uploads`).

## Compte famille

Le même fichier s'importe dans un [compte famille](https://kartnico.github.io/patrimeter/fr/utilisation/famille/),
pour l'un de ses deux membres au plus :

- **Mêmes contrôles** que l'import dans un compte vide (`ExportReader`, sans base de données) : types, dates, mois
  dans la période d'ouverture, parts réservées aux biens immobiliers, crédits liés à un bien du fichier, budget
  validé par les règles du domaine. Tout ou rien.
- Le contenu validé est **gardé tel quel**, chiffré avec la clé du compte famille (table `family_members`), à part
  des comptes de ce compte : il ne se modifie pas, et un nouvel import le **remplace**.
- À la lecture, les comptes d'un membre reçoivent des identifiants négatifs tirés de son rang (`−(rang × 1 000 000 +
  n)`), les catégories et postes de son budget des identifiants à partir de `rang × 1 000 000 + 1` : ils ne se
  mêlent jamais à ceux d'un autre membre ni du compte famille.
- L'export du compte famille ne contient que ses propres données.
