# REST API and MCP service

Two ways to access the same data, with **the same authentication mechanisms as the site**, the user signing in
in their browser (password and TOTP, or passkey):

- **REST API**: `/api/v1/…`, JSON, amounts in cents (integers), months in `YYYY-MM` format.
- **MCP service**: `/mcp`, for an assistant or an MCP client. Revision `2026-07-28`, and earlier revisions for
  older clients, through [`symfony/mcp-bundle`](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html)
  (experimental: pinned in `composer.lock`).

## Enabling

The API and the MCP service are **disabled by default**, each by its environment variable, as in Carbure: an
instance only exposes its pages until the administrator decides otherwise. In `.env.local`
([installation § 3](installation.en.md#3-configuring)) or the container's environment file:

```sh
API_ENABLED=true   # REST API, /api/v1/…
MCP_ENABLED=true   # MCP service, /mcp
```

A disabled service answers 404 on all its addresses, before routing and the firewall
(`Http\ApiAvailabilitySubscriber`), OAuth metadata included. The OAuth authorization server (`/oauth/…`,
`/.well-known/oauth-authorization-server`) and personal tokens (My account page) only exist if one of the two is
enabled. Tokens already issued stay in the database and work again if the service is enabled again.

## Authentication

Patrimeter is its own OAuth 2.1 authorization server: authorization code with PKCE (S256), public clients,
bearer token. The firewall uses Symfony's native `access_token` authenticator.

| Access | How to get it | Lifetime |
|---|---|---|
| Application, MCP client | OAuth flow: the browser opens on the site's sign-in, then the consent | Access token 1 h, refresh token 30 days (single use) |
| Script | Personal token created from the **My account** page, after confirming your identity | 30, 90 or 365 days |

In both cases: `Authorization: Bearer patrimeter_…`.

- **Recognizable format**: a token always starts with `patrimeter_`, followed by 24 hexadecimal characters, `_` and
  43 base64url characters. A secret detector thus spots a token published by mistake (code repository, ticket, log):
  the `patrimeter-api-token` rule of [`.gitleaks.toml`](../.gitleaks.toml) applies it to this repository, and its
  regular expression can be reused in any other tool. Tokens of the former format (`pm1.…`, before 2.23.0) stay valid
  until they expire. An exposed token is revoked from the "AI agents" tab.

- **Scopes**: `patrimeter.read` (default), `patrimeter.write` (writing, includes reading). Without the right
  scope: `403` and `WWW-Authenticate: Bearer error="insufficient_scope"`.
- **Audience** (RFC 8707): an OAuth token is only valid for its resource, `<DEFAULT_URI>/mcp` or
  `<DEFAULT_URI>/api`. A personal token is valid for both.
- **End of validity**: like a session, a token stops working if the password changes, if the user signs out
  their other sessions, or if the administrator resets their second factor.
- **Revoking**: My account page, "AI agents" tab, or `POST /oauth/revoke`.
- **Encryption**: the token opens the user's data key for the request, without the server being able to do
  it alone: the database only holds its hash and a copy of the key wrapped under a key derived from it
  ([encryption.md](encryption.en.md)). **Whoever holds a token reads the data in clear**: treat it like a
  password.

Discovery, for a client: a request without a token answers `401` with `WWW-Authenticate: Bearer
resource_metadata="…"`, which leads to `/.well-known/oauth-protected-resource/mcp` (or `/api`), then to
`/.well-known/oauth-authorization-server`. Two ways for a client to make itself known:

- **Metadata document** (*Client ID Metadata Document*, preferred by the MCP specification): its `client_id` is
  the HTTPS address of a JSON document (`client_id`, `redirect_uris`, `client_name`). The server reads it with
  Symfony's HttpClient, never towards the local network or the loopback, without redirects, 64 KB at most, and
  keeps it for an hour. The server must be able to reach the address of the document.
- **Dynamic registration** (RFC 7591) on `POST /oauth/register`, limited to 10 per hour and per IP address.

Redirect addresses over HTTPS, or over HTTP on the loopback.

The reverse proxy must pass `/mcp`, `/api/`, `/oauth/` and `/.well-known/oauth-`, and the `Authorization`
header. As the site remains restricted to the local network, a client must be there too.

## REST API

Complete description in **OpenAPI 3.1** format: [openapi.yaml](openapi.en.yaml), to import into a client (Bruno,
Insomnia, Postman…) or a code generator. Its readable version, with each operation, its parameters, its responses and
its schemas: [API reference](https://kartnico.github.io/patrimeter/en/exploitation/api-reference/) (online
documentation). A test checks that the description covers exactly the API routes and that the actual responses follow
its schemas.

With `curl`, a personal token in `$TOKEN`:

```sh
curl -H "Authorization: Bearer $TOKEN" https://patrimeter.example.org/api/v1/net-worth
curl -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -X PUT \
    -d '{"amount_cents": 1234567}' https://patrimeter.example.org/api/v1/accounts/12/statements/2026-11
```

| Method and address | Role |
|---|---|
| `GET /api/v1/me` | User of the token, write permission |
| `GET /api/v1/account-types` | Account types, default level and nature |
| `GET /api/v1/net-worth?month=` | Net worth, by level (current month by default) |
| `GET /api/v1/history?from=&to=` | Net worth month by month |
| `GET /api/v1/properties?month=` | Properties: value, share, loans, net value |
| `GET /api/v1/goals?month=` | Savings goals: value reached, effort needed, pace, status, projected month |
| `GET /api/v1/challenges` | Challenges and stars of the month: stars and level, saver stage and style, entry streak, each challenge with its thresholds; with the "not investment advice" notice |
| `GET /api/v1/budget?month=` | Household budget in force: totals, categories and items with their amount |
| `GET`, `POST /api/v1/accounts` | List, creation (`name`, `type_id`, `opened_on`, and optional `level`, `nature`, `initial_amount_cents`) |
| `GET`, `PUT`, `DELETE /api/v1/accounts/{id}` | Details with statements and shares, update, deletion |
| `POST /api/v1/accounts/{id}/archive`, `/unarchive` | Closing (`closed_on`), reactivation |
| `PUT /api/v1/accounts/{id}/loan-link` | Links a loan to a property (`asset_id`, `loan_mode`: `prorata` or `own`), or unlinks it (null `asset_id`) |
| `GET /api/v1/accounts/{id}/statements` | Statements |
| `PUT`, `DELETE /api/v1/accounts/{id}/statements/{month}` | Statement of a month (`amount_cents`). A loan has a positive amount: the outstanding principal |
| `GET /api/v1/accounts/{id}/shares` | Shares of a property |
| `PUT`, `DELETE /api/v1/accounts/{id}/shares/{month}` | Share from a month on (`share_millionths`, from 1 to 1,000,000) |

Request bodies are JSON (`Content-Type: application/json`, otherwise `415`), read and validated by
`#[MapRequestPayload]` and Symfony's constraints. A decimal number where an integer is expected is rejected,
never truncated.

Errors in `application/problem+json` format (RFC 9457): `{"title", "status"}`, and `errors` (field → message)
for rejected data (`422`). Another user's account answers `404`, like an account that does not exist.

## MCP service

Address to give the client: `https://patrimeter.example.org/mcp`. It opens the browser for signing in and
consenting. For example with Claude Code:

```sh
claude mcp add --transport http patrimeter https://patrimeter.example.org/mcp
```

| Tool | Scope | Role |
|---|---|---|
| `get_net_worth` | read | Net worth of a month, by level |
| `get_history` | read | Net worth month by month |
| `list_accounts`, `get_account` | read | Accounts, values; statements and shares of an account |
| `list_properties` | read | Properties and net value |
| `list_goals` | read | Savings goals and where each one stands |
| `get_challenges` | read | Challenges and stars of the month, with the "not investment advice" notice |
| `get_budget` | read | Household budget of the month |
| `list_account_types` | read | Account types |
| `create_account`, `save_statement`, `delete_statement` | write | Creating an account, statement of a month |

A write tool called with a read token answers `403`: the client then asks for access again with the write
scope. An assistant that calls these tools **sends your data to its provider**: the consent covers this too.

**Origin**: as the MCP specification asks against *DNS rebinding*, a request carrying an `Origin` header (sent by a
browser) is only accepted if it is the site's address (`DEFAULT_URI`) or one of the origins of `MCP_ALLOWED_ORIGINS`;
otherwise: `403`, before the token is even read. A client outside a browser (Claude Code, script) sends no such
header and is not concerned.

## Limits

- Rate per token: 120 requests in a row, then one per second (API and MCP together). Beyond that, `429 Too Many
  Requests` with `Retry-After` (seconds) and `{"title": "Trop de requêtes", "status": 429, "retry_after": …}`
  (error titles are in French).
- The sign-in log records access granted or revoked, not each call.
