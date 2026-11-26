// Property-based tests (fast-check) of the amounts of the monthly entry in assets/js/app.js: whatever the amount
// typed, the field keeps exactly that amount once formatted, formatting it again changes nothing, and the level total
// shows it.
import fc from 'fast-check';
import { beforeAll, expect, test } from 'vitest';

beforeAll(async () => {
    Element.prototype.scrollIntoView = () => {};
    await import('../../assets/js/app.js');
});

// Amount of a French field: thin and non-breaking spaces between thousands, comma for the cents, "−" for a minus.
const cents = (text) => {
    const plain = text.replace(/[\s€]/g, '').replace('\u2212', '-').replace(',', '.');
    return Math.round(Number(plain) * 100);
};

function entryWith(initial) {
    document.documentElement.lang = 'fr';
    document.body.innerHTML = `<form data-entry-form data-diff-label="%amount% vs oct." data-invalid-label="Montant illisible">
        <section data-entry-level><table><tbody>
            <tr class="entry-row is-carried" data-entry-row data-share="1000000" data-liability="0" data-previous="">
                <th></th>
                <td><input id="amount" name="amounts[1]" value="${initial}" data-initial="${initial}"><span data-entry-diff></span></td>
            </tr>
        </tbody><tfoot><tr><td data-level-total></td></tr></tfoot></table></section>
        <strong data-entry-net></strong><button type="submit">Valider</button>
    </form>`;
    document.dispatchEvent(new Event('DOMContentLoaded'));
    return document.getElementById('amount');
}

function type(field, text) {
    field.value = text;
    field.dispatchEvent(new Event('input'));
    field.dispatchEvent(new Event('blur'));
}

test('any amount typed is kept exactly, and formatting it again changes nothing', () => {
    fc.assert(
        fc.property(fc.integer({ min: -9_999_999_999, max: 9_999_999_999 }), (amount) => {
            const field = entryWith('0,00');
            const typed = `${amount < 0 ? '-' : ''}${Math.trunc(Math.abs(amount) / 100)},${String(Math.abs(amount) % 100).padStart(2, '0')}`;

            type(field, typed);
            const formatted = field.value;
            expect(cents(formatted)).toBe(amount);

            type(field, formatted);
            expect(field.value).toBe(formatted);
            expect(cents(document.querySelector('[data-level-total]').textContent)).toBe(amount);
        }),
        { numRuns: 200 },
    );
});
