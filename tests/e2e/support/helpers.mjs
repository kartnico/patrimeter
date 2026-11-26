import { createHmac } from 'node:crypto';
import { expect, test as base } from '@playwright/test';

// Same values as tests/Support/TestUsers.php.
export const PASSWORD = 'mot de passe de test';
export const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
// Users of tests/e2e/seed.php: accounts with French names, or English ones (language "en", English screenshots).
export const seededUser = (number, language = 'fr') =>
    `e2e-${language === 'en' ? 'en-' : ''}${String(number).padStart(2, '0')}@example.test`;

// Recovery key of the test users (TestUsers::RECOVERY_KEY), in the displayed format.
const base32Encode = (bytes) => {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...bytes].map((byte) => byte.toString(2).padStart(8, '0')).join('');
    return (bits.match(/.{1,5}/g) ?? []).map((chunk) => alphabet[parseInt(chunk.padEnd(5, '0'), 2)]).join('');
};
export const RECOVERY_KEY = base32Encode(Buffer.from('cle de recuperation de test 1234'))
    .match(/.{1,4}/g)
    .join('-');

const MAILPIT = process.env.MAILPIT_API ?? 'http://127.0.0.1:8025';
const STEP_MS = 30_000;

const base32Decode = (secret) => {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.replace(/[\s=]/g, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    return Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
};

const totpAt = (secret, counter) => {
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(BigInt(counter));
    const digest = createHmac('sha1', base32Decode(secret)).update(message).digest();
    const offset = digest[digest.length - 1] & 0xf;
    return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
};

const usedSteps = new Map();

// A code only serves once: waits for the next window if needed, and avoids the end of a window.
export const freshTotp = async (secret, account) => {
    for (;;) {
        const now = Date.now();
        const counter = Math.floor(now / STEP_MS);
        const remaining = STEP_MS - (now % STEP_MS);
        if (counter > (usedSteps.get(account) ?? 0) && remaining > 3_000) {
            usedSteps.set(account, counter);
            return totpAt(secret, counter);
        }
        await new Promise((resolve) => {
            setTimeout(resolve, remaining + 100);
        });
    }
};

export const logIn = async (page, email, password = PASSWORD, secret = TOTP_SECRET) => {
    await page.goto('/connexion');
    const labels = await signInLabels(page);
    await page.getByLabel(labels.email).fill(email);
    await page.getByLabel(labels.password, { exact: true }).fill(password);
    await page.getByRole('button', { name: labels.submit, exact: true }).click();
    await submitTotp(page, secret, email);
};

// Sign-in labels in each interface language: the page gives its own (<html lang>).
const SIGN_IN_LABELS = {
    fr: { email: 'Adresse e-mail', password: 'Mot de passe', submit: 'Se connecter', confirm: 'Valider' },
    en: { email: 'Email address', password: 'Password', submit: 'Sign in', confirm: 'Confirm' },
};
const signInLabels = async (page) =>
    SIGN_IN_LABELS[await page.locator('html').getAttribute('lang')] ?? SIGN_IN_LABELS.fr;

export const submitTotp = async (page, secret, account) => {
    const labels = await signInLabels(page);
    await page.locator('input#code').fill(await freshTotp(secret, account));
    await page.getByRole('button', { name: labels.confirm, exact: true }).click();
};

export const logOut = async (page) => {
    // Sign out: last entry of the account menu.
    await page.locator('details.account-menu > summary').click();
    await page.getByRole('button', { name: 'Déconnexion' }).click();
    await expect(page).toHaveURL(/\/connexion/);
};

// Last email received by an address (the sendings happen at the end of the request).
export const lastMailTo = async (address, subjectPart) => {
    const deadline = Date.now() + 15_000;
    while (Date.now() < deadline) {
        const query = encodeURIComponent(`to:"${address}" subject:"${subjectPart}"`);
        const search = await (await fetch(`${MAILPIT}/api/v1/search?query=${query}&limit=1`)).json();
        if (search.messages?.length > 0) {
            return (await fetch(`${MAILPIT}/api/v1/message/${search.messages[0].ID}`)).json();
        }
        await new Promise((resolve) => {
            setTimeout(resolve, 250);
        });
    }
    throw new Error(`Aucun e-mail « ${subjectPart} » pour ${address}`);
};

export const firstLink = (mail) => {
    const match = mail.Text.match(/https?:\/\/\S+/);
    if (!match) {
        throw new Error('Aucun lien dans le message');
    }
    return match[0];
};

export const addVirtualAuthenticator = async (context, page, options = {}) => {
    const cdp = await context.newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
            hasPrf: true,
            ...options,
        },
    });
    return { cdp, authenticatorId };
};

// Confirms the displayed recovery key: its last two groups.
export const confirmRecoveryKey = async (page) => {
    await expect(page).toHaveURL(/\/securite\/cle-de-recuperation/);
    const groups = (await page.locator('.secret-key code').innerText()).trim().split(/[\s-]+/);
    await page.getByLabel('Pour confirmer').fill(groups.slice(-2).join(' '));
    await page.getByRole('button', { name: "J'ai noté ma clé" }).click();
    await expect(page).toHaveURL(/\/$/);
};

export const parseAmount = (text) =>
    Number(
        text
            .replace(/[^\d,\u2212-]/g, '')
            .replace(',', '.')
            .replace('\u2212', '-'),
    );

// Any flow fails on a CSP violation or a JavaScript error.
export const test = base.extend({
    page: async ({ page }, use) => {
        const problems = [];
        page.on('pageerror', (error) => problems.push(`JavaScript : ${error.message}`));
        page.on('console', (message) => {
            if (message.type() === 'error' && /Content Security Policy/i.test(message.text())) {
                problems.push(`CSP : ${message.text()}`);
            }
        });
        await use(page);
        expect(problems).toEqual([]);
    },
});

export { expect };
