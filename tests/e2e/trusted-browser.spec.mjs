// Trusted browser: "trust this browser" ticked on the code screen, the next sign-in there asks for the password only;
// removed from My account, the code is asked for again.
import { expect, logOut, PASSWORD, seededUser, submitTotp, test, TOTP_SECRET } from './support/helpers.mjs';

const signInWithPassword = async (page, email) => {
    await page.goto('/connexion');
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
};

test('navigateur de confiance : connexion sans code, puis retiré depuis Mon compte', async ({ page }) => {
    const email = seededUser(8);
    await signInWithPassword(page, email);
    await expect(page).toHaveURL(/\/connexion\/code$/);
    await page.getByLabel('Faire confiance à ce navigateur pendant 30 jours').check();
    await submitTotp(page, TOTP_SECRET, email);
    await expect(page).toHaveURL(/\/$/);
    await logOut(page);

    await signInWithPassword(page, email);
    await expect(page).toHaveURL(/\/$/);

    await page.goto('/securite/connexion');
    const section = page.locator('#trusted-browsers');
    await expect(section.locator('tr.is-current')).toContainText('ce navigateur');
    await page.locator('#reauth_password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Confirmer mon identité' }).click();
    await section.getByRole('button', { name: 'Retirer', exact: true }).click();
    await expect(page.locator('#trusted-browsers')).toContainText('Aucun navigateur de confiance.');
    await logOut(page);

    await signInWithPassword(page, email);
    await expect(page).toHaveURL(/\/connexion\/code$/);
});
