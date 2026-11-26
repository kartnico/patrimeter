# Security

## Reporting a vulnerability

Report any vulnerability through a **private vulnerability report** on GitHub:
<https://github.com/kartnico/patrimeter/security/advisories/new>.

- **Response** within 7 days: acknowledgement, then assessment of the severity.
- **Fix** published in a new version as soon as possible, within 90 days at the latest for a confirmed vulnerability;
  the CHANGELOG and the Release mention it.
- **Coordinated disclosure**: please do not publish any detail before the fix; the person who reported the
  vulnerability is credited if they wish.

Only the latest published version receives security fixes.

## Current state

Patrimeter is **multi-user**: separated accounts, mandatory password and TOTP second factor, wealth data encrypted
with a key per user ([docs/encryption.en.md](docs/encryption.en.md)), passkeys and FIDO2 keys.
Authentication flows: [docs/auth-flows.md](docs/auth-flows.md) (in French). Backups:
[docs/backup.en.md](docs/backup.en.md).

### What is protected

- Each user's wealth data: accounts, amounts, history, invisible and inaccessible to the other users.
- Access to the accounts: password, second factor, sessions.
- The confidentiality of registrations: the application does not reveal whether an address has an account.

### Assumptions

- The server administrator is trusted (see the limits below).
- HTTPS is mandatory in production, on the server or on a reverse proxy declared in `TRUSTED_PROXIES`: passkeys
  and the `__Host-` cookie do not exist without it.
- Dependencies come from recognised sources (Surý repository, Packagist, versioned `composer.lock`).

## Measures in place

| Threat | Measure | Where |
|---|---|---|
| Access to another user's data | Owner required by every repository method and filtered in every query (architecture test); voter on every account read; SQL forbidden outside the repositories (PHPStan rule); composite foreign key `(account_id, user_id)`; someone else's account not found (404, without revealing that it exists); test robots on every route | `AccountRepository`, `StatementRepository`, `OwnedResourceVoter`, `IsolationTest` |
| Guessing the password | Argon2id (64 MiB, 4 passes); at least 12 characters, Symfony's `PasswordStrength` (entropy ≥ 80 bits) and `NotCompromisedPassword` (not in known leaks); symfony/rate-limiter limiters: 5 failures per IP over 15 min, then blocking; per account, 5 failures then one try per minute, without blocking so that a third party cannot lock the user out; 100 failures per hour in total; counters in the database, without email addresses in clear; no check during a block | `AuthThrottle`, `config/packages/rate_limiter.yaml`, `PasswordRules` |
| Compromised password | Mandatory TOTP second factor (RFC 6238, 6 digits, 30 s, tolerance ± 15 s); code already used refused (replay); 5 wrong codes lock the account for 15 min; no page reachable before the code | scheb/2fa-bundle, `AuthenticationSubscriber`, `EnrollmentRequiredSubscriber` |
| Lost phone | 10 single-use backup codes (≈ 50 bits each), shown only once, consumed atomically; reminder when 3 or fewer remain; reset by the administrator as a last resort | `BackupCodes`, `app:user:reset-2fa` |
| Phishing | Passkeys and FIDO2 keys bound to the domain (`DEFAULT_URI`), single-use challenge, counter checked; management after confirming identity | `WebAuthn`, `WebAuthnController` |
| Stolen or reused API token | 256-bit token of which only the hash is stored; audience checked (RFC 8707); read scope by default; one-hour access, single-use refresh, and a replay revokes the whole authorization; invalidated with the sessions; revocation from My account; alert email; fixed `patrimeter_` prefix and fixed length, so that a secret detector spots a token published by mistake (`patrimeter-api-token` rule of `.gitleaks.toml`) | `ApiTokens`, `ApiTokenHandler`, `.gitleaks.toml` |
| Abuse of a valid API token (client loop, stolen token) | Rate limited per token on the API and MCP: 120 requests in a row, then one per second; beyond that, `429` with `Retry-After` | `ApiSubscriber`, `config/packages/rate_limiter.yaml` |
| Request forged towards the inside by an OAuth client metadata document | Symfony's `NoPrivateNetworkHttpClient` (neither local network nor loopback, including after DNS resolution), without redirects, 64 KB at most, 10 s timeout; the document must name itself | `ClientMetadataDocuments` |
| Hijacking the API's OAuth flow | Limited registration, exact redirect addresses (HTTPS or loopback), mandatory PKCE S256, single-use 60 s code, `state` and `iss` returned, explicit consent behind the complete sign-in | `OAuthServerController`, `ClientRedirect` |
| Request from another site to MCP | Host checked (DNS rebinding); `Origin` header refused with `403`, even before the firewall, if it is neither the site nor an origin of `MCP_ALLOWED_ORIGINS`; no cookie: bearer token only | `config/packages/mcp.yaml`, `ApiSubscriber` |
| Stolen backup | Wealth data encrypted in the archive; archives restricted to root (`0600`, folder `0700`); `AUTH_SECRET_KEY` outside the archive, to keep separately; copy off the server encrypted with age for public keys only, never in clear. Still readable: email addresses, password hashes, sign-in log and open sessions | `docs/backup/`, `docs/backup.en.md` |
| Leak of the database, backups or sessions | Data encrypted (XChaCha20-Poly1305) with a key per user, wrapped by their password (Argon2id) and their recovery key; ciphertext bound to its row and its owner; in the session, key wrapped under a key that only lives in an `HttpOnly` cookie | `Vault`, `DataKeys`, repositories |
| Database leak | TOTP secrets encrypted (XChaCha20-Poly1305) and backup codes as HMAC-SHA256, with subkeys of `AUTH_SECRET_KEY` (environment only); tokens sent by email stored as hashes | `AuthSecrets`, `OneTimeTokens` |
| Leak of `AUTH_SECRET_KEY` | Rotation without resetting the users: old key in `AUTH_SECRET_KEY_PREVIOUS` while the TOTP secrets are encrypted again (`app:auth-secrets:rotate`) and the backup codes regenerated (`docs/installation.en.md` [§ 12.1](docs/installation.en.md#rotating-auth_secret_key)) | `AuthSecrets`, `AuthSecretsRotateCommand` |
| Leak of a data export | Unencrypted file, by nature: export after confirming identity within 5 minutes, warning on screen; all-or-nothing import, into an empty account, with the API's validations | `DataTransferController`, `DataTransfer` |
| Account enumeration | Same answer and same cost (one Argon2id hash) for an unknown address or a wrong password; registration of an existing address: same page, informative email to its holder; unconfirmed address only reported with the right password | `PasswordLoginAuthenticator`, `Registration` |
| Registration in someone else's name | Confirmation link (24 h, single use) **and** the registration password required; a new registration of an unconfirmed address invalidates the old links; limits per IP (10 per hour), all addresses together (30 per hour) and per account (3 emails per hour) | `Registration`, `RegistrationController`, `AuthThrottle` |
| Bulk account creation, password attempts in bursts | Registrations capped per IP and in all; sign-in failures limited per IP, per account and in all; in nginx, 30 sign-in form submissions per minute and per address (429) | `AuthThrottle`, `docs/nginx/patrimeter-limits.conf` |
| Demo account misused (storage, sending emails, lasting access) | `DEMO_MODE`: temporary account without password or real address, data key only in the session; limited to the wealth, the budget and the export (no security settings, no API, no import, no email); 2,000 rows, forms of 32 KiB, limited writes; 50 accounts at a time, 5 creations per IP and per hour, 60 in all; deleted at sign-out or after 2 hours | `DemoAccounts`, `DemoGuard` |
| Email reminder misused | Monthly reminder chosen by the user, without amount or status (the server reads nothing outside the session), once a month at most, never for a demo account; single-use unsubscribe link, only its fingerprint kept, a page then a button (a link scanner unsubscribes nobody) | `EntryReminders`, `ReminderController` |
| Requests to unknown addresses (probes, exploits) | nginx only passes the addresses of the application to PHP (tested allowlist) and only accepts PUT, DELETE and OPTIONS for the API and MCP; anything else gets 404 or 403 without running code | `docs/nginx/patrimeter-app.conf`, `NginxAllowlistTest` |
| Poisoned links (Host header) | Links in emails built from `DEFAULT_URI`, never from the request | `PublicUrl` |
| Session theft or fixation | New identifier at sign-in, `HttpOnly`, `SameSite=Strict` cookie, `Secure` over HTTPS (`__Host-` prefix in production); expiry after 30 min of inactivity and 12 h at most; TOTP secret never written to the session; sessions closed when the password changes; open sessions listed (IP address, browser, last activity), to sign out one by one or all together, effective at the next request | `framework.yaml`, `SessionIdleTimeoutSubscriber`, `User`, `UserSessions` |
| Trusted browser misused | Explicit checkbox, never ticked by default; random `HttpOnly`, `SameSite=Strict` cookie, `Secure` with the `__Host-` prefix over HTTPS, of which only the hash is stored; valid 30 days, for this account only, and only with the credentials of that time (new password or signed-out sessions: void); spares the code, never the password; listed and removed from My account; sign-in without code recorded in the log | `TrustedBrowsers`, `SecondFactorCondition` |
| Stolen session used to take over the account | Current password required to change it; confirmation of identity within 5 minutes before changing the TOTP app, regenerating the codes or closing the sessions; alert email after every change | `Reauthentication`, `SecurityController` |
| Cross-site requests (CSRF) | Token checked on **every** POST before any controller, regenerated at sign-in; sign-out and TOTP code as POST with a token | `CsrfProtectionSubscriber` |
| Script injection (XSS) | Twig's automatic escaping; strict CSP without `unsafe-inline` (no inline script or style, QR code as SVG without style); JSON data encoded without any character that could close a tag; every CSP violation reported by the browser (`report-uri`, `report-to`) and logged in `var/log/csp-*.log`, addresses without query and tokens masked, rate limited per IP | `SecurityHeadersSubscriber`, `HistoryController`, `QrCodeSvg`, `CspReportController` |
| SQL injection | Prepared statements only, SQL confined to `src/Infrastructure/Persistence/`; taint analysis (Psalm) in the CI | repositories |
| Open redirect | Return addresses restricted to internal paths | `InternalPath`, `TwoFactorRedirects` |
| Framing (clickjacking) | `frame-ancestors 'none'`, `X-Frame-Options: DENY` | headers |
| Leaks through the browser | `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, `Permissions-Policy`, COOP/CORP | headers |
| Interception (with HTTPS) | One-year HSTS | headers |
| File exposure | Only `public/` is served; hidden files and PHP scripts other than the front controller refused by nginx; `open_basedir` and command execution functions disabled in php-fpm | `docs/nginx`, `docs/php-fpm` |
| Overly detailed error messages | Generic error pages in production, details only in `var/log/prod.log`; failure to send an email logged without the content of the message | `templates/bundles/TwigBundle/Exception/`, `AccountMailer` |
| Secrets in the repository | Secrets only in `.env.local` (not versioned, 0640); (null) development key refused in production; Gitleaks on the whole history in the CI | `.env.example`, `AuthSecrets`, CI |
| Vulnerable dependencies | `composer audit` in the CI; **security pipeline every Monday at 6 am** (`composer audit`, `npm audit`, Trivy, Semgrep, Gitleaks, GitLab SAST and secret detection, ZAP, Nuclei, and Nuclei against the deployed instance), even without new code, failure reported by email by GitLab; **Renovate every day**: update merge requests (PHP, Node, images and tools of the CI, container image), versions published for 14 days, a known flaw without waiting; project practices rated by **OpenSSF Scorecard** in every pipeline of `main`; CI images pinned by digest; Chart.js copied with its hash checked | CI (`docs/ci.md`), `assets/vendor/chart.js/README.md` |
| Code changed without review | `main` branch protected: no direct or forced push, merge through a merge request only | GitLab project settings |

## Sign-in log

Each user sees, on their My account page, their last 30 events (kept for 180 days): sign-ins, wrong passwords and
codes, backup codes used, identity confirmations, changes of password, app or codes, closing the other sessions.
Only the type, the success, the IP address, the browser and the date are recorded, never a secret.

## Known limits

- **Active administrator**: encryption protects against passive access (database, backups, sessions, logs). An
  administrator who changes the code can capture a password at the next sign-in; no web application escapes this.
- **Metadata visible to the administrator**: email addresses, number of accounts, statements and ownership shares,
  months entered, number of budget categories and items and the months in which its amounts were saved, number of
  members of a family account, number of savings goals, order of the budget's categories and items, day of the monthly
  reminder chosen, whether a Carbure link exists, technical dates, sign-in log, open sessions (IP
  address, browser, dates) and trusted browsers. The `app:user:list` command shows part of it (addresses, dates,
  number of accounts, last sign-in), without decrypting anything.
- **Carbure**: the instance address comes from the user. Patrimeter only calls `…/api/mcp`, with POST, without
  redirects, with a bounded delay and size, never sends back the response, and limits calls per user;
  `CARBURE_ALLOWED_HOSTS` restricts the hosts on an instance open to the public.
- **Password lost without a recovery key**: the data is permanently lost.
- **Demo mode**: off by default; turned on (`DEMO_MODE=true`), it lets anyone who reaches the sign-in page create a
  temporary account. Only turn it on for an instance dedicated to the demo ([settings](docs/reglages.en.md)).
- **Leaked passwords**: the check queries the Have I Been Pwned API (only the first 5 characters of the SHA-1
  hash). Server without outbound access or service unreachable: it is skipped, and only the entropy is checked.
- **Without HTTPS**, the password, the code and the session cookie travel in clear on the network. Behind a reverse
  proxy, the last hop to the server remains HTTP: keep it to a trusted network, and restrict it to the proxy's
  address (`allow` in the virtual host).
- **API token**: whoever holds it reads the data in clear until it expires or is revoked. An MCP client sends what
  it reads to its provider.
- **Experimental libraries**: `symfony/mcp-bundle` and `mcp/sdk` do not guarantee compatibility between versions
  yet; the MCP service is checked in the CI at every update.
- **Email alerts**: the message of a critical error goes to `ADMIN_EMAIL`. It contains no secret or wealth data
  (filtered logs, encrypted data), but may mention an IP address or a path.
- **Restoring a backup**: passwords, TOTP and recovery keys go back to their state at the time, and what was deleted
  since reappears ([docs/backup.en.md](docs/backup.en.md)).
- **Limiting by IP address**: behind a reverse proxy, `TRUSTED_PROXIES` and `X-Forwarded-For` are essential,
  otherwise all attempts seem to come from the proxy and share the same counter.
- **Global block**: 100 failures in one hour, all addresses together, block all sign-ins until the oldest ones leave
  this sliding hour; this is deliberate (distributed attack), at the cost of a possible denial of service.
