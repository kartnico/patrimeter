# Patrimeter

Patrimeter tracks your **net worth month by month** and shows it as a **pyramid**: from your main residence,
at the base, up to the riskiest investments, at the top. Every month, you enter the balances of your accounts
and loans; Patrimeter works out the rest.

![Net worth page: net worth pyramid](captures/tableau-de-bord-ordinateur.png)

## What Patrimeter does

- **Six-level pyramid**: main residence, emergency savings, guaranteed funds, equities, real estate,
  alternative. Each level shows its net value (assets minus loans) and its share of the total.
- **Monthly update** on a single screen; an account without a balance this month keeps its last known balance. The
  Monthly update menu stands out as long as the month is not entered, and an email reminder is possible.
- **History**, at the bottom of the Net worth page: how your net worth and each level change over time, as a chart
  and a table, and what was put into or taken out of each level since the start of the year.
- **Report to share**: the month's net worth on a card that fills the phone screen, with or without the amounts,
  as a screenshot or as a picture (Mastodon, X, Facebook, WhatsApp, Instagram…).
- **Real estate** owned in part (joint ownership, couple…), with the loans linked to it.
- **[Savings goals](utilisation/objectifs.md)**: an amount to reach by a date (or months of budget spending); every
  month, Patrimeter compares your pace with the effort needed and tells whether you are on track, with a projection
  chart.
- **Trends** of the net worth and of the budget over 3, 6 and 12 months, compared with the period before.
- **[Household budget](utilisation/budget.md)**: a shared budget paid into the joint account and each adult's
  share, each person's own expenses and savings, income flows compared with the 50/30/20 rule, read alongside the
  month's net worth.
- **[Family account](utilisation/famille.md)**: each person keeps their account, and a family account brings their
  data together every month for a view of the family.
- **[Carbure](utilisation/carbure.md)**: actual spending read from your Carbure instance, next to the planned budget.
- **Recommendations**: emergency savings compared with the month's expenses, on a gauge, with an alert below the
  target.
- **Benchmarks**: how your investments are split compared with a balanced allocation, and where your net worth
  stands among French households and among adults worldwide.
- **On phone and computer alike**, in French or English, in a light or dark theme, accessible (contrast,
  colour blindness, keyboard).
- **API and MCP service** for your own tools or an assistant, with the same authentication.

> **Not financial advice.** Patrimeter is a tracking tool: its benchmarks (pyramid, split of the investments,
> emergency savings, goals) are indicative and are neither financial advice nor investment advice, which is reserved
> for licensed professionals. Adapt them to your situation.

## Your data stays yours

- **Self-hosted** application: your data does not leave your server.
- Wealth data **encrypted with a key specific to each user**, itself protected by their password: neither the
  server administrator nor a stolen backup can read it.
- **Mandatory two-factor** sign-in (authenticator app), passkeys (FIDO2) and security keys available; open sessions
  visible and signed out from My account.

## Where to start

- You are discovering the application: [quick start](demarrage-rapide.md), or **Try the demo** on the sign-in page
  of an instance that offers it ([trying without an account](utilisation/premiere-connexion.md#trying-without-an-account)).
- You are installing it: on a Linux server ([installation](exploitation/installation.md)) or in a container,
  with Docker or Podman ([container deployment](exploitation/docker.md)); you are updating it:
  [updating](exploitation/mise-a-jour.md).
- You already use it: [user guide](utilisation/comptes.md) and [frequently asked questions](utilisation/questions.md).
  In the application, **Documentation** (account menu, and sign-in page) leads back here.
