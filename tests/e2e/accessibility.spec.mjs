// Accessibility (axe-core, WCAG 2.2 A and AA rules): public pages, application screens in light and in dark theme
// (contrasts). Any violation makes the test fail, with the rule and the elements concerned.
import AxeBuilder from '@axe-core/playwright';
import { expect, logIn, seededUser, test } from './support/helpers.mjs';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const PUBLIC_PAGES = ['/connexion', '/inscription', '/mot-de-passe-oublie'];
const APP_PAGES = [
    '/',
    '/rapport',
    '/rapport?montants=0',
    '/rapport?montants=0&etoiles=1',
    '/?periode=tout&vue=niveaux',
    '/saisie',
    '/comptes',
    '/comptes/nouveau',
    '/biens',
    '/biens/nouveau',
    '/objectifs',
    '/saisie/objectifs',
    '/saisie/objectifs/nouveau',
    '/budget',
    '/budget/saisie',
    '/budget/foyer',
    '/budget/carbure',
    '/securite',
    '/securite/connexion',
    '/securite/agents-ia',
    '/donnees',
    '/famille',
    '/defis',
    '/bienvenue/pyramide',
    '/bienvenue/express',
    '/bienvenue/questions',
];
// Demonstration family account: views of the family (choice of the perimeter, imported accounts, read-only budget).
const FAMILY_PAGES = ['/', '/budget', '/famille'];

async function audit(page, path) {
    await page.goto(path);
    await expect(page.locator('h1').first()).toBeVisible();
    // Whole page in the viewport: the sticky bars (monthly and budget entry) sit in their place instead of covering,
    // at the bottom of the screen, whichever field happens to be there; target sizes are measured without this
    // scroll artefact (keyboard focus stays above the bars: scroll-padding-bottom in app.css).
    const width = page.viewportSize()?.width ?? 1280;
    const height = await page.evaluate(() => document.documentElement.scrollHeight);
    await page.setViewportSize({ width, height: Math.min(Math.max(height, 720), 10000) });
    const { violations } = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    const summary = violations.map(
        (violation) =>
            `${violation.id} (${violation.impact}) : ${violation.help} — ` +
            violation.nodes
                .slice(0, 3)
                .map((node) => node.target.join(' '))
                .join(' | '),
    );
    expect.soft(summary, `violations d'accessibilité sur ${path}`).toEqual([]);
}

test('accessibilité : pages publiques', async ({ page }) => {
    for (const path of PUBLIC_PAGES) {
        await audit(page, path);
    }
});

for (const [theme, user] of [
    ['light', 14],
    ['dark', 15],
]) {
    test(`accessibilité : écrans de l'application, thème ${theme === 'light' ? 'clair' : 'sombre'}`, async ({
        browser,
        baseURL,
    }) => {
        const context = await browser.newContext({ baseURL, locale: 'fr-FR', colorScheme: theme });
        const page = await context.newPage();
        await logIn(page, seededUser(user));
        for (const path of APP_PAGES) {
            await audit(page, path);
        }
        await context.close();
    });
}

test('accessibilité : compte famille', async ({ page }) => {
    await logIn(page, seededUser('famille'));
    for (const path of FAMILY_PAGES) {
        await audit(page, path);
    }
});
