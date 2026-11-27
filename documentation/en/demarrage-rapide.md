# Quick start

## Installing Patrimeter (administrator)

Two ways, at your choice:

- **With Docker**: download `compose.yaml` and `patrimeter.env.example` from `docker/`, configure `patrimeter.env`
  from the template, then run `docker compose up -d`
  ([Installing with Docker](exploitation/docker.md)).
- **On Linux**: the archive of a version, with PHP and nginx ([Installing on Linux](exploitation/installation.md)).

Either way, you need **HTTPS** access (reverse proxy or certificate) and, ideally, an **SMTP** server for the emails.
On the first visit, the **installation wizard** checks the configuration and creates the first account, yours.

**Updating**: one command, the database being backed up automatically first
([updating](exploitation/mise-a-jour.md)).

## Starting to track your net worth (user)

1. **Create your account** from the sign-in page ("Create an account"), then confirm your address with the link
   received by email. → [First sign-in](utilisation/premiere-connexion.md)
2. **Set up your authenticator app** (Google Authenticator, Aegis, 1Password…) by scanning the QR code, and
   write down your **backup codes**.
3. **Write down your recovery key**: it is what saves your data if you forget your password.
4. **Create your accounts and loans**: current account, savings accounts, life insurance, PEA, consumer loan…
   Your **properties** and their loans are created in the **Real estate** tab of My account.
   → [Accounts](utilisation/comptes.md)
5. **Enter their balances** for the month. → [Monthly update](utilisation/saisie.md)
6. **Look at your pyramid** on the Net worth page, then come back every month: the Monthly update menu reminds you
   as long as the month is not entered. → [Net worth and history](utilisation/tableau-de-bord.md)
7. **Set yourself goals** (Monthly update, Goals tab) and follow them in the **Goals** menu; and, if you wish, the
   **household budget**. → [Savings goals](utilisation/objectifs.md), [budget](utilisation/budget.md)

To discover Patrimeter without an account: **Try the demo** on the sign-in page, if the instance offers it
([trying without an account](utilisation/premiere-connexion.md#trying-without-an-account)).

![Monthly update on a phone](captures/saisie-mobile.png)
