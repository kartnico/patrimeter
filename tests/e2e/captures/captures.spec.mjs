import { expect, logIn, seededUser, test } from '../support/helpers.mjs';

// Screenshots of the user documentation, in each language, generated again on every main pipeline: they always show
// the application as it is. Same file names in documentation/fr/captures and documentation/en/captures. Demonstration
// data: tests/e2e/seed.php (English account names for English). Reduced motion: no micro-animation caught halfway.
const languages = [
    {
        code: 'fr',
        locale: 'fr-FR',
        directory: 'documentation/fr/captures',
        entryHelp: 'Aide de la saisie',
        shortcuts: 'Raccourcis clavier',
    },
    {
        code: 'en',
        locale: 'en-GB',
        directory: 'documentation/en/captures',
        entryHelp: 'Help with the monthly update',
        shortcuts: 'Keyboard shortcuts',
    },
];

const screens = [
    ['tableau-de-bord', '/'],
    ['rapport', '/rapport'],
    ['rapport-pourcentages', '/rapport?montants=0'],
    ['saisie', '/saisie'],
    ['comptes', '/comptes'],
    ['nouveau-compte', '/comptes/nouveau'],
    ['biens', '/biens'],
    ['nouveau-bien', '/biens/nouveau'],
    ['objectifs', '/objectifs'],
    ['defis', '/defis'],
    ['bienvenue-pyramide', '/bienvenue/pyramide'],
    ['saisie-express', '/bienvenue/express'],
    ['bienvenue-questions', '/bienvenue/questions'],
    ['saisie-objectifs', '/saisie/objectifs'],
    ['budget', '/budget'],
    ['budget-saisie', '/budget/saisie'],
    ['budget-foyer', '/budget/foyer'],
    ['budget-carbure', '/budget/carbure'],
    ['securite', '/securite'],
    ['securite-connexion', '/securite/connexion'],
    ['securite-agents-ia', '/securite/agents-ia'],
    ['donnees', '/donnees'],
];

const devices = [
    // One user per device: the same TOTP code only serves once.
    { name: 'ordinateur', viewport: { width: 1280, height: 860 }, user: 1 },
    { name: 'mobile', viewport: { width: 390, height: 844 }, user: 2 },
];

for (const language of languages) {
    for (const device of devices) {
        test(`captures sur ${device.name} (${language.code})`, async ({ browser, baseURL }) => {
            const context = await browser.newContext({
                baseURL,
                locale: language.locale,
                viewport: device.viewport,
                colorScheme: 'light',
                reducedMotion: 'reduce',
            });
            const page = await context.newPage();

            for (const [name, path] of [
                ['connexion', '/connexion'],
                ['inscription', '/inscription'],
            ]) {
                await page.goto(path);
                await expect(page.locator('html')).toHaveAttribute('lang', language.code);
                await page.screenshot({ path: `${language.directory}/${name}-${device.name}.png`, fullPage: true });
            }

            await logIn(page, seededUser(device.user, language.code));
            for (const [name, path] of screens) {
                await page.goto(path);
                await expect(page.locator('h1').first()).toBeVisible();
                await page.screenshot({ path: `${language.directory}/${name}-${device.name}.png`, fullPage: true });
            }
            await context.close();
        });
    }

    test(`capture du compte famille (${language.code})`, async ({ browser, baseURL }) => {
        const context = await browser.newContext({
            baseURL,
            locale: language.locale,
            viewport: { width: 1280, height: 860 },
            colorScheme: 'light',
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        await logIn(page, seededUser('famille', language.code));
        await page.goto('/famille');
        await expect(page.locator('section.family-member')).toHaveCount(2);
        await page.screenshot({ path: `${language.directory}/famille-ordinateur.png`, fullPage: true });
        await page.goto('/');
        await page.screenshot({ path: `${language.directory}/tableau-de-bord-famille-ordinateur.png`, fullPage: true });
        await context.close();
    });

    test(`capture du thème sombre (${language.code})`, async ({ browser, baseURL }) => {
        const context = await browser.newContext({
            baseURL,
            locale: language.locale,
            viewport: { width: 1280, height: 860 },
            colorScheme: 'dark',
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        await logIn(page, seededUser(3, language.code));
        await page.goto('/');
        // The dark theme is the one shown: the browser's preference reaches the page.
        await expect(page.locator('h1').first()).toBeVisible();
        expect(await page.evaluate(() => matchMedia('(prefers-color-scheme: dark)').matches)).toBe(true);
        await page.screenshot({ path: `${language.directory}/tableau-de-bord-sombre.png`, fullPage: true });
        await context.close();
    });

    test(`capture de la saisie, aide ouverte, sur téléphone en thème sombre (${language.code})`, async ({
        browser,
        baseURL,
    }) => {
        const context = await browser.newContext({
            baseURL,
            locale: language.locale,
            viewport: { width: 390, height: 844 },
            colorScheme: 'dark',
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        // Distinct user: the same TOTP code only serves once.
        await logIn(page, seededUser(4, language.code));
        await page.goto('/saisie');
        await page.locator(`summary[aria-label="${language.entryHelp}"]`).click();
        await expect(page.getByRole('heading', { name: language.shortcuts })).toBeVisible();
        await page.screenshot({ path: `${language.directory}/saisie-aide-sombre.png` });
        await context.close();
    });
}
