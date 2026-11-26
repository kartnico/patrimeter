// Demonstration mode (DEMO_MODE, enabled by ci/app-env.sh): temporary account opened from the sign-in page, example data,
// guided tour, arrow of the collapsible budgets, restricted settings, and sign-out that deletes the account.
import { expect, logOut, test } from './support/helpers.mjs';

test('démo : compte temporaire, visite guidée, budgets dépliables, réglages fermés, suppression à la déconnexion', async ({
    page,
}) => {
    await page.goto('/connexion');
    await expect(page.locator('.demo-offer')).toContainText('sans inscription');
    await page.getByRole('button', { name: 'Essayer la démo' }).click();
    await expect(page).toHaveURL(/\/$/);
    await expect(page.locator('.demo-banner')).toContainText('Données fictives');
    await expect(page.locator('main')).toContainText('Résidence principale');

    // Guided tour: starts with the demo, highlights each step's element, stops and starts again.
    const card = page.locator('.tour-card');
    await expect(card).toContainText('étape 1 sur 13');
    await expect(page.locator('.summary:not(.history-summary)')).toHaveClass(/tour-highlight/);
    await card.getByRole('button', { name: 'Suivant' }).click();
    await expect(card).toContainText('La pyramide');
    await expect(page.locator('.pyramid-card')).toHaveClass(/tour-highlight/);
    await card.getByRole('button', { name: 'Arrêter la visite' }).click();
    await expect(card).toHaveCount(0);
    await page.getByRole('button', { name: 'Lancer la visite guidée' }).click();
    await expect(card).toContainText('étape 1 sur 13');

    // Collapsible budgets: an arrow that turns when the budget is open.
    await page.goto('/budget');
    const shared = page.locator('details.budget-detail').first();
    const summary = shared.locator('summary');
    const arrow = (property) =>
        summary.evaluate((element, name) => getComputedStyle(element, '::before')[name], property);
    expect(await arrow('content')).toBe('"▸"');
    expect(await arrow('transform')).toBe('none');
    await summary.click();
    await expect(shared).toHaveAttribute('open', '');
    await expect.poll(() => arrow('transform')).not.toBe('none');

    // Security settings: not available in the demonstration.
    await page.goto('/securite');
    await expect(page).toHaveURL(/\/$/);
    await expect(page.locator('.flash-error')).toContainText('pas disponible dans la démonstration');

    await logOut(page);
    await page.goto('/');
    await expect(page).toHaveURL(/\/connexion/);
});
