// Family account: a member downloads their data from "Your data", the family account imports this file for them (it
// replaces the previous import), then shows the family or each one. Demonstration family account (tests/e2e/seed.php):
// members imported from users no. 1 and 2, under the first names Camille and Julien.
import { readFile } from 'node:fs/promises';
import { expect, logIn, PASSWORD, seededUser, test } from './support/helpers.mjs';

test('compte famille : export d’un membre, import pour lui, vues de la famille et de chacun', async ({
    browser,
    baseURL,
}) => {
    // The member (user no. 13) downloads their data, after confirming their identity.
    const memberContext = await browser.newContext({ baseURL, locale: 'fr-FR' });
    const member = await memberContext.newPage();
    await logIn(member, seededUser(13));
    await member.goto('/donnees');
    await member.locator('#reauth_password').fill(PASSWORD);
    await member.getByRole('button', { name: 'Confirmer mon identité' }).click();
    const [download] = await Promise.all([
        member.waitForEvent('download'),
        member.getByRole('button', { name: 'Télécharger mes données' }).click(),
    ]);
    // Copy kept: the downloaded file disappears with the member's context.
    const file = test.info().outputPath('export.json');
    await download.saveAs(file);
    const exported = JSON.parse(await readFile(file, 'utf8'));
    expect(exported.format).toBe('patrimeter');
    await memberContext.close();

    // The family account replaces Camille's import with this file.
    const familyContext = await browser.newContext({ baseURL, locale: 'fr-FR' });
    const family = await familyContext.newPage();
    await logIn(family, seededUser('famille'));
    await family.goto('/famille');
    await expect(family.locator('section.family-member h2')).toHaveText(['Camille', 'Julien']);
    const camille = family.locator('section.family-member').filter({ hasText: 'Camille' });
    await expect(camille.locator('.family-pill')).toBeVisible();
    await camille.locator('input[type="file"]').setInputFiles(file);
    await camille.getByRole('button', { name: 'Mettre à jour' }).click();
    await expect(family.locator('.flash-success')).toContainText('Données de Camille importées');

    // View of the family, then of Julien alone; the choice is kept from one page to the next.
    await family.goto('/');
    const scopes = family.getByRole('navigation', { name: 'Périmètre' });
    await expect(scopes.getByRole('link')).toHaveText(['Toute la famille', 'Camille', 'Julien']);
    await expect(scopes.locator('[aria-current="true"]')).toHaveText('Toute la famille');
    await expect(family.locator('.levels-card')).toContainText('· Camille');
    await scopes.getByRole('link', { name: 'Julien' }).click();
    await expect(scopes.locator('[aria-current="true"]')).toHaveText('Julien');
    await family.goto('/budget');
    await expect(family.getByRole('navigation', { name: 'Périmètre' }).locator('[aria-current="true"]')).toHaveText(
        'Julien',
    );
    await expect(family.getByText('Budget importé, en lecture seule')).toBeVisible();
    await familyContext.close();
});
