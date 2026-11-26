import { randomBytes } from 'node:crypto';
import {
    confirmRecoveryKey,
    expect,
    firstLink,
    freshTotp,
    lastMailTo,
    logIn,
    logOut,
    PASSWORD,
    RECOVERY_KEY,
    seededUser,
    submitTotp,
    test,
    TOTP_SECRET,
} from './support/helpers.mjs';

// End of the first flow: TOTP, backup codes, recovery key. Returns the TOTP secret.
const finishEnrollment = async (page, account) => {
    await expect(page).toHaveURL(/\/securite\/application/);
    const secret = (await page.locator('.secret-key code').innerText()).replace(/\s/g, '');
    await page.locator('input#code').fill(await freshTotp(secret, account));
    await page.getByRole('button', { name: 'Activer' }).click();
    await page.getByRole('link', { name: "J'ai noté mes codes" }).click();
    await confirmRecoveryKey(page);
    return secret;
};

test("inscription, confirmation de l'adresse, TOTP, clé de récupération puis reconnexion", async ({ page }) => {
    const email = `inscrit-${randomBytes(4).toString('hex')}@example.test`;
    const password = 'une phrase de passe assez longue';

    await page.goto('/connexion');
    await page.getByRole('link', { name: 'Créer un compte' }).click();
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    await page.getByLabel('Confirmation du mot de passe').fill(password);
    await page.getByRole('button', { name: 'Créer mon compte' }).click();
    await expect(page).toHaveURL(/\/inscription\/envoyee/);

    const mail = await lastMailTo(email, 'Patrimeter');
    await page.goto(firstLink(mail));
    await page.getByLabel("Mot de passe choisi à l'inscription").fill(password);
    await page.getByRole('button', { name: 'Confirmer' }).click();
    const secret = await finishEnrollment(page, email);

    await logOut(page);
    await logIn(page, email, password, secret);
    await expect(page).toHaveURL(/\/$/);
});

test('connexion : mot de passe faux refusé, puis mot de passe et code TOTP', async ({ page }) => {
    const email = seededUser(1);
    await page.goto('/connexion');
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill('mauvais mot de passe');
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Connexion' })).toBeVisible();
    await expect(page.getByText(/incorrect|invalide/i)).toBeVisible();

    await logIn(page, email);
    await expect(page).toHaveURL(/\/$/);
    await page.goto('/comptes');
    await expect(page.getByRole('link', { name: 'Livret A' })).toBeVisible();

    await logOut(page);
    await page.goto('/comptes');
    await expect(page).toHaveURL(/\/connexion/);
});

test('mot de passe oublié avec la clé de récupération : les données sont conservées', async ({ page }) => {
    const email = seededUser(2);
    const password = 'nouvelle phrase de passe assez longue';
    await page.goto('/connexion');
    await page.getByRole('link', { name: 'Mot de passe oublié ?' }).click();
    await page.getByLabel('Adresse e-mail').fill(email);
    await page
        .getByRole('button')
        .filter({ hasText: /Envoyer|Recevoir/ })
        .click();
    await expect(page).toHaveURL(/\/mot-de-passe-oublie\/envoye/);

    await page.goto(firstLink(await lastMailTo(email, 'mot de passe')));
    await page.getByLabel('Nouveau mot de passe').fill(password);
    await page.getByLabel('Confirmation').fill(password);
    await page.locator('#recovery_key').fill(RECOVERY_KEY);
    await page.getByLabel("Code de l'application").fill(await freshTotp(TOTP_SECRET, email));
    await page.getByRole('button', { name: 'Changer le mot de passe' }).click();
    await expect(page).toHaveURL(/\/connexion/);

    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    await submitTotp(page, TOTP_SECRET, email);
    await page.goto('/comptes');
    await expect(page.getByRole('link', { name: 'Livret A' })).toBeVisible();
    expect(PASSWORD).not.toBe(password);
});

test('langue : la langue en cours est affichée, le menu passe en anglais puis revient au français', async ({
    page,
}) => {
    await page.goto('/connexion');
    const toggle = page.locator('details.locale-menu > summary');
    const english = page.getByRole('button', { name: 'English' });
    await expect(toggle).toContainText('FR');

    // The menu closes with Escape, then with a click elsewhere.
    await toggle.click();
    await expect(english).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(english).toBeHidden();
    await toggle.click();
    await page.locator('h1').click();
    await expect(english).toBeHidden();

    await toggle.click();
    await english.click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('h1')).toHaveText('Sign in');
    await expect(toggle).toContainText('EN');

    await toggle.click();
    await page.getByRole('button', { name: 'Français' }).click();
    await expect(page.locator('h1')).toHaveText('Connexion');
});
