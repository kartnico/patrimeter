# CSV import

To create your accounts, loans and properties in one go, without entering them one by one: a CSV file, written with a
spreadsheet (LibreOffice Calc, Excel, Numbers…) or by hand, one line per account. **Your data** page, from My account,
**Create accounts from a CSV file** section.

## In short

1. **Download the model (CSV)**: a file ready to fill in, with a savings account, a property and its loan as examples.
2. Replace the examples with your accounts, one line each, and save in CSV format.
3. **Create the accounts** with this file: you land on the list of accounts, which shows them.

The accounts **are added** to the existing ones. **All or nothing**: at the slightest refusal, nothing is created, and
the message gives the line concerned (line 1 is that of the columns, as in the spreadsheet). File of 1 MB and 500
accounts at most.

## Example

```text
name,type,opened_on,balance,month,level,nature,share,property,loan_mode,closed_on
Savings account,livret_a,2020-01-15,12345.67,,,,,,,
Flat in Lyon,location_nue,2018-06-01,250000,,,,50,,,
Flat loan,credit_immo_locatif,2018-06-01,180000,,,,,Flat in Lyon,prorata,
Old PEL,pel_cel,2012-09-01,5000,2019-12,,,,,,2020-01-31
```

Only the **name**, **type** and **opened_on** columns are required; the others may be missing, in any order. An empty
cell means "not given".

## Columns

| Column | In French | Required | Content |
|---|---|---|---|
| `name` | `nom` | yes | Name of the account, 80 characters at most; two accounts of the file cannot have the same name (case and accents aside) |
| `type` | `type` | yes | Account type: its code (`livret_a`) or its label ("Livret A (regulated savings account)", "Unfurnished rental"), in English or French; [list of the types](#account-types) |
| `opened_on` | `ouverture` | yes | Opening date: `YYYY-MM-DD` (2020-01-15) or `DD/MM/YYYY` (15/01/2020) |
| `balance` | `solde` | no | Balance in euros, in the format of the interface language: `12,345.67` or `12345.67` in English, `12345,67` in French. For a loan, the **outstanding principal**, positive. For a property, its **total value**, even if you only own a share of it |
| `month` | `mois` | no | Month of the balance: `YYYY-MM` (2026-10) or `MM/YYYY` (10/2026). Empty: the current month (or the opening month, if it is to come) |
| `level` | `niveau` | no | Level of the pyramid, if it is not that of the type: `residence`, `precaution`, `fonds_euros`, `actions`, `immobilier` or `exotique` |
| `nature` | `nature` | no | `asset` or `liability` (`actif`, `passif`), if it is not that of the type |
| `share` | `part` | no | For a **property**: your share as a percentage (`50`, `33.3333`), from the opening month. Empty: 100% |
| `property` | `bien` | no | For a **loan**: the name of the property it finances, in the file or among your properties already entered |
| `loan_mode` | `mode_credit` | no | For a loan linked to a property: `prorata` (the amount is that of the whole loan, counted according to your share of the property) or `own` (the amount is already your share); empty: `prorata`. See [properties](biens.md#share-and-loans) |
| `closed_on` | `cloture` | no | Closing date of a closed account (`YYYY-MM-DD` or `DD/MM/YYYY`): it is created then archived |

Column names accept capitals, accents and spaces ("Closed on", "Mode crédit"). An unknown column is refused, so that a
typo does not go unnoticed.

One balance per account: the history of the previous months is entered afterwards, in the [monthly update](saisie.md)
or the account's page.

## Saving the file

- **Separator**: semicolon (the choice of a spreadsheet in French), comma or tab; Patrimeter recognizes that of the
  line of the columns. A value that contains the separator is written between quotes (`"Savings, joint"`).
- **Encoding**: UTF-8, with or without BOM, or Windows-1252 (the "CSV" of Excel in French); accents go through in
  both cases.
- **Dates and months**: a spreadsheet sometimes turns `2026-10` into a date ("Oct-26"); set these columns to the
  **Text** format before filling them in, or write `10/2026`.
- **LibreOffice Calc**: *File > Save As*, type "Text CSV", tick "Edit filter settings", character set Unicode (UTF-8),
  separator `,`.
- **Excel**: *File > Save As*, type "CSV UTF-8 (Comma delimited)".

## Properties and loans

A **property** is an asset of the Main residence or Real estate level (types marked **property** below): its `share`
column gives your share. A **loan** (liability nature) is attached to its property by the `property` column, which
repeats the property's name, whether the property comes before or after it in the file, or is already entered in
Patrimeter. The properties and loans created this way appear in the [Real estate](biens.md) tab, as if they had been
entered with **New property**.

## Error messages

| Message | What to do |
|---|---|
| Unknown column "…" | Correct the name of the column, or remove it |
| Columns name, type and opened_on required | Add the missing column, or fill in the empty cell of this line |
| Unknown account type "…" | Use a code or a label from the [list of the types](#account-types) |
| Name "…" already used on line … | Give each account of the file a distinct name |
| Invalid opening date, Invalid month of the balance | Write the date `YYYY-MM-DD` or `DD/MM/YYYY`, the month `YYYY-MM` or `MM/YYYY`; set the column to the Text format in the spreadsheet |
| Invalid balance | An amount in euros, two decimals at most, without any text other than `€` |
| Share reserved for properties | Empty the `share` column for an account that is not a property |
| Property "…" not found | Write in `property` the exact name of the property, as in the file or in Patrimeter |
| More values than columns | Check the separator, and put between quotes a value that contains it |

## Account types

Code to write in the `type` column (the label, in English or French, also works); the level and the nature are those
the account takes when the `level` and `nature` columns are empty.

| Code | Label | Level | Nature |
|---|---|---|---|
| `residence_principale` | Main residence (property value) | Main residence (`residence`) | asset, **property** |
| `credit_immo_rp` | Main residence mortgage | Main residence (`residence`) | liability (loan) |
| `compte_courant` | Current account | Emergency savings (`precaution`) | asset |
| `livret_a` | Livret A (regulated savings account) | Emergency savings (`precaution`) | asset |
| `ldds` | LDDS | Emergency savings (`precaution`) | asset |
| `lep` | LEP | Emergency savings (`precaution`) | asset |
| `livret_bancaire` | Bank savings account / term deposit | Emergency savings (`precaution`) | asset |
| `pel_cel` | PEL / CEL | Emergency savings (`precaution`) | asset |
| `credit_conso` | Consumer loan | Emergency savings (`precaution`) | liability (loan) |
| `autre_passif` | Other liability | Emergency savings (`precaution`) | liability (loan) |
| `fonds_euros` | Life insurance — guaranteed fund (fonds euros) | Guaranteed funds (`fonds_euros`) | asset |
| `av_uc` | Life insurance — unit-linked | Equities (`actions`) | asset |
| `pea` | PEA / PEA-PME | Equities (`actions`) | asset |
| `cto` | Securities account (CTO) | Equities (`actions`) | asset |
| `per` | PER (retirement savings plan) | Equities (`actions`) | asset |
| `gestion_pilotee` | Managed portfolio | Equities (`actions`) | asset |
| `epargne_salariale` | Employee savings (PEE, PERCO) | Equities (`actions`) | asset |
| `residence_secondaire` | Second home | Real estate (`immobilier`) | asset, **property** |
| `lmnp` | LMNP (furnished rental) | Real estate (`immobilier`) | asset, **property** |
| `location_nue` | Unfurnished rental | Real estate (`immobilier`) | asset, **property** |
| `scpi` | SCPI | Real estate (`immobilier`) | asset, **property** |
| `sci` | SCI | Real estate (`immobilier`) | asset, **property** |
| `siic` | SIIC (listed real estate companies) | Real estate (`immobilier`) | asset, **property** |
| `credit_immo_rs` | Second home mortgage | Real estate (`immobilier`) | liability (loan) |
| `credit_immo_locatif` | Buy-to-let mortgage | Real estate (`immobilier`) | liability (loan) |
| `crowdfunding` | Crowdfunding | Alternative (`exotique`) | asset |
| `crypto` | Cryptocurrencies | Alternative (`exotique`) | asset |
| `gfi` | GFI / GFV (forest and vineyard groups) | Alternative (`exotique`) | asset |
| `or` | Gold | Alternative (`exotique`) | asset |
| `argent_metal` | Silver | Alternative (`exotique`) | asset |
| `autres_metaux` | Platinum, palladium and other precious metals | Alternative (`exotique`) | asset |
| `bijoux` | Jewellery | Alternative (`exotique`) | asset |
| `montres` | Watches | Alternative (`exotique`) | asset |
| `art_collection` | Works of art and collectibles | Alternative (`exotique`) | asset |
| `vins` | Wines and spirits | Alternative (`exotique`) | asset |
| `voiture` | Car | Alternative (`exotique`) | asset |
| `moto` | Motorbike | Alternative (`exotique`) | asset |
| `autre_actif` | Other asset | Alternative (`exotique`) | asset |
