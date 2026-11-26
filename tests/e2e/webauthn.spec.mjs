import { addVirtualAuthenticator, expect, logIn, logOut, PASSWORD, seededUser, test } from './support/helpers.mjs';

const addDevice = async (page, label, usage) => {
    await page.goto('/securite/connexion');
    if (await page.locator('#reauth_password').isVisible()) {
        await page.locator('#reauth_password').fill(PASSWORD);
        await page.getByRole('button', { name: 'Confirmer mon identité' }).click();
    }
    await page.getByLabel("Nom de l'appareil").fill(label);
    await page.getByLabel('Usage').selectOption(usage);
    await page.getByRole('button', { name: 'Ajouter cet appareil' }).click();
    await expect(
        page
            .getByText('Appareil ajouté')
            .or(page.locator(`input[value="${label}"]`))
            .first(),
    ).toBeVisible();
};

test('passkey avec PRF : connexion sans mot de passe, données déverrouillées', async ({ context, page }) => {
    const email = seededUser(3);
    await addVirtualAuthenticator(context, page);
    await logIn(page, email);
    await addDevice(page, 'Téléphone', 'passkey');
    await logOut(page);

    await page.getByRole('button', { name: 'Se connecter avec une passkey (FIDO2)' }).click();
    await expect(page).toHaveURL(/\/$/);
    await page.goto('/comptes');
    await expect(page.getByRole('link', { name: 'Livret A' })).toBeVisible();
});

test('passkey sans PRF : connexion, puis déverrouillage par le mot de passe', async ({ context, page }) => {
    const email = seededUser(4);
    await addVirtualAuthenticator(context, page, { hasPrf: false });
    await logIn(page, email);
    await addDevice(page, 'Ancien téléphone', 'passkey');
    await logOut(page);

    await page.getByRole('button', { name: 'Se connecter avec une passkey (FIDO2)' }).click();
    await expect(page).toHaveURL(/\/deverrouiller/);
    await page.locator('input#password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Déverrouiller' }).click();
    await page.goto('/comptes');
    await expect(page.getByRole('link', { name: 'Livret A' })).toBeVisible();
});

test('clé de sécurité FIDO2 en second facteur', async ({ context, page }) => {
    const email = seededUser(5);
    await addVirtualAuthenticator(context, page, { hasResidentKey: false, transport: 'usb', hasPrf: false });
    await logIn(page, email);
    await addDevice(page, 'Clé USB', 'security_key');
    await logOut(page);

    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    await page.getByRole('button', { name: 'Utiliser ma clé de sécurité' }).click();
    await expect(page).toHaveURL(/\/$/);
    await page.goto('/comptes');
    await expect(page.getByRole('link', { name: 'Livret A' })).toBeVisible();
});
