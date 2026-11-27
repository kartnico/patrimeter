// Behaviours of assets/js/app.js in a simulated DOM: monthly entry (formatting, totals, gap, keyboard) and typing
// lists. app.js subscribes to DOMContentLoaded: each test sets its HTML, then fires the event.
import { beforeAll, describe, expect, test } from 'vitest';

const NBSP = ' ';
const NNBSP = ' ';
const MINUS = '−';

beforeAll(async () => {
    // jsdom does not implement scrolling.
    Element.prototype.scrollIntoView = () => {};
    await import('../../assets/js/app.js');
});

function start(html, lang = 'fr') {
    document.documentElement.lang = lang;
    document.body.innerHTML = html;
    document.dispatchEvent(new Event('DOMContentLoaded'));
}

function row({ id, initial, share = 1000000, liability = false, previous = '', entered = false, advance = false }) {
    return `<tr class="entry-row ${entered ? 'is-entered' : 'is-carried'}" data-entry-row data-share="${share}"
            data-liability="${liability ? 1 : 0}" data-previous="${previous}">
        <th>${advance ? `<input type="hidden" name="advance[]" value="${id}">` : ''}</th>
        <td><input id="${id}" name="amounts[${id}]" value="${initial}" data-initial="${initial}"><span class="entry-diff" data-entry-diff></span></td>
    </tr>`;
}

function entryPage(rows, lang = 'fr') {
    start(
        `<progress data-entry-progress value="0" max="3"></progress><strong data-entry-count>0</strong>
        <form data-entry-form data-diff-label="%amount% vs oct." data-invalid-label="Montant illisible">
            <section data-entry-level><table><tbody>${rows}</tbody>
                <tfoot><tr><td data-level-total></td></tr></tfoot></table></section>
            <strong data-entry-net></strong>
            <button type="submit">Valider</button>
        </form>`,
        lang,
    );
}

const type = (input, value) => {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
};
const key = (input, name, options = {}) =>
    input.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true, ...options }));
const total = () => document.querySelector('[data-level-total]').textContent;
const net = () => document.querySelector('[data-entry-net]').textContent;

describe('saisie du mois', () => {
    test('met les montants en forme au chargement, le « − » collé au capital restant dû', () => {
        entryPage(
            row({ id: 'livret', initial: '20000,00' }) +
                row({ id: 'pret', initial: `${MINUS}12000,00`, liability: true }),
        );

        expect(document.getElementById('livret').value).toBe(`20${NBSP}000,00`);
        expect(document.getElementById('pret').value).toBe(`${MINUS}12${NBSP}000,00`);
        // €20,000 of assets minus €12,000 of loan.
        expect(total()).toBe(`8${NNBSP}000,00${NBSP}€`);
        expect(net()).toBe(`8${NNBSP}000,00${NBSP}€`);
    });

    test('recalcule les totaux à la frappe, avec la part du bien', () => {
        entryPage(row({ id: 'maison', initial: '300000,00', share: 500000 }) + row({ id: 'cc', initial: '1500,00' }));
        expect(total()).toBe(`151${NNBSP}500,00${NBSP}€`);

        type(document.getElementById('maison'), '320 000');

        expect(total()).toBe(`161${NNBSP}500,00${NBSP}€`);
    });

    test('un crédit saisi sans signe reste un capital dû ; sa baisse est un écart positif', () => {
        entryPage(row({ id: 'pret', initial: `${MINUS}12000,00`, liability: true, previous: '1200000' }));
        const input = document.getElementById('pret');

        type(input, '11500');

        expect(total()).toBe(`${MINUS}11${NNBSP}500,00${NBSP}€`);
        expect(input.closest('tr').querySelector('[data-entry-diff]').textContent).toBe(`+500,00${NBSP}€ vs oct.`);
        input.dispatchEvent(new Event('blur'));
        expect(input.value).toBe(`${MINUS}11${NBSP}500,00`);
    });

    test('un montant illisible est signalé et compte pour zéro', () => {
        entryPage(row({ id: 'cc', initial: '1500,00' }));
        const input = document.getElementById('cc');

        type(input, 'douze');

        expect(input.closest('tr').querySelector('[data-entry-diff]').textContent).toBe('Montant illisible');
        expect(total()).toBe(`0,00${NBSP}€`);
    });

    test('Entrée confirme et passe au champ suivant ; Maj+Entrée revient ; Échap rétablit', () => {
        entryPage(row({ id: 'a', initial: '100,00' }) + row({ id: 'b', initial: '200,00' }));
        const [a, b] = [document.getElementById('a'), document.getElementById('b')];
        a.focus();

        const submitted = !key(a, 'Enter');

        expect(submitted).toBe(true); // event cancelled: the form is not sent
        expect(document.activeElement).toBe(b);
        expect(a.closest('tr').classList.contains('is-touched')).toBe(true);
        expect(document.querySelector('[data-entry-count]').textContent).toBe('1');
        expect(document.querySelector('[data-entry-progress]').value).toBe(1);

        key(b, 'Enter', { shiftKey: true });
        expect(document.activeElement).toBe(a);

        type(a, '999');
        key(a, 'Escape');
        expect(a.value).toBe('100,00');
        expect(a.closest('tr').classList.contains('is-touched')).toBe(false);
    });

    test('en anglais : « 1,500.00 », « €1,500.00 »', () => {
        entryPage(row({ id: 'cc', initial: '1500.00' }), 'en');

        expect(document.getElementById('cc').value).toBe('1,500.00');
        expect(total()).toBe('€1,500.00');
    });

    test('les milliers suivent la langue : « 1,000 » en anglais, « 1.000 » en français', () => {
        // Bug: in English, "1,000" (a thousand) was unreadable, read as an amount with three decimals.
        entryPage(row({ id: 'cc', initial: '0.00' }), 'en');
        type(document.getElementById('cc'), '1,000');
        expect(total()).toBe('€1,000.00');
        type(document.getElementById('cc'), '1.000');
        expect(total()).toBe('€0.00'); // three decimals: unreadable, counts as zero

        entryPage(row({ id: 'cc', initial: '0,00' }));
        type(document.getElementById('cc'), '1.000');
        expect(total()).toBe(`1${NNBSP}000,00${NBSP}€`);
        type(document.getElementById('cc'), '1,000');
        expect(total()).toBe(`0,00${NBSP}€`);
    });
});

test('un compte repris d’un mois suivant (champ caché advance[] avant le montant) est mis en forme et compté', () => {
    // Regression of 11/11/2026: the row took the hidden field for the amount, and the script stopped.
    entryPage(row({ id: 'repris', initial: '2876,12', advance: true }) + row({ id: 'cc', initial: '100,00' }));

    expect(document.getElementById('repris').value).toBe(`2${NBSP}876,12`);
    expect(total()).toBe(`2${NNBSP}976,12${NBSP}€`);
});

describe('liste à saisie', () => {
    const page = () =>
        start(`<label for="type_id">Type</label>
            <select id="type_id" data-combobox data-combobox-show-all="Afficher toute la liste" data-status-none="Aucun type" data-status-one="1 type" data-status-many="%count% types">
                <option value="">— Choisir —</option>
                <optgroup label="Épargne de précaution"><option value="1">Livret A</option><option value="2">LDDS</option>
                    <option value="3">Livret bancaire</option></optgroup>
                <optgroup label="Actions"><option value="4">Épargne salariale</option></optgroup>
            </select>`);
    const visibleOptions = () =>
        Array.from(document.querySelectorAll('[role="option"]')).filter((item) => !item.hidden && item.isConnected);

    test('remplace la liste par un champ qui filtre sans casse ni accents', () => {
        page();
        const input = document.querySelector('input[role="combobox"]');
        expect(document.getElementById('type_id').hidden).toBe(true);
        expect(document.querySelector('label').htmlFor).toBe(input.id);

        type(input, 'LIVRET');
        expect(visibleOptions().map((item) => item.textContent)).toEqual(['Livret A', 'Livret bancaire']);

        type(input, 'epargne');
        expect(visibleOptions().map((item) => item.textContent)).toEqual(['Épargne salariale']);
    });

    test('a click shows the whole list, even with a choice made, and the arrow button opens or closes it', () => {
        page();
        const input = document.querySelector('input[role="combobox"]');
        type(input, 'ldds');
        key(input, 'ArrowDown');
        key(input, 'Enter');
        expect(input.value).toBe('LDDS');

        input.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(visibleOptions().map((item) => item.textContent)).toEqual([
            'Livret A',
            'LDDS',
            'Livret bancaire',
            'Épargne salariale',
        ]);
        expect(input.getAttribute('aria-expanded')).toBe('true');

        const toggle = document.querySelector('.combobox-toggle');
        expect(toggle.getAttribute('aria-label')).toBe('Afficher toute la liste');
        expect(toggle.tabIndex).toBe(-1);
        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(document.querySelector('[role="listbox"]').hidden).toBe(true);
        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(visibleOptions()).toHaveLength(4);
    });

    test('Entrée choisit l’option active et met à jour le <select>', () => {
        page();
        const input = document.querySelector('input[role="combobox"]');
        const select = document.getElementById('type_id');
        let changed = 0;
        select.addEventListener('change', () => changed++);

        type(input, 'ldds');
        key(input, 'ArrowDown');
        key(input, 'Enter');

        expect(select.value).toBe('2');
        expect(input.value).toBe('LDDS');
        expect(changed).toBe(1);
    });
});

describe('new password', () => {
    const page = (emailField = true) =>
        start(`<input id="email" value="anne@example.test">
            <input type="password" id="password" aria-describedby="password-hint">
            <input type="password" id="password_confirmation">
            <div class="password-meter" id="password-meter" data-password-meter data-for="password" data-min-length="12"
                 data-confirm="password_confirmation" ${emailField ? 'data-email-input="email"' : 'data-email="anne@example.test"'}
                 data-level-0="Très faible" data-level-1="Faible" data-level-2="Moyenne" data-level-3="Forte" data-level-4="Très forte"
                 data-met="respectée" data-unmet="non respectée">
                <div data-password-strength hidden><span data-strength-fill data-level="0"></span><strong data-strength-label></strong></div>
                <ul>
                    <li data-rule="length"><span data-rule-state></span></li>
                    <li data-rule="email"><span data-rule-state></span></li>
                    <li data-rule="strength"><span data-rule-state></span></li>
                    <li data-rule="match"><span data-rule-state></span></li>
                </ul>
            </div>`);
    const fill = (id, value) => {
        const field = document.getElementById(id);
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
    };
    const level = () => document.querySelector('[data-strength-fill]').dataset.level;
    const met = (rule) => document.querySelector(`[data-rule="${rule}"]`).classList.contains('is-met');

    // Levels given by Symfony's PasswordStrengthValidator::estimateStrength for the same passwords: the meter shows
    // what the server accepts (at least 2, "Moyenne").
    test.each([
        ['', '0'],
        ['motdepasse12', '0'],
        ['aaaaaaaaaaaaaaaaaaaaaaaa', '0'],
        ['Az9!Az9!Az9!', '0'],
        ['été', '0'],
        ['Tr0ub4dor&3', '1'],
        ['abcdefghijklmnop', '1'],
        ['Patrimeter2026', '1'],
        ['Patrimeter2026!', '2'],
        ['Xq7#pL2@vN9!kR4$', '3'],
        ['correct horse battery staple', '4'],
        ['Mon chat Félix aime les sardines', '4'],
    ])('estimates "%s" like the server: level %s', (password, expected) => {
        page();
        fill('password', password);
        expect(level()).toBe(expected);
    });

    test('shows the strength and marks each requirement while typing', () => {
        page();
        const label = document.querySelector('[data-strength-label]');
        expect(document.querySelector('[data-password-strength]').hidden).toBe(false);
        expect(label.textContent).toBe('Très faible');
        expect(document.getElementById('password').getAttribute('aria-describedby')).toBe(
            'password-hint password-meter',
        );

        fill('password', 'Patrimeter2026');
        expect(label.textContent).toBe('Faible');
        expect([met('length'), met('email'), met('strength'), met('match')]).toEqual([true, true, false, false]);
        expect(document.querySelector('[data-rule="strength"] [data-rule-state]').textContent).toBe(' (non respectée)');

        fill('password', 'ma phrase anne@example.test');
        expect(met('email')).toBe(false);
        fill('email', 'autre@example.test');
        expect(met('email')).toBe(true); // the address typed in the form counts

        fill('password', 'correct horse battery staple');
        fill('password_confirmation', 'correct horse battery staple');
        expect(label.textContent).toBe('Très forte');
        expect([met('length'), met('email'), met('strength'), met('match')]).toEqual([true, true, true, true]);
        expect(document.querySelector('[data-rule="match"] [data-rule-state]').textContent).toBe(' (respectée)');
    });

    test('checks the known address of the account, without case', () => {
        page(false);
        fill('password', 'xx ANNE@EXAMPLE.TEST xx');
        expect(met('email')).toBe(false);
    });
});

describe('visite guidée de la démonstration', () => {
    test("met en évidence l'élément de l'étape et l'amène à l'écran", () => {
        const scrolled = [];
        Element.prototype.scrollIntoView = function (options) {
            scrolled.push([this.id, options.block]);
        };
        start(`<section class="summary" id="synthese"></section>
            <aside class="tour-card" data-tour-target=".summary"></aside>`);

        expect(document.getElementById('synthese').classList.contains('tour-highlight')).toBe(true);
        expect(scrolled).toEqual([['synthese', 'start']]);
    });

    test("sans élément à montrer, rien n'est mis en évidence", () => {
        start(`<section class="summary"></section><aside class="tour-card"></aside>`);

        expect(document.querySelector('.tour-highlight')).toBeNull();
    });
});

describe('boutons de copie des adresses (onglet Agents IA)', () => {
    const page = () =>
        start(`<dl><dd><code id="mcp-url"> https://patrimeter.example/mcp </code>
            <button type="button" data-copy="mcp-url" data-copied="Copié" hidden>Copier</button></dd></dl>
            <p role="status" data-copy-status data-copied-message="Adresse copiée."></p>`);

    test("le bouton apparaît et copie l'adresse, sans espaces autour", async () => {
        const copied = [];
        Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: async (text) => copied.push(text) },
            configurable: true,
        });
        page();
        const button = document.querySelector('[data-copy]');
        expect(button.hidden).toBe(false);

        button.click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(copied).toEqual(['https://patrimeter.example/mcp']);
        expect(button.textContent).toBe('Copié');
        expect(document.querySelector('[data-copy-status]').textContent).toBe('Adresse copiée.');
    });

    test('une liste se copie ligne par ligne (codes de secours)', async () => {
        const copied = [];
        Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: async (text) => copied.push(text) },
            configurable: true,
        });
        start(`<ul id="backup-codes">
                <li><code>AAAAA-BBBBB</code></li>
                <li><code>CCCCC-DDDDD</code></li>
            </ul>
            <button type="button" data-copy="backup-codes" data-copied="Copié" hidden>Copier les codes</button>`);

        document.querySelector('[data-copy]').click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(copied).toEqual(['AAAAA-BBBBB\nCCCCC-DDDDD']);
    });

    test('sans presse-papiers accessible, le bouton reste masqué', () => {
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        page();

        expect(document.querySelector('[data-copy]').hidden).toBe(true);
    });

    test('the print button shows itself and opens the print dialog', () => {
        const printed = [];
        const print = window.print;
        window.print = () => printed.push(true);
        try {
            start(`<button type="button" data-print hidden>Imprimer</button>`);
            const button = document.querySelector('[data-print]');
            expect(button.hidden).toBe(false);
            button.click();
            expect(printed).toEqual([true]);
        } finally {
            window.print = print;
        }
    });
});

describe('pyramide du patrimoine', () => {
    const page = () =>
        start(`<div data-level-highlight>
            <svg><g class="pyr-tier pyr-actions"><polygon/></g><g class="pyr-tier pyr-exotique is-empty"><polygon/></g></svg>
            <details class="level-detail" data-level="actions"><summary>Actions</summary></details>
            <details class="level-detail" data-level="exotique"><summary>Exotique</summary></details>
            <table><tr data-level="actions"><th>Actions</th></tr></table>
        </div><p id="ailleurs">Ailleurs</p>`);
    const highlighted = () =>
        Array.from(document.querySelectorAll('.is-level-highlighted'), (element) => element.tagName.toLowerCase());
    const over = (element) => element.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));

    test('survoler un niveau surligne ses lignes dans les autres cadres, et réciproquement', () => {
        page();
        over(document.querySelector('g[data-level="actions"] polygon'));

        expect(highlighted()).toEqual(['g', 'details', 'tr']);
        expect(document.querySelector('[data-level-highlight]').classList.contains('has-level-highlight')).toBe(true);

        over(document.querySelector('details[data-level="exotique"] summary'));
        expect(highlighted()).toEqual(['g', 'details']);
        expect(document.querySelector('.is-level-highlighted').dataset.level).toBe('exotique');
    });

    test('un clic garde le niveau surligné, un clic ailleurs ou Échap le relâche', () => {
        page();
        const tier = document.querySelector('g[data-level="actions"]');
        tier.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        document.querySelector('[data-level-highlight]').dispatchEvent(new MouseEvent('mouseleave'));
        expect(highlighted()).toEqual(['g', 'details', 'tr']);

        document.getElementById('ailleurs').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(highlighted()).toEqual([]);

        tier.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(highlighted()).toEqual([]);
    });
});

describe('foyer fiscal', () => {
    test('les nombres d’adultes et d’enfants choisis n’affichent que leurs lignes', () => {
        const rows = (kind, count) =>
            Array.from(
                { length: count },
                (_, index) =>
                    `<div class="form-row" data-household-row="${kind}" data-household-index="${index + 1}"></div>`,
            ).join('');
        start(`<form>
            <select data-household-count="adult"><option value="1" selected>1</option><option value="2">2</option></select>
            <select data-household-count="child"><option value="0">0</option><option value="2" selected>2</option><option value="3">3</option></select>
            ${rows('adult', 2)}
            <fieldset data-household-group="child">${rows('child', 12)}</fieldset>
        </form>`);
        const visible = (kind) =>
            Array.from(document.querySelectorAll(`[data-household-row="${kind}"]`)).filter((row) => !row.hidden).length;

        expect(visible('adult')).toBe(1);
        expect(visible('child')).toBe(2);

        const [adults, children] = document.querySelectorAll('select');
        adults.value = '2';
        adults.dispatchEvent(new Event('change'));
        children.value = '0';
        children.dispatchEvent(new Event('change'));

        expect(visible('adult')).toBe(2);
        expect(document.querySelector('[data-household-group="child"]').hidden).toBe(true);
    });
});

describe('month and year fields shown as the month picker', () => {
    const field = (mode, value) =>
        `<form><div class="month-field" data-month-field ${mode === 'year' ? 'data-mode="year" data-label-clear="Aucune année"' : ''}
            data-today="2026-11" data-short-names="janv.|févr.|mars|avr.|mai|juin|juil.|août|sept.|oct.|nov.|déc."
            data-long-names="janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre"
            data-label-open="Choisir dans un calendrier" data-label-empty="Choisir" data-label-previous="Avant" data-label-next="Après">
            <input id="value" name="value" value="${value}" required>
        </div></form>`;

    test('a month shows as "Oct. 2026", and a click in the calendar fills the field sent', () => {
        start(field('month', '2026-10'));
        const input = document.getElementById('value');
        const toggle = document.querySelector('.month-field-toggle');
        expect(input.hidden).toBe(true);
        expect(input.required).toBe(false);
        expect(toggle.textContent).toContain('Oct. 2026');

        toggle.click();
        document.querySelector('.mp-cell[aria-label="mars 2026"]').click();
        expect(input.value).toBe('2026-03');
        expect(toggle.textContent).toContain('Mars 2026');
    });

    test('the calendar button keeps the field label when the input is hidden', () => {
        start(`<label for="value">Mois cible</label>${field('month', '2026-10')}`);
        const toggle = document.querySelector('.month-field-toggle');
        expect(document.querySelector('label').control).toBe(toggle);
        const name = toggle
            .getAttribute('aria-labelledby')
            .split(' ')
            .map((id) => document.getElementById(id).textContent);
        expect(name).toEqual(['Oct. 2026', 'Mois cible', ' — Choisir dans un calendrier']);
    });

    test('a year shows as such, picks from 12 years, and can be emptied', () => {
        start(field('year', '1985'));
        const input = document.getElementById('value');
        const toggle = document.querySelector('.month-field-toggle');
        expect(toggle.textContent).toContain('1985');

        toggle.click();
        const cells = Array.from(document.querySelectorAll('.mp-cell'), (cell) => cell.textContent);
        expect(cells).toHaveLength(12);
        expect(cells).toContain('1985');
        document.querySelector('.mp-cell[aria-label="1987"]').click();
        expect(input.value).toBe('1987');

        toggle.click();
        document.querySelector('.link-button').click();
        expect(input.value).toBe('');
        expect(toggle.textContent).toContain('Choisir');
    });
});
