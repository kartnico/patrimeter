// Load: 20 simultaneous users, each with 10 years of history (ci/start-app.sh, SEED_*).
// Each user signs in once (password and TOTP), then goes through the pages and enters amounts.
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';
import { parseHTML } from 'k6/html';
import http from 'k6/http';

const BASE = __ENV.BASE_URL || 'http://localhost:8765';
// Same values as tests/Support/TestUsers.php.
const PASSWORD = 'mot de passe de test';
const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

export const options = {
    noCookiesReset: true,
    scenarios: {
        users: {
            executor: 'ramping-vus',
            startVUs: 0,
            // One sign-in every 3 seconds: Argon2id must not saturate everything at the start.
            stages: [
                { duration: '60s', target: 20 },
                { duration: '90s', target: 20 },
            ],
            gracefulRampDown: '5s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
        'http_req_duration{kind:page}': ['p(95)<800'],
        'http_req_duration{kind:entry}': ['p(95)<1000'],
        'http_req_duration{kind:login}': ['p(95)<2000'],
    },
};

const base32Decode = (secret) => {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const bytes = new Uint8Array(Math.floor(bits.length / 8));
    for (let i = 0; i < bytes.length; ++i) {
        bytes[i] = parseInt(bits.substring(i * 8, i * 8 + 8), 2);
    }
    return bytes.buffer;
};

const totp = () => {
    const message = new Uint8Array(8);
    let counter = Math.floor(Date.now() / 30000);
    for (let i = 7; i >= 0; --i) {
        message[i] = counter % 256;
        counter = Math.floor(counter / 256);
    }
    const digest = crypto.hmac('sha1', base32Decode(TOTP_SECRET), message.buffer, 'hex');
    const offset = parseInt(digest.slice(-1), 16);
    const value = parseInt(digest.substring(offset * 2, offset * 2 + 8), 16) & 0x7fffffff;
    return String(value % 1000000).padStart(6, '0');
};

const csrf = (response) => parseHTML(response.body).find('input[name="_csrf"]').first().attr('value');

const logIn = (email) => {
    const page = http.get(`${BASE}/connexion`, { tags: { kind: 'page' } });
    const codePage = http.post(
        `${BASE}/connexion`,
        { _csrf: csrf(page), email, password: PASSWORD },
        { tags: { kind: 'login' } },
    );
    check(codePage, { 'mot de passe accepté': (r) => r.url.endsWith('/connexion/code') });
    const home = http.post(
        `${BASE}/connexion/code`,
        { _csrf: csrf(codePage), code: totp() },
        { tags: { kind: 'login' } },
    );
    check(home, { 'code accepté': (r) => r.status === 200 && r.url === `${BASE}/` });
};

let connected = false;

export default function () {
    if (!connected) {
        logIn(`e2e-${String(__VU).padStart(2, '0')}@example.test`);
        connected = true;
    }

    for (const path of ['/', '/comptes', '/budget', '/biens']) {
        const response = http.get(`${BASE}${path}`, { tags: { kind: 'page' } });
        check(response, { [`${path} affichée`]: (r) => r.status === 200 && r.url === `${BASE}${path}` });
        sleep(1);
    }

    const entry = http.get(`${BASE}/saisie`, { tags: { kind: 'page' } });
    check(entry, { 'saisie affichée': (r) => r.status === 200 });
    const form = parseHTML(entry.body).find('form.entry-form');
    const fields = {
        _csrf: form.find('input[name="_csrf"]').attr('value'),
        mois: form.find('input[name="mois"]').attr('value'),
    };
    form.find('input[name^="amounts["]').each((_, input) => {
        fields[input.getAttribute('name')] = '1000,00';
    });
    const saved = http.post(`${BASE}/saisie`, fields, { tags: { kind: 'entry' } });
    check(saved, { 'saisie enregistrée': (r) => r.status === 200 && r.url.includes('mois=') });
    sleep(1);
}
