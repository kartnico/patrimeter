# Exporting and importing data

Users can export all their data into a file, then import it on another Patrimeter instance (moving, new
server): **Your data** page, from My account.

## Content of the file

UTF-8 JSON, **unencrypted**: the server decrypts the data with the key of the signed-in user. Keep it somewhere
safe and delete it after use. The export requires a recent confirmation of identity (password, 5 minutes).

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
      "name": "Down payment",
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

- `ref`: identifier of the account in the file; `linked_asset_ref` refers to it for a loan linked to a
  property.
- `type`: code of the account type (the same on every instance), not its number.
- Amounts in cents, shares in millionths (500,000 = 50%), as in the [API](api.en.md).
- `budget`: the household budget (user guide, Household budget page), or `null`. Adults numbered in order
  (1, then 2 for a couple); `group` is `needs`, `wants` or `savings` (50/30/20 rule); a category's `adult`: `null`
  for the shared budget, otherwise the adult's number. An item's `child_ref` and `account_ref` refer to the
  children and accounts of the file, an amount's `line_ref` to an item. `split_rule`: `income` (in proportion to
  income) or `fixed` (fixed share of the first adult, `first_adult_share_millionths`).
  Since Patrimeter 2.26: `adult_birth_years` (in the order of the adults) and a child's `birth_year`, years of birth
  (`null` if unknown); a month's `child_incomes`, incomes of a child of working age (16 in the year), `child_ref`
  referring to the child. Absent from an older file, which is imported as it is.
- `goals`: the [savings goals](https://kartnico.github.io/patrimeter/en/utilisation/objectifs/); `scope` is
  `net` (net worth), `level` (level `level`) or `accounts` (accounts of the file, `account_refs`); the starting point
  (`start_month`, `start_cents`) is kept as it is.
- `indicators` (since Patrimeter 3.4): the
  [custom indicators](https://kartnico.github.io/patrimeter/en/utilisation/indicateurs/), their levels
  (`levels`) and their accounts of the file (`account_refs`).
- `<`, `>`, `&`, `'` and `"` are written `\u003C`, `\u003E`…: entered text can never be taken for HTML; the file
  reads back identically.

Not exported: the user account itself (address, password, second factor, passkeys, API tokens), which is
created again on the other instance.

## Import

- Into an account that is **still empty**: the import does not merge with existing accounts, nor with an existing
  budget.
- A version 1 file (Patrimeter 2.7 and earlier, without a budget), 2 (Patrimeter 2.17 and earlier, without goals) or 3
  (Patrimeter 3.3 and earlier, without indicators) can still be imported.
- **All or nothing**: at the slightest rejection (unknown type, month outside the opening period…), nothing is
  imported and the account at fault is named.
- Same checks as the API: the data is encrypted again with the key of the importing user.
- File of 2 MB at most. Together with a member's import into a family account, these are the only requests that nginx
  accepts above 64 KB (`location ~ "^/donnees/import(-csv)?$"`, `location ~ "^/famille/membres(/[0-9]{1,9})?$"` and, for
  a bank statement, `location ~ "^/comptes/[0-9]{1,9}/releves/fichier$"`), and the only ones for which php-fpm accepts a
  file (`var/uploads`).

## Family account

The same file can be imported into a [family account](https://kartnico.github.io/patrimeter/en/utilisation/famille/),
for one of its two members at most:

- **Same checks** as the import into an empty account (`ExportReader`, without a database): types, dates, months
  within the opening period, shares only for real estate, loans linked to a property of the file, budget validated
  by the domain rules. All or nothing.
- The validated content is **kept as is**, encrypted with the family account's key (`family_members` table), apart
  from the account's own accounts: it cannot be changed, and a new import **replaces** it.
- When read, a member's accounts get negative identifiers based on their rank (`−(rank × 1,000,000 + n)`), and the
  categories and items of their budget identifiers from `rank × 1,000,000 + 1`: they never mix with another
  member's or with the family account's.
- The family account's export only contains its own data.
