# Carbure: actual versus planned

[Carbure](https://github.com/ltoinel/Carbure) is a free, self-hosted spending tracker: it fetches your bank
transactions and sorts them into categories. Linked to **your** Carbure instance, Patrimeter reads each month's
**actual spending** and compares it with the **planned** [budget](budget.md).

## Linking your instance

1. In Carbure, the administrator enables the **MCP server** (it is disabled by default).
2. In Carbure, create an **API token**: Settings, API tokens, Create. A limited lifetime (90 days, one year) is
   better. The token starts with `cbt_`; it is shown only once.
3. In Patrimeter, [My account](securite.md), **Carbure** tab: the address of your instance (for example
   `https://carbure.maison.lan`) and the token, then **Link Carbure**. The address of the MCP service that Carbure
   shows (`https://carbure.maison.lan/api/mcp`) will do too.

Patrimeter then reads your expense categories: the page shows how many. Each user links their own instance; the
token is encrypted with your key and never shown again (only its last four characters). To change the address
without changing the token, leave the token field empty.

## Mapping the categories

Each top-level Carbure expense category (its subcategories included) counts towards **one budget category**, or is
**ignored**. Categories with the same name are mapped automatically; the others are chosen in **Category mapping**.
Carbure's income categories are not used.

## Planned and actual

The budget page adds the **Planned and actual** section: for each mapped category, the planned amount, the month's
actual spending in Carbure and the gap (actual minus planned: when positive, spending exceeds the budget), with two
bars. Spending in categories without a mapping is flagged, with a link to map them.

If Carbure does not answer, refuses the token or has not enabled its MCP server, the budget says so and is shown
anyway. The message tells a refused token (to create again in Carbure) apart from an address without a Carbure MCP
service (wrong address, or MCP server disabled).

## Security

- Patrimeter only calls the `…/api/mcp` address of your instance, read-only, without following redirects, with a
  limited delay and response size; it never sends back what it reads there.
- Calls are limited per user (a burst of 30, then one every 10 seconds).
- The Patrimeter administrator can restrict the allowed instances (`CARBURE_ALLOWED_HOSTS`, see
  [the installation](../exploitation/installation.md#3-configuring)).
- **Delete the link** erases the address, the token and the mapping from Patrimeter; revoke the token in Carbure
  too.
