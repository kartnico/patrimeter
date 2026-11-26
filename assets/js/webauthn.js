/*
 * Passkeys and security keys: dialogue with the browser's authenticator. The forms stay classic POSTs; this script
 * only gets the options and fills in the hidden fields. It reveals the buttons concerned only if the browser supports
 * WebAuthn.
 */
(() => {
    'use strict';

    const toBuffer = (value) => {
        const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(normalized), (c) => c.charCodeAt(0)).buffer;
    };
    const toBase64Url = (buffer) =>
        btoa(String.fromCharCode(...new Uint8Array(buffer)))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/={1,2}$/, '');

    const descriptors = (list) => (list || []).map((d) => ({ ...d, id: toBuffer(d.id) }));

    const fetchOptions = async (form) => {
        const body = new URLSearchParams(new FormData(form));
        const response = await fetch(form.dataset.webauthnOptions, {
            method: 'POST',
            body,
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error('options');
        }
        return response.json();
    };

    const prfExtension = (prf) => ({ prf: { eval: { first: toBuffer(prf) } } });
    const prfOutput = (credential) => {
        const first = credential.getClientExtensionResults().prf?.results?.first;
        return first ? toBase64Url(first) : '';
    };

    const assertionJson = (credential) =>
        JSON.stringify({
            id: credential.id,
            rawId: toBase64Url(credential.rawId),
            type: credential.type,
            clientExtensionResults: {},
            response: {
                clientDataJSON: toBase64Url(credential.response.clientDataJSON),
                authenticatorData: toBase64Url(credential.response.authenticatorData),
                signature: toBase64Url(credential.response.signature),
                userHandle: credential.response.userHandle ? toBase64Url(credential.response.userHandle) : null,
            },
        });

    const authenticate = async (form) => {
        const { options, prf } = await fetchOptions(form);
        const credential = await navigator.credentials.get({
            publicKey: {
                ...options,
                challenge: toBuffer(options.challenge),
                allowCredentials: descriptors(options.allowCredentials),
                extensions: form.dataset.webauthnPrf !== undefined ? prfExtension(prf) : undefined,
            },
        });
        form.elements[form.dataset.webauthnField].value = assertionJson(credential);
        if (form.elements.prf_output) {
            form.elements.prf_output.value = prfOutput(credential);
        }
    };

    const register = async (form) => {
        const { options, prf } = await fetchOptions(form);
        const credential = await navigator.credentials.create({
            publicKey: {
                ...options,
                challenge: toBuffer(options.challenge),
                user: { ...options.user, id: toBuffer(options.user.id) },
                excludeCredentials: descriptors(options.excludeCredentials),
                extensions: prfExtension(prf),
            },
        });
        form.elements.credential.value = JSON.stringify({
            id: credential.id,
            rawId: toBase64Url(credential.rawId),
            type: credential.type,
            clientExtensionResults: {},
            response: {
                clientDataJSON: toBase64Url(credential.response.clientDataJSON),
                attestationObject: toBase64Url(credential.response.attestationObject),
                transports: credential.response.getTransports ? credential.response.getTransports() : [],
            },
        });
        let output = prfOutput(credential);
        if (output === '' && credential.getClientExtensionResults().prf?.enabled) {
            // Some authenticators only give the PRF output at authentication.
            const assertion = await navigator.credentials.get({
                publicKey: {
                    challenge: crypto.getRandomValues(new Uint8Array(32)),
                    rpId: options.rp.id,
                    allowCredentials: [{ type: 'public-key', id: credential.rawId }],
                    extensions: prfExtension(prf),
                },
            });
            output = prfOutput(assertion);
        }
        form.elements.prf_output.value = output;
    };

    if (!window.PublicKeyCredential) {
        return;
    }
    document.querySelectorAll('[data-webauthn]').forEach((form) => {
        form.hidden = false;
        const message = form.querySelector('[data-webauthn-error]');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (message) {
                message.hidden = true;
            }
            try {
                await (form.dataset.webauthn === 'register' ? register(form) : authenticate(form));
                form.submit();
            } catch (error) {
                if (message) {
                    message.hidden = false;
                }
            }
        });
    });
})();
