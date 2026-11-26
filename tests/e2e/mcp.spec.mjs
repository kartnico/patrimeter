import { expect, freshTotp, seededUser, test, TOTP_SECRET, PASSWORD } from './support/helpers.mjs';

const REDIRECT = 'http://127.0.0.1:9/retour';
const VERIFIER = 'verificateur-pkce-du-parcours-de-bout-en-bout-mcp';

const challenge = async (verifier) =>
    Buffer.from(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))).toString('base64url');

test('client MCP : découverte, connexion et consentement dans le navigateur, puis outils', async ({
    page,
    baseURL,
    request,
}) => {
    const email = seededUser(9);

    const denied = await request.post('/mcp', { data: {} });
    expect(denied.status()).toBe(401);
    const metadataUrl = denied.headers()['www-authenticate'].match(/resource_metadata="([^"]+)"/)[1];
    const resource = await (await request.get(metadataUrl)).json();
    const server = await (
        await request.get(`${resource.authorization_servers[0]}/.well-known/oauth-authorization-server`)
    ).json();

    const client = await (
        await request.post(server.registration_endpoint, {
            data: { client_name: 'Client de bout en bout', redirect_uris: [REDIRECT] },
        })
    ).json();

    // The user's browser: usual sign-in, back to the consent, then to the client.
    await page.route('http://127.0.0.1:9/**', (route) => route.fulfill({ contentType: 'text/plain', body: 'client' }));
    const query = new URLSearchParams({
        response_type: 'code',
        client_id: client.client_id,
        redirect_uri: REDIRECT,
        code_challenge: await challenge(VERIFIER),
        code_challenge_method: 'S256',
        state: 'etat-du-client',
        scope: 'patrimeter.read',
        resource: resource.resource,
    });
    await page.goto(`${server.authorization_endpoint}?${query}`);
    await expect(page).toHaveURL(/\/connexion$/);
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    await page.locator('input#code').fill(await freshTotp(TOTP_SECRET, email));
    await page.getByRole('button', { name: 'Valider', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Autoriser une application' })).toBeVisible();
    await expect(page.getByText('Client de bout en bout')).toBeVisible();
    await page.getByRole('button', { name: 'Autoriser', exact: true }).click();
    await page.waitForURL(/127\.0\.0\.1:9\/retour/);
    const back = new URL(page.url());
    expect(back.searchParams.get('state')).toBe('etat-du-client');
    expect(back.searchParams.get('iss')).toBe(server.issuer);

    const tokens = await (
        await request.post(server.token_endpoint, {
            form: {
                grant_type: 'authorization_code',
                client_id: client.client_id,
                code: back.searchParams.get('code'),
                redirect_uri: REDIRECT,
                code_verifier: VERIFIER,
                resource: resource.resource,
            },
        })
    ).json();
    expect(tokens.access_token).toMatch(/^patrimeter_[0-9a-f]{24}_/);

    const version = '2026-07-28';
    const call = await request.post('/mcp', {
        headers: {
            Authorization: `Bearer ${tokens.access_token}`,
            Accept: 'application/json, text/event-stream',
            'MCP-Protocol-Version': version,
            'Mcp-Method': 'tools/call',
            'Mcp-Name': 'list_accounts',
        },
        data: {
            jsonrpc: '2.0',
            id: 1,
            method: 'tools/call',
            params: {
                name: 'list_accounts',
                arguments: {},
                _meta: {
                    'io.modelcontextprotocol/protocolVersion': version,
                    'io.modelcontextprotocol/clientCapabilities': {},
                },
            },
        },
    });
    expect(call.status()).toBe(200);
    expect(await call.text()).toContain('Livret A');
    expect(baseURL).toContain('localhost');
});
