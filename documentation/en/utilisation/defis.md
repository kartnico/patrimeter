# Challenges and stars

**Challenges** menu of the main bar: stars for good habits (entering your balances every month, keeping your budget,
holding emergency savings, moving towards your goals, protecting your account), a level that rises with them and a
saver profile. Nothing more to enter: everything is computed from your data.

> **Not investment advice.** Indicative benchmarks, to adapt to your situation: this is not investment advice. No
> challenge rewards a return, the share of shares or cryptocurrencies, a product or more risk, and Patrimeter never
> compares you with the other users.

![Challenges and stars](../captures/defis-ordinateur.png)

## The Challenges page

At the top, **My level** and **My profile** give your two statuses; each leads to its card. Then come:

- **My stars**: the total, on a dial, and **Where do my stars come from?**, the stars earned by family of challenges
  (first steps, balance entries, budget and goals, cushion and credit, account security);
- **My level**: where you stand between your level and the next one, and how many stars are missing; **See the 6
  levels** unfolds the ladder;
- **My profile**: your stage, the next one, a bar when its condition can be counted (months entered, months of
  spending set aside…) and what it asks; **See the 5 stages** unfolds the ladder;
- **My streak**: the months entered in a row and the joker of the year.

## The challenges

Each challenge gives up to three stars, at thresholds (five for **First steps**, two for **Protected account**):

| Challenge | One star | Two stars | Three stars |
|---|---|---|---|
| **First steps** | one star per step done, five in all (see below) | | |
| **Entry streak** | 3 months in a row | 6 months in a row | 12 months in a row |
| **Wealth up to date** | 1 month where every account has its balance entered | 6 months | 12 months |
| **Safety cushion** | 1 month of spending set aside | half of your target | your target |
| **No consumer loan** | 1 month without consumer loan | 6 months in a row | 12 months in a row |
| **Goal under way** | 25% of your most advanced goal | 50% | 75% |
| **Budget kept up to date** | 1 month of budget entered | 3 months | 6 months |
| **Protected account** | a passkey | a second one, as a spare | |
| **Savings rate kept** | 1 month at the rate you aim at | 3 months in a row | 6 months in a row |

- A month counts in the **streak** as soon as at least one balance is entered in it (not only carried over). As long as
  the current month is not entered, the streak goes on from the previous month. **One forgotten month per calendar year
  is forgiven** (the joker), if it follows an entered month: the page says whether the joker of the year is still
  available.
- The **safety cushion** is measured in months of spending (needs and wants) of the [budget](budget.md), and the target
  is the tax household's (6 months by default). Without household nor budget, the challenge says so.
- The **goal under way** follows your most advanced [savings goal](objectifs.md). Without any goal, the challenge says
  so.
- The **savings rate kept** counts the months in a row where the [budget](budget.md) in force plans savings of at least
  the rate **you** choose on its card (from 5% to 50%): Patrimeter suggests none. Without a rate chosen, nor a budget,
  the challenge says so. A month without a budget entered takes the previous month's, as in the budget.

Each card shows its score ("1 star earned out of 3"), then its thresholds from left to right, each under its star:
**Earned** (full star), **Next** (circled star) or **Later**. Then come **Already done** (where you stand this month)
and what is missing for the next star, for example "For the 2nd star: 3 more months entered in a row."; a challenge
that must first be made measurable says what to do (**Before you start**). **Rule of the challenge** unfolds what it
rewards. The challenges whose stars are all earned move to **Completed**, marked **Challenge completed**.

**A star earned is never lost**: if a streak stops or a balance goes down, the stars already obtained stay; only the
progress of the month changes.

## Stars after the monthly entry

After **Save … balances** in the monthly entry, a message announces the new highest score for each challenge, for
example “New star, challenge ‘Entry streak’: 1 of 3.” Checking the dashboard, Challenges page or API first does not
dismiss the announcement. Saving the same balances again does not repeat it. If you turn challenges off, you can still
save balances without star announcements. Your stars and any pending announcements are kept for when you turn
challenges back on.

## Level

The total of the stars gives a **level**, from 1 to 6: Curious (from the start), Attentive (4 stars), Steady (8),
Far-sighted (13), Consistent (18) and Master of the pyramid (24). In all, 28 stars are possible: the Challenges page
shows "8 stars of 28" at the top, and the ladder of the six levels, from the first to the last, with yours (**you are
here**) and what is missing for the next one.

## Saver profile

The **stage** is computed every month; it is the highest rung whose condition is met, along with those of every rung
below:

1. **Discovery**: a first month entered;
2. **Organized**: three months where every account has its balance entered;
3. **Protected**: emergency savings reaching your target, in months of the budget's spending;
4. **Serene**: no consumer loan to repay;
5. **Builder**: a savings goal reached and twelve months of entries in a row.

The **style**, optional, is chosen in **My profile**, **Challenges and stars** section: **Ant** (a little every month),
**Project** (a dated project), **Home** (the home and its loan first) or **Horizon** (the long term). It only changes
the order of the challenges shown; it says nothing about what you should buy nor about the risk to take. The profile is
not a risk profile test.

## "First steps" card

On the **Net worth** page, as long as the five first steps have not been done once, a card lists them with a link to
each: describe your household, create your accounts, enter the month's balances, add your home or a property, set a
first savings goal. Each step is worth a star, counted as soon as the card is shown. As long as you have not chosen a
saver style, the card offers it. **Hide this card** removes it; the Challenges page brings it back.

## AI agents

The challenges can also be read through the [API and the MCP service](api.md) (`GET /api/v1/challenges`,
`get_challenges` tool), with the same notice: this is not investment advice.

## Turning the challenges off

**Challenge settings**, at the bottom of the Challenges page, or **My account > My profile**, **Challenges and stars**
section: **Turn off challenges and stars** removes the page from the navigation, the "First steps" card and the
messages after the entry. Your stars are kept; **Turn on challenges and stars** brings them back.

The administrator can also remove them for the whole instance (`GAMIFICATION=false`,
[installation](../exploitation/installation.md#3-configuring)).
