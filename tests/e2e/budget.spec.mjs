// Household budget: totals recalculated while typing, saving the month, budget flows and 50/30/20 rule.
// Demonstration user no. 16 (tests/e2e/seed.php): household of two adults and one child, budget of this month.
import { expect, logIn, seededUser, test } from './support/helpers.mjs';

test('budget : totaux pendant la frappe, enregistrement du mois, flux et règle 50/30/20', async ({ page }) => {
    await logIn(page, seededUser(16));
    await page.goto('/budget');
    await expect(page.locator('h1')).toContainText('Budget');
    await expect(page.locator('svg.sankey')).toBeVisible();
    await expect(page.locator('.budget-rule tbody tr')).toHaveCount(4);

    await page.getByRole('link', { name: /^Modifier le budget/ }).click();
    await expect(page).toHaveURL(/\/budget\/saisie/);
    await expect(page.locator('h1')).toContainText('Saisie');
    const housing = page.locator('[data-budget-category]').first();
    // Housing: 1 508,01 + 150 + 160 + 215 + 60; the rent goes up to 2 000.
    await expect(housing.locator('[data-budget-category-total]')).toHaveText(/^2\s093,01\s€$/u);
    const rent = housing.getByLabel('Loyer ou crédit');
    await rent.fill('2000');
    await expect(housing.locator('[data-budget-category-total]')).toHaveText(/^2\s585,00\s€$/u);
    await expect(page.locator('[data-budget-joint]')).toHaveText(/^4\s923,00\s€$/u);
    // Enter formats the amount and moves to the next item.
    await rent.press('Enter');
    await expect(rent).toHaveValue(/^2\s000,00$/u);
    await expect(housing.getByLabel('Charges')).toBeFocused();

    await page.getByRole('button', { name: /^Enregistrer le budget/ }).click();
    await expect(page).toHaveURL(/\/budget\?mois=\d{4}-\d{2}$/);
    await expect(page.locator('.flash-success')).toContainText('enregistré');
    await expect(page.locator('.budget-status')).toContainText('saisi ce mois-ci');
});

// Regression: on a phone (390 px), the column headings of "Budget trends" ran over each other ("Spending a month"
// could not wrap in its fixed-width column). Every heading now fits in its column.
test('budget : les titres des colonnes des tendances tiennent dans leur colonne sur mobile', async ({
    browser,
    baseURL,
}) => {
    const context = await browser.newContext({ baseURL, viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    await logIn(page, seededUser(16));
    await page.goto('/budget');
    const headings = page.locator('.budget-trends thead th');
    await expect(headings).toHaveCount(3);
    const overflowing = await headings.evaluateAll((cells) =>
        cells.filter((cell) => cell.scrollWidth > cell.clientWidth + 1).map((cell) => cell.textContent.trim()),
    );
    expect(overflowing).toEqual([]);
    await context.close();
});
