import { expect, logIn, parseAmount, seededUser, test } from './support/helpers.mjs';

test("thème : la bascule s'applique sans rechargement et persiste", async ({ page }) => {
    await page.goto('/connexion');
    const html = page.locator('html');
    await page.getByRole('button', { name: /thème/i }).click();
    const first = await html.getAttribute('data-theme');
    expect(['light', 'dark']).toContain(first);
    await page.reload();
    await expect(html).toHaveAttribute('data-theme', first);
    await page.getByRole('button', { name: /thème/i }).click();
    await expect(html).toHaveAttribute('data-theme', first === 'dark' ? 'light' : 'dark');
});

test('thème : la bascule fonctionne aussi sans JavaScript', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ baseURL, javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/connexion');
    const before = await page.locator('html').getAttribute('data-theme');
    await page.getByRole('button', { name: /thème/i }).click();
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', before ?? '');
    await context.close();
});

test("saisie mensuelle : le montant saisi se retrouve sur l'accueil", async ({ page }) => {
    await logIn(page, seededUser(6));
    await page.goto('/saisie');
    await page.getByLabel('Livret A').fill('12 345,67');
    await page.getByRole('button', { name: /Valider la saisie/ }).click();
    await expect(page).toHaveURL(/\?mois=/);
    await page.goto('/comptes');
    const row = page
        .locator('li, tr')
        .filter({ has: page.getByRole('link', { name: 'Livret A' }) })
        .first();
    await expect(row).toContainText('12');
    expect(parseAmount(await row.innerText())).toBeCloseTo(12345.67, 2);
});

test('bien immobilier : le bien et ses crédits se créent ensemble, selon la part', async ({ page }) => {
    await logIn(page, seededUser(7));
    await page.goto('/biens');
    await page.getByRole('link', { name: 'Nouveau bien' }).click();
    await page.getByLabel('Nom', { exact: true }).first().fill('Maison de test');
    await page.getByRole('combobox', { name: 'Type' }).fill('lmnp');
    await page.getByRole('option', { name: 'LMNP' }).click();
    await page.getByLabel('Valeur totale du bien').fill('200 000');
    await page.getByLabel('Votre part').fill('50');
    await page.locator('#loan-name-0').fill('Prêt commun');
    await page.locator('#loan-amount-0').fill('100 000');
    await page.getByRole('button', { name: 'Ajouter un crédit' }).click();
    await page.locator('#loan-name-3').fill('Prêt personnel');
    await page.locator('#loan-amount-3').fill('10 000');
    await page.locator('#loan-mode-3').selectOption('own');
    await page.getByRole('button', { name: 'Créer le bien' }).click();

    await expect(page).toHaveURL(/\/biens$/);
    const card = page.locator('.property').filter({ hasText: 'Maison de test' });
    await expect(card.getByText('Prêt personnel')).toBeVisible();
    expect(parseAmount(await card.locator('.property-net td').innerText())).toBe(40000);
});

test('nouveau compte : on tape dans le type, la liste ne garde que ce qui contient le texte, sans casse ni accents', async ({
    page,
}) => {
    await logIn(page, seededUser(10));
    await page.goto('/comptes/nouveau');
    const type = page.getByRole('combobox', { name: 'Type' });
    // Options of the typing list only (the other lists of the page have options too).
    const options = page.getByRole('listbox').getByRole('option');

    await type.click();
    // 38 types, minus the 7 property types (created from the property form).
    await expect(options).toHaveCount(31);

    await type.fill('LIVRET');
    await expect(options).toHaveCount(2);
    await type.press('Enter');
    await expect(type).toHaveValue(/Livret/);
    await expect(page.locator('#type_id')).toHaveValue(/\d+/);

    // The chosen type sets the level and the nature.
    await type.fill('epargne');
    await page.getByRole('option', { name: /Épargne salariale/ }).click();
    await expect(page.locator('#level_code')).toHaveValue('actions');

    await type.fill('zzz');
    await expect(options).toHaveCount(0);
    await expect(page.getByText('Aucun type ne correspond.').first()).toBeVisible();
    await type.press('Escape');
    await expect(type).toHaveValue(/Épargne salariale/);
});

test('nouveau bien : même liste à saisie pour les types de bien', async ({ page }) => {
    await logIn(page, seededUser(11));
    await page.goto('/biens/nouveau');
    const type = page.getByRole('combobox', { name: 'Type' });
    // Options of the typing list only (the other lists of the page have options too).
    const options = page.getByRole('listbox').getByRole('option');

    await type.click();
    await expect(options).toHaveCount(7);
    await type.fill('scpi');
    await expect(options).toHaveCount(1);
    await page.getByRole('option', { name: 'SCPI' }).click();
    await expect(type).toHaveValue('SCPI');

    // With a choice made, a click shows the whole list again; so does the arrow of the field.
    await type.click();
    await expect(options).toHaveCount(7);
    await type.press('Escape');
    await page.locator('.combobox-toggle').click();
    await expect(options).toHaveCount(7);

    await type.fill('RESIDENCE');
    await expect(options).toHaveCount(2);
    await type.press('ArrowDown');
    await type.press('Enter');
    await expect(type).toHaveValue(/Résidence secondaire/);
});

test("saisie : les soldes d'un autre mois, choisi dans le calendrier, se copient sans être enregistrés", async ({
    page,
}) => {
    await logIn(page, seededUser(12));
    await page.goto('/saisie');
    const source = page.locator('#copy-from');

    await page.getByRole('button', { name: 'Choisir le mois dans un calendrier' }).click();
    await page.getByRole('button', { name: 'Année précédente', exact: true }).click();
    await page.getByRole('button', { name: /^mars \d{4}$/ }).click();
    await expect(source).toHaveValue(/^\d{4}-03$/);
    await expect(page.getByRole('button', { name: /^mars \d{4}$/ })).toBeHidden();

    await page.getByRole('button', { name: 'Copier', exact: true }).click();
    await expect(page).toHaveURL(/depuis=\d{4}-03/);
    await expect(page.locator('p.flash')).toContainText('copiés');
    await expect(page.locator('.badge-copied').first()).toBeVisible();
});

test("saisie : le total du niveau et l'écart suivent la frappe, Entrée passe au champ suivant sans envoyer", async ({
    page,
}) => {
    await logIn(page, seededUser(13));
    await page.goto('/saisie');
    const field = page.getByLabel('Livret A');
    const row = page.locator('[data-entry-row]').filter({ has: field });
    const total = page.locator('[data-entry-level]').filter({ has: field }).locator('[data-level-total]');
    const before = parseAmount(await total.innerText());
    const initial = parseAmount(await field.inputValue());

    await field.fill(String(Math.round(initial) + 100));
    await expect
        .poll(async () => parseAmount(await total.innerText()))
        .toBeCloseTo(before - initial + Math.round(initial) + 100, 2);
    await expect(row.locator('[data-entry-diff]')).toContainText(' vs ');

    await field.press('Enter');
    await expect(row).toHaveClass(/is-touched/);
    await expect(row.locator('.status-confirmed')).toBeVisible();
    await expect(field).not.toBeFocused();
    await expect(page).toHaveURL(/\/saisie$/);

    // Escape restores the suggested amount.
    await field.focus();
    await field.press('Escape');
    expect(parseAmount(await field.inputValue())).toBeCloseTo(initial, 2);
    await expect(row).not.toHaveClass(/is-touched/);

    // "=" takes the previous month's balance, confirms it and moves to the next field.
    const same = row.locator('[data-entry-same]');
    const previous = Number(await row.getAttribute('data-previous')) / 100;
    await expect(same).toBeVisible();
    await same.click();
    expect(parseAmount(await field.inputValue())).toBeCloseTo(previous, 2);
    await expect(row).toHaveClass(/is-touched/);
    await expect(same).toBeHidden();
    await expect(field).not.toBeFocused();
});
