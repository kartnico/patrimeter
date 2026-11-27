/* Patrimeter — shared behaviours (progressive enhancement, no dependency). */
// Everything inside a function: nothing is added to window (classic script, served by AssetMapper without importmap).
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        // Property form: extra loan line (without JavaScript, three lines are enough).
        const addLoan = document.querySelector('[data-loan-add]');
        const loanRows = document.querySelector('[data-loan-rows]');
        if (addLoan && loanRows) {
            addLoan.hidden = false;
            addLoan.addEventListener('click', () => {
                const index = loanRows.querySelectorAll('[data-loan-row]').length;
                const row = loanRows.querySelector('[data-loan-row]').cloneNode(true);
                row.querySelector('[data-loan-number]').textContent = String(index + 1);
                row.querySelector('.field-error')?.remove();
                row.querySelectorAll('input, select, label').forEach((element) => {
                    ['id', 'for'].forEach((attribute) => {
                        if (element.hasAttribute(attribute)) {
                            element.setAttribute(
                                attribute,
                                element.getAttribute(attribute).replace(/(?<!\d)\d+$/, String(index)),
                            );
                        }
                    });
                    if (element.name) {
                        element.name = element.name.replace(/\[\d+\]/, `[${index}]`);
                    }
                    if (element.tagName === 'INPUT') {
                        element.value = '';
                    }
                    if (element.tagName === 'SELECT') {
                        element.selectedIndex = 0;
                    }
                });
                loanRows.append(row);
            });
        }

        // Copy buttons (addresses of the AI agents tab, backup codes, recovery key): shown only if the browser lets the
        // page write to the clipboard; the text of the element named by data-copy is copied (a list: one item per
        // line), the button says so for two seconds.
        const copyStatus = document.querySelector('[data-copy-status]');
        if (navigator.clipboard && window.isSecureContext) {
            document.querySelectorAll('[data-copy]').forEach((button) => {
                const source = document.getElementById(button.dataset.copy);
                if (!source) {
                    return;
                }
                const label = button.textContent;
                button.hidden = false;
                button.addEventListener('click', async () => {
                    try {
                        const items = source.matches('ul, ol') ? [...source.querySelectorAll('li')] : [source];
                        await navigator.clipboard.writeText(items.map((item) => item.textContent.trim()).join('\n'));
                    } catch {
                        return;
                    }
                    button.textContent = button.dataset.copied;
                    button.classList.add('is-copied');
                    if (copyStatus) {
                        copyStatus.textContent = copyStatus.dataset.copiedMessage;
                    }
                    window.setTimeout(() => {
                        button.textContent = label;
                        button.classList.remove('is-copied');
                    }, 2000);
                });
            });
        }

        // Print buttons (backup codes, recovery key): hidden without JavaScript, they open the browser's print dialog.
        if (typeof window.print === 'function') {
            document.querySelectorAll('[data-print]').forEach((button) => {
                button.hidden = false;
                button.addEventListener('click', () => window.print());
            });
        }

        // Second factor: the security key form takes the "trust this browser" box of the code form along.
        document.querySelectorAll('[data-trust-copy]').forEach((copy) => {
            const box = document.getElementById(copy.dataset.trustCopy);
            if (!box) {
                return;
            }
            const sync = () => {
                copy.value = box.checked ? '1' : '';
            };
            box.addEventListener('change', sync);
            sync();
        });

        // Goal form: the target amount or the months of spending, the level or the accounts, according to the two lists
        // (without JavaScript, all the fields are shown with the case they are for).
        document.querySelectorAll('[data-goal-form]').forEach((form) => {
            form.classList.add('has-goal-conditions');
            const update = () => {
                form.querySelectorAll('[data-goal-when]').forEach((field) => {
                    const choice = form.elements.namedItem(field.dataset.goalWhen);
                    field.hidden = choice !== null && choice.value !== '' && choice.value !== field.dataset.goalValue;
                });
            };
            form.addEventListener('change', update);
            update();
        });

        // Guided tour of the demonstration: highlights the element the step talks about and brings it to the top of the
        // screen, above the card kept at the bottom.
        const tourCard = document.querySelector('[data-tour-target]');
        const tourTarget = tourCard ? document.querySelector(tourCard.dataset.tourTarget) : null;
        if (tourTarget) {
            tourTarget.classList.add('tour-highlight');
            const still = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
            tourTarget.scrollIntoView({ block: 'start', behavior: still ? 'auto' : 'smooth' });
        }

        // Confirmation before destructive actions: <form data-confirm="Message">.
        document.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!window.confirm(form.dataset.confirm)) {
                    event.preventDefault();
                }
            });
        });

        // Account form: the chosen type preselects its default level and nature.
        const typeSelect = document.querySelector('[data-type-select]');
        if (typeSelect) {
            const levelSelect = document.querySelector('[data-level-select]');
            const natureSelect = document.querySelector('[data-nature-select]');
            typeSelect.addEventListener('change', () => {
                const option = typeSelect.selectedOptions[0];
                if (!option || !option.dataset.level) {
                    return;
                }
                if (levelSelect) {
                    levelSelect.value = option.dataset.level;
                }
                if (natureSelect) {
                    natureSelect.value = option.dataset.nature;
                }
            });
        }

        document.querySelectorAll('form[data-entry-form]').forEach(initEntryForm);
        document.querySelectorAll('form[data-questions]').forEach(initQuestions);
        document.querySelectorAll('form[data-budget-form]').forEach(initBudgetForm);
        document.querySelectorAll('select[data-combobox]').forEach(initCombobox);
        document.querySelectorAll('[data-month-picker]').forEach(initMonthPicker);
        document.querySelectorAll('[data-theme-toggle]').forEach(initThemeToggle);
        document.querySelectorAll('[data-locale-menu], [data-dropdown]').forEach(initDropdown);
        document.querySelectorAll('[data-level-highlight]').forEach(initLevelHighlight);
        document.querySelectorAll('select[data-household-count]').forEach(initHouseholdCount);
        document.querySelectorAll('[data-month-field]').forEach(initMonthField);
        document.querySelectorAll('[data-password-meter]').forEach(initPasswordMeter);
    });

    /**
     * Strength of a password, from 0 (very weak) to 4 (very strong): same estimate as the server
     * (Symfony\Component\Validator\Constraints\PasswordStrengthValidator::estimateStrength), on the UTF-8 bytes:
     * entropy = distinct bytes × log2(pool of their classes) + repeated bytes × log2(distinct bytes).
     */
    function estimatePasswordStrength(password) {
        const bytes = new TextEncoder().encode(password);
        if (bytes.length === 0) {
            return 0;
        }
        const distinct = new Set(bytes);
        const pools = { control: 0, digit: 0, upper: 0, lower: 0, other: 0, symbol: 0 };
        distinct.forEach((byte) => {
            if (byte < 32 || byte === 127) {
                pools.control = 33;
            } else if (byte >= 48 && byte <= 57) {
                pools.digit = 10;
            } else if (byte >= 65 && byte <= 90) {
                pools.upper = 26;
            } else if (byte >= 97 && byte <= 122) {
                pools.lower = 26;
            } else if (byte >= 128) {
                pools.other = 128;
            } else {
                pools.symbol = 33;
            }
        });
        const pool = Object.values(pools).reduce((sum, size) => sum + size, 0);
        const entropy = distinct.size * Math.log2(pool) + (bytes.length - distinct.size) * Math.log2(distinct.size);
        if (entropy >= 120) {
            return 4;
        }
        if (entropy >= 100) {
            return 3;
        }
        if (entropy >= 80) {
            return 2;
        }
        return entropy >= 60 ? 1 : 0;
    }

    /**
     * New password: strength bar and label, and each requirement of the server marked as met or not, while typing.
     * The leak check (Have I Been Pwned) stays on the server, at submission.
     */
    function initPasswordMeter(meter) {
        const input = document.getElementById(meter.dataset.for);
        if (!input) {
            return;
        }
        const confirmation = meter.dataset.confirm ? document.getElementById(meter.dataset.confirm) : null;
        const emailInput = meter.dataset.emailInput ? document.getElementById(meter.dataset.emailInput) : null;
        const minLength = Number(meter.dataset.minLength);
        const strength = meter.querySelector('[data-password-strength]');
        const fill = meter.querySelector('[data-strength-fill]');
        const label = meter.querySelector('[data-strength-label]');
        const describedBy = (input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
        if (!describedBy.includes(meter.id)) {
            input.setAttribute('aria-describedby', [...describedBy, meter.id].join(' '));
        }

        const mark = (rule, met) => {
            const item = meter.querySelector(`[data-rule="${rule}"]`);
            if (!item) {
                return;
            }
            item.classList.toggle('is-met', met);
            item.classList.toggle('is-unmet', !met);
            item.querySelector('[data-rule-state]').textContent = ` (${met ? meter.dataset.met : meter.dataset.unmet})`;
        };

        const update = () => {
            const password = input.value;
            const level = estimatePasswordStrength(password);
            strength.hidden = false;
            fill.dataset.level = String(level);
            const levelName = meter.dataset[`level-${level}`]; // data-level-0 … data-level-4
            if (label.textContent !== levelName) {
                label.textContent = levelName;
            }
            const email = (emailInput ? emailInput.value : meter.dataset.email || '').trim().toLowerCase();
            mark('length', [...password].length >= minLength);
            mark('email', email === '' || !password.toLowerCase().includes(email));
            mark('strength', level >= 2);
            if (confirmation) {
                mark('match', password !== '' && confirmation.value === password);
            }
        };

        [input, confirmation, emailInput].forEach((field) => field?.addEventListener('input', update));
        update();
    }

    /**
     * Typing list (combobox) instead of a <select data-combobox>: typing in the field keeps only the choices whose
     * label contains the text, regardless of case and accents; the groups keep their order. Mouse, arrows, Enter
     * (choose) and Escape (close). The hidden <select> carries the value sent and receives the "change" event;
     * without JavaScript, it shows on its own.
     */
    function initCombobox(select) {
        const { comboboxPlaceholder, comboboxShowAll, statusNone, statusOne, statusMany } = select.dataset;
        const normalize = (text) =>
            text
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();
        const listId = `${select.id}-options`;
        const create = (tag, className, text) => {
            const element = document.createElement(tag);
            if (className) {
                element.className = className;
            }
            if (text !== undefined) {
                element.textContent = text;
            }
            return element;
        };

        const input = create('input', 'combobox-input');
        input.type = 'text';
        input.id = `${select.id}-combobox`;
        input.autocomplete = 'off';
        input.placeholder = comboboxPlaceholder || '';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);
        ['aria-invalid', 'aria-describedby'].forEach((name) => {
            if (select.hasAttribute(name)) {
                input.setAttribute(name, select.getAttribute(name));
            }
        });
        const list = create('ul', 'combobox-list');
        list.id = listId;
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        const status = create('p', 'visually-hidden');
        status.setAttribute('aria-live', 'polite');

        let active = null;
        const choose = (item) => {
            select.value = item.dataset.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            input.value = item.textContent;
            close();
        };
        const makeOption = (option) => {
            const item = create('li', 'combobox-option', option.textContent);
            item.id = `${listId}-${option.value}`;
            item.dataset.value = option.value;
            item.setAttribute('role', 'option');
            // The click must not make the field lose focus before the choice is made.
            item.addEventListener('mousedown', (event) => event.preventDefault());
            item.addEventListener('click', () => choose(item));
            return item;
        };
        // Entries in the <select>'s order: groups (with their header) or single options, without the empty option.
        const entries = Array.from(select.children).flatMap((child) => {
            if (child.tagName === 'OPTGROUP') {
                const header = create('li', 'combobox-group', child.label);
                header.setAttribute('role', 'presentation');
                return [{ header, options: Array.from(child.querySelectorAll('option')).map(makeOption) }];
            }
            return child.value === '' ? [] : [{ header: null, options: [makeOption(child)] }];
        });
        const empty = create('li', 'combobox-empty', statusNone || '');
        empty.setAttribute('role', 'presentation');

        const options = () => Array.from(list.querySelectorAll('[role="option"]'));
        const setActive = (item) => {
            if (active) {
                active.classList.remove('is-active');
            }
            active = item;
            if (item) {
                item.classList.add('is-active');
                input.setAttribute('aria-activedescendant', item.id);
                item.scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        };
        const filter = (text) => {
            const query = normalize(text);
            const shown = [];
            let count = 0;
            entries.forEach(({ header, options: entryOptions }) => {
                const matches = entryOptions.filter(
                    (item) => query === '' || normalize(item.textContent).includes(query),
                );
                matches.forEach((item) =>
                    item.setAttribute('aria-selected', item.dataset.value === select.value ? 'true' : 'false'),
                );
                if (matches.length > 0) {
                    shown.push(...(header ? [header] : []), ...matches);
                    count += matches.length;
                }
            });
            list.replaceChildren(...(count === 0 ? [empty] : shown));
            status.textContent =
                query === ''
                    ? ''
                    : count === 0
                      ? statusNone
                      : (count > 1 ? statusMany : statusOne).replace('%count%', String(count));
            setActive(options().find((item) => item.dataset.value === select.value) || options()[0] || null);
        };
        const open = (text) => {
            filter(text);
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };
        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            setActive(null);
        }
        const selectedLabel = () => {
            const option = select.selectedOptions[0];
            return option && option.value !== '' ? option.textContent : '';
        };

        // A click shows the whole list; the text is selected, so that typing replaces it and filters.
        const showAll = () => {
            open('');
            input.select();
        };
        input.addEventListener('input', () => open(input.value));
        input.addEventListener('click', () => {
            if (list.hidden) {
                showAll();
            }
        });
        // Arrow button: shows that it is a list, opens it whole or closes it (the keyboard uses the arrow keys).
        const toggle = create('button', 'combobox-toggle');
        toggle.type = 'button';
        toggle.tabIndex = -1;
        toggle.setAttribute('aria-label', comboboxShowAll || '');
        toggle.setAttribute('aria-controls', listId);
        toggle.addEventListener('mousedown', (event) => event.preventDefault());
        toggle.addEventListener('click', () => {
            if (list.hidden) {
                input.focus();
                showAll();
            } else {
                close();
            }
        });
        input.addEventListener('keydown', (event) => {
            const items = options();
            const index = items.indexOf(active);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (list.hidden) {
                    open('');
                } else {
                    setActive(items[Math.min(index + 1, items.length - 1)] || null);
                }
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (!list.hidden) {
                    setActive(items[Math.max(index - 1, 0)] || null);
                }
            } else if (event.key === 'Enter' && !list.hidden) {
                event.preventDefault();
                if (active) {
                    choose(active);
                }
            } else if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                close();
                input.value = selectedLabel();
            }
        });
        // Leaving the field without choosing brings it back to the current choice.
        input.addEventListener('blur', () => {
            close();
            input.value = selectedLabel();
        });

        input.value = selectedLabel();
        const label = document.querySelector(`label[for="${select.id}"]`);
        if (label) {
            label.htmlFor = input.id;
        }
        const wrapper = create('div', 'combobox');
        wrapper.append(input, toggle, list, status);
        select.after(wrapper);
        select.hidden = true;
    }

    /**
     * Month (YYYY-MM) or year (YYYY) field shown as the month picker of the page heads: a button with the value ("Oct.
     * 2026", "1985") opens a calendar of 12 months, or of 12 years, and a click fills the field, which stays the one
     * sent. Without JavaScript, the text field remains, to type in.
     */
    function initMonthField(field) {
        const input = field.querySelector('input');
        const { today, labelOpen, labelPrevious, labelNext, labelEmpty, labelClear } = field.dataset;
        const years = field.dataset.mode === 'year';
        const SHORT = (field.dataset.shortNames || '').split('|');
        const LONG = (field.dataset.longNames || '').split('|');
        const key = (y, m) => `${y}-${String(m).padStart(2, '0')}`;
        const todayYear = Number(today.slice(0, 4));
        // First year shown: the year itself for months, the start of its row of 12 for years.
        const firstOf = (year) => (years ? year - ((year - 1900) % 12) : year);
        let viewYear = firstOf(todayYear);

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn month-picker-toggle month-field-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'icon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4');
        svg.append(path);
        const value = document.createElement('span');
        // The accessible name starts with the visible value (WCAG 2.5.3), then says what the button does.
        const hint = document.createElement('span');
        hint.className = 'visually-hidden';
        const label = input.labels?.[0];
        hint.textContent = ` — ${labelOpen}`;
        if (label && input.id) {
            toggle.id = `${input.id}-toggle`;
            label.htmlFor = toggle.id;
            label.id ||= `${input.id}-label`;
            value.id = `${input.id}-value`;
            hint.id = `${input.id}-hint`;
            toggle.setAttribute('aria-labelledby', `${value.id} ${label.id} ${hint.id}`);
        }
        toggle.append(svg, value, hint);

        // The value as shown: "Oct. 2026" or "1985"; the field's own text while it cannot be read.
        const shown = () => {
            const typed = input.value.trim();
            const month = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(typed);
            if (!years && month) {
                const label = `${SHORT[Number(month[2]) - 1]} ${month[1]}`;
                return label.charAt(0).toUpperCase() + label.slice(1);
            }
            return typed === '' ? labelEmpty : typed;
        };
        const update = () => {
            value.textContent = shown();
        };

        const panel = document.createElement('div');
        panel.className = 'month-picker-panel month-field-panel';
        panel.setAttribute('role', 'group');
        panel.setAttribute('aria-label', labelOpen);
        panel.hidden = true;
        const head = document.createElement('div');
        head.className = 'mp-head';
        const step = (label, text, delta) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'mp-step';
            button.textContent = text;
            button.setAttribute('aria-label', label);
            button.addEventListener('click', () => {
                viewYear += delta * (years ? 12 : 1);
                render();
            });
            return button;
        };
        const previous = step(labelPrevious, '‹', -1);
        const next = step(labelNext, '›', 1);
        const title = document.createElement('span');
        title.className = 'mp-year';
        title.setAttribute('aria-live', 'polite');
        head.append(previous, title, next);
        const grid = document.createElement('ul');
        grid.className = 'mp-grid';
        panel.append(head, grid);
        // Optional year: it can be emptied again.
        if (labelClear) {
            const clear = document.createElement('p');
            clear.className = 'mp-foot';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'link-button';
            button.textContent = labelClear;
            button.addEventListener('click', () => choose(''));
            clear.append(button);
            panel.append(clear);
        }
        input.hidden = true;
        // Hidden, it could not take the focus of a "required" message: the server checks the value.
        input.required = false;
        field.append(toggle, panel);
        update();

        function choose(picked) {
            input.value = picked;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            update();
            close();
            toggle.focus();
        }

        // One cell of the grid: a month of viewYear, or a year of the row starting at viewYear.
        function cell(index) {
            const picked = years ? String(viewYear + index) : key(viewYear, index + 1);
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            const isToday = years ? viewYear + index === todayYear : picked === today;
            button.className = 'mp-cell' + (isToday ? ' is-today' : '');
            button.textContent = years ? picked : SHORT[index];
            button.setAttribute('aria-label', years ? picked : `${LONG[index]} ${viewYear}`);
            if (picked === input.value.trim()) {
                button.setAttribute('aria-current', 'true');
            }
            button.addEventListener('click', () => choose(picked));
            li.append(button);
            return li;
        }

        function render() {
            title.textContent = years ? `${viewYear} – ${viewYear + 11}` : String(viewYear);
            previous.disabled = viewYear <= 1900;
            grid.replaceChildren(...Array.from({ length: 12 }, (_, index) => cell(index)));
        }

        function open() {
            const typed = /^(\d{4})/.exec(input.value.trim());
            viewYear = firstOf(typed ? Number(typed[1]) : todayYear);
            render();
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            (
                grid.querySelector('[aria-current]') ||
                grid.querySelector('.is-today') ||
                grid.querySelector('button')
            ).focus();
        }

        function close() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', () => (panel.hidden ? open() : close()));
        document.addEventListener('click', (event) => {
            if (!panel.hidden && !field.contains(event.target)) {
                close();
            }
        });
        field.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                close();
                toggle.focus();
            }
        });
    }

    /** Drop-down menu in <details> (language): closed by a click elsewhere or by Escape, which gives focus back to the button. */
    function initDropdown(menu) {
        document.addEventListener('click', (event) => {
            if (menu.open && !menu.contains(event.target)) {
                menu.open = false;
            }
        });
        menu.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && menu.open) {
                menu.open = false;
                menu.querySelector('summary').focus();
            }
        });
    }

    /**
     * Light / dark switch without reloading. Without JavaScript, the form goes through the server
     * (ThemeController), which writes the same cookie. The theme shown is that of the data-theme
     * attribute, otherwise the system's.
     */
    function initThemeToggle(form) {
        const root = document.documentElement;
        const cookie = form.dataset.themeCookie;
        const ONE_YEAR = 365 * 24 * 3600;

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const dark = root.dataset.theme
                ? root.dataset.theme === 'dark'
                : window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = dark ? 'light' : 'dark';

            root.dataset.theme = theme;
            const secure = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = `${cookie}=${theme}; Path=/; Max-Age=${ONE_YEAR}; SameSite=Strict${secure}`;
            document.dispatchEvent(new CustomEvent('patrimeter:themechange', { detail: { theme } }));
        });
    }

    /**
     * Month picker: the template provides a version that works without JS (links); here the year changes
     * without reloading, and the grid of years, the keyboard and the closing are added.
     */
    function initMonthPicker(picker) {
        // Month names and labels in the interface language, given by the template.
        const { base, selected, max, first, labelPrevious, labelNext, labelYear } = picker.dataset;
        // Current month, marked in the calendar; the entries allow one more month (max).
        const today = picker.dataset.today || max;
        const SHORT = picker.dataset.shortNames.split('|');
        const LONG = picker.dataset.longNames.split('|');
        const maxYear = Number(max.slice(0, 4));
        const toggle = picker.querySelector('summary');
        const yearButton = picker.querySelector('[data-year-toggle]');
        const monthsGrid = picker.querySelector('[data-months]');
        const yearsGrid = picker.querySelector('[data-years]');
        const key = (y, m) => `${y}-${String(m).padStart(2, '0')}`;
        let viewYear = Number(selected.slice(0, 4));

        // The year arrows become buttons (no more page reload).
        const steps = {};
        picker.querySelectorAll('[data-year-step]').forEach((el) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'mp-step';
            button.textContent = el.textContent;
            button.dataset.yearStep = el.dataset.yearStep;
            button.setAttribute('aria-label', el.dataset.yearStep === '1' ? labelNext : labelPrevious);
            button.addEventListener('click', () => {
                if (!button.disabled) {
                    viewYear += Number(button.dataset.yearStep);
                    render();
                }
            });
            el.replaceWith(button);
            steps[button.dataset.yearStep] = button;
        });

        const cellsFocusable = () => Array.from(monthsGrid.querySelectorAll('a.mp-cell'));

        function render() {
            yearButton.textContent = String(viewYear);
            yearButton.setAttribute('aria-label', labelYear.replace('%year%', String(viewYear)));
            steps['1'].disabled = viewYear >= maxYear;
            steps['1'].classList.toggle('is-disabled', viewYear >= maxYear);
            monthsGrid.replaceChildren(
                ...LONG.map((label, i) => {
                    const month = key(viewYear, i + 1);
                    const li = document.createElement('li');
                    const cell = document.createElement(month > max ? 'span' : 'a');
                    cell.className = 'mp-cell';
                    cell.textContent = SHORT[i];
                    if (month > max) {
                        cell.classList.add('is-disabled');
                        cell.setAttribute('aria-disabled', 'true');
                    } else {
                        cell.href = `${base}?mois=${month}`;
                        cell.setAttribute('aria-label', `${label} ${viewYear}`);
                        if (month === selected) {
                            cell.setAttribute('aria-current', 'date');
                        }
                    }
                    if (month === today) {
                        cell.classList.add('is-today');
                    }
                    if (first && month < first) {
                        cell.classList.add('is-empty');
                    }
                    li.append(cell);
                    return li;
                }),
            );
            yearsGrid.querySelectorAll('[data-year]').forEach((b) => {
                if (Number(b.dataset.year) === viewYear) {
                    b.setAttribute('aria-current', 'true');
                } else {
                    b.removeAttribute('aria-current');
                }
            });
        }

        function showYears(show) {
            yearsGrid.hidden = !show;
            monthsGrid.hidden = show;
            yearButton.setAttribute('aria-expanded', String(show));
            const target = show ? yearsGrid.querySelector('[aria-current]') : null;
            if (target) {
                target.scrollIntoView({ block: 'nearest' });
                target.focus();
            }
        }

        yearButton.addEventListener('click', () => showYears(yearsGrid.hidden));
        yearsGrid.addEventListener('click', (event) => {
            const button = event.target.closest('[data-year]');
            if (button) {
                viewYear = Number(button.dataset.year);
                render();
                showYears(false);
                (monthsGrid.querySelector('[aria-current]') || cellsFocusable().pop() || yearButton).focus();
            }
        });

        // Keyboard arrows in the grid of months (3 columns).
        monthsGrid.addEventListener('keydown', (event) => {
            const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -3, ArrowDown: 3 };
            if (!(event.key in moves)) {
                return;
            }
            const cells = Array.from(monthsGrid.querySelectorAll('.mp-cell'));
            let i = cells.indexOf(document.activeElement) + moves[event.key];
            while (i >= 0 && i < cells.length && cells[i].tagName !== 'A') {
                i += Math.sign(moves[event.key]);
            }
            if (i >= 0 && i < cells.length) {
                event.preventDefault();
                cells[i].focus();
            }
        });

        picker.addEventListener('toggle', () => {
            if (picker.open) {
                viewYear = Number(selected.slice(0, 4));
                render();
                showYears(false);
                (monthsGrid.querySelector('[aria-current]') || cellsFocusable().pop() || yearButton).focus();
            }
        });
        picker.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && picker.open) {
                event.preventDefault();
                picker.open = false;
                toggle.focus();
            }
        });
        document.addEventListener('click', (event) => {
            if (picker.open && !picker.contains(event.target)) {
                picker.open = false;
            }
        });

        render();
    }

    /*
     * Monthly entry: amounts formatted, level totals and net worth recalculated while typing (property shares
     * included), discreet gap with the previous month, "entered" status as soon as an amount is confirmed, and
     * keyboard: Enter moves to the next field, Shift+Enter to the previous one, Escape restores the suggested amount.
     * The server remains the only judge: these calculations only serve the display (same rules as Money and
     * OwnershipShares).
     */
    /**
     * Amounts in the page's language, like Money on the server: reading an input, formatting a field and a displayed
     * amount, share in millionths (monthly entry, budget entry).
     */
    function amountTools() {
        const english = document.documentElement.lang === 'en';
        const NBSP = '\u00A0';
        const NNBSP = '\u202F';
        const MINUS = '\u2212';
        const FULL = 1000000;
        const MAX_EURO_DIGITS = 13;
        // Thousands separators, three digits at a time from the right (a loop: linear, unlike a look-ahead regex).
        const group = (digits, separator) => {
            const parts = [];
            for (let end = digits.length; end > 0; end -= 3) {
                parts.unshift(digits.slice(Math.max(0, end - 3), end));
            }
            return parts.join(separator);
        };
        const plain = (cents, separator) =>
            group(String(Math.floor(cents / 100)), separator) +
            (english ? '.' : ',') +
            String(cents % 100).padStart(2, '0');
        // Like the Twig "money" filter: "1 234,56 €", "€1,234.56", "−" attached to the digits.
        const money = (cents, signed = false) => {
            const amount = plain(Math.abs(cents), english ? ',' : NNBSP);
            const sign = cents < 0 ? MINUS : signed && cents > 0 ? '+' : '';
            return sign + (english ? `€${amount}` : `${amount}${NBSP}€`);
        };
        // Value of a field: no-break space as wide as a digit (aligned columns); loan preceded by "−".
        const fieldText = (cents, liability = false) => {
            const negative = liability ? cents > 0 : cents < 0;
            return (negative ? MINUS : '') + plain(Math.abs(cents), english ? ',' : NBSP);
        };
        // Like Money::parse, in the page's language: null for an empty field, NaN for an unreadable amount.
        const parse = (text) => {
            let s = text
                .trim()
                .replace(/[\s\u00A0\u202F€']/g, '')
                .replace(/\u2212/g, '-');
            if (s === '') {
                return null;
            }
            let negative = false;
            if (s[0] === '-' || s[0] === '+') {
                negative = s[0] === '-';
                s = s.slice(1);
            }
            const comma = s.includes(',');
            const dot = s.includes('.');
            if (comma && dot && /^[1-9]\d{0,2}(,\d{3})+\.\d{0,2}$/.test(s)) {
                s = s.replace(/,/g, '');
            } else if (comma && dot) {
                if (!/^[1-9]\d{0,2}(\.\d{3})+,\d{0,2}$/.test(s)) {
                    return Number.NaN;
                }
                s = s.replace(/\./g, '').replace(',', '.');
            } else if (comma && english && /^[1-9]\d{0,2}(,\d{3})+$/.test(s)) {
                // "1,234" in English: thousands commas.
                s = s.replace(/,/g, '');
            } else if (comma) {
                s = s.replace(/,/g, '.');
            } else if (dot && !english && /^[1-9]\d{0,2}(\.\d{3})+$/.test(s)) {
                // "1.234" in French: thousands dots.
                s = s.replace(/\./g, '');
            }
            const match = /^(\d+)(?:\.(\d{0,2}))?$/.exec(s);
            if (!match || match[1].replace(/^0+/, '').length > MAX_EURO_DIGITS) {
                return Number.NaN;
            }
            const cents = Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
            return negative ? -cents : cents;
        };
        // Like OwnershipShares::apply: rounded to the nearest cent, without exceeding the precision of numbers.
        const apply = (cents, share) => {
            const absolute = Math.abs(cents);
            const value =
                Math.floor(absolute / FULL) * share + Math.floor(((absolute % FULL) * share + FULL / 2) / FULL);
            return cents < 0 ? -value : value;
        };

        return { money, fieldText, parse, apply };
    }

    /**
     * Simple questions for beginners: one question at a time (all of them without JavaScript), with its number, Back
     * and Next; the first question with an error comes first. Typing an exact amount selects "Exact amount".
     */
    function initQuestions(form) {
        const questions = Array.from(form.querySelectorAll('[data-question]'));
        const progress = form.querySelector('[data-questions-progress]');
        const back = form.querySelector('[data-questions-back]');
        const next = form.querySelector('[data-questions-next]');
        const submit = form.querySelector('[data-questions-submit]');
        const failed = questions.findIndex((question) => question.hasAttribute('data-question-error'));
        let current = Math.max(0, failed);

        const show = (index, focus) => {
            current = index;
            questions.forEach((question, position) => {
                question.hidden = position !== index;
            });
            progress.hidden = false;
            progress.textContent = form.dataset.progressLabel
                .replace('%number%', String(index + 1))
                .replace('%total%', String(questions.length));
            back.hidden = index === 0;
            next.hidden = index === questions.length - 1;
            submit.hidden = index !== questions.length - 1;
            if (focus) {
                const target =
                    questions[index].querySelector('input:checked') || questions[index].querySelector('input');
                target.focus();
            }
        };

        back.addEventListener('click', () => show(current - 1, true));
        next.addEventListener('click', () => show(current + 1, true));
        form.querySelectorAll('[data-question-amount]').forEach((amount) => {
            amount.addEventListener('input', () => {
                const exact = amount.closest('[data-question]').querySelector('[data-question-exact]');
                exact.checked = amount.value.trim() !== '';
            });
        });
        // Enter in an amount moves to the next question rather than sending the form too early.
        form.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && event.target.matches('input') && current < questions.length - 1) {
                event.preventDefault();
                show(current + 1, true);
            }
        });
        show(current, failed >= 0);
    }

    /**
     * Monthly entry: amounts formatted, level totals and net worth recalculated while typing, gap with the previous
     * month, Enter to move to the next field and Escape to restore the suggested amount. "=" takes the previous month's
     * balance; a folded level (all entered) opens when one of its fields gets the focus.
     */
    function initEntryForm(form) {
        const { money, fieldText, parse, apply } = amountTools();

        const rows = Array.from(form.querySelectorAll('[data-entry-row]'), (tr) => {
            // The amount field, not a hidden field of the row (account carried over from a later month: advance[]).
            const field = tr.querySelector('input[name^="amounts"]');
            const liability = tr.dataset.liability === '1';
            // A loan is an outstanding principal: "−1 000" and "1 000" mean the same amount.
            const valueOf = (text) => {
                const cents = parse(text);
                return liability && cents !== null ? Math.abs(cents) : cents;
            };
            const row = {
                tr,
                field,
                liability,
                valueOf,
                diff: tr.querySelector('[data-entry-diff]'),
                same: tr.querySelector('[data-entry-same]'),
                section: tr.closest('[data-entry-level]'),
                share: Number(tr.dataset.share),
                previous: tr.dataset.previous === '' ? null : Number(tr.dataset.previous),
                entered: tr.classList.contains('is-entered'),
                initial: valueOf(field.dataset.initial),
                touched: false,
            };
            const tidy = () => {
                const cents = valueOf(field.value);
                if (cents !== null && !Number.isNaN(cents)) {
                    field.value = fieldText(cents, liability);
                }
            };
            if (!field.hasAttribute('aria-invalid')) {
                tidy();
            }
            row.tidy = tidy;
            return row;
        });
        const progress = document.querySelector('[data-entry-progress]');
        const count = document.querySelector('[data-entry-count]');
        const net = form.querySelector('[data-entry-net]');
        const change = form.querySelector('[data-entry-change]');

        const render = () => {
            let entered = 0;
            let total = 0;
            const totals = new Map();
            rows.forEach((row) => {
                const cents = row.valueOf(row.field.value);
                const valid = !Number.isNaN(cents);
                row.tr.classList.toggle('is-touched', row.touched);
                if (row.entered || row.touched) {
                    entered += 1;
                }
                row.diff.classList.toggle('is-error', !valid);
                if (row.same) {
                    // "=" while the account is not confirmed; then its line shows the gap, if any.
                    row.same.hidden = row.touched;
                    row.diff.hidden = !row.touched;
                }
                if (!valid) {
                    row.diff.textContent = form.dataset.invalidLabel;
                } else if (cents !== null && row.previous !== null && cents !== row.initial) {
                    // Gap on the net worth: a loan going down is a positive gap.
                    const delta = (cents - row.previous) * (row.liability ? -1 : 1);
                    row.diff.textContent = form.dataset.diffLabel.replace('%amount%', money(delta, true));
                } else {
                    row.diff.textContent = '';
                }
                const contribution = valid && cents !== null ? apply(cents, row.share) * (row.liability ? -1 : 1) : 0;
                totals.set(row.section, (totals.get(row.section) || 0) + contribution);
                total += contribution;
            });
            totals.forEach((sum, section) => {
                section.querySelectorAll('[data-level-total], [data-level-sum]').forEach((cell) => {
                    cell.textContent = money(sum);
                });
            });
            if (net) {
                net.textContent = money(total);
            }
            if (change) {
                change.textContent = change.dataset.label.replace(
                    '%amount%',
                    money(total - Number(change.dataset.previousNet), true),
                );
            }
            if (progress && count) {
                progress.value = entered;
                progress.textContent = `${entered}/${rows.length}`;
                count.textContent = String(entered);
            }
        };

        const submit = form.querySelector('button[type="submit"]');
        // Next (or previous) field, its level opened if it was folded.
        const focusFrom = (index, step) => {
            const next = rows[index + step];
            if (!next) {
                submit.focus();
                return;
            }
            const fold = next.field.closest('details');
            if (fold) {
                fold.open = true;
            }
            next.field.focus();
        };
        rows.forEach((row, index) => {
            const { field } = row;
            // "=" (button or key): the previous month's balance, confirmed, then the next field.
            const same = () => {
                field.value = fieldText(row.previous, row.liability);
                row.touched = true;
                render();
                focusFrom(index, 1);
            };
            if (row.same && row.previous !== null) {
                row.same.addEventListener('click', same);
            }
            field.addEventListener('input', () => {
                const cents = row.valueOf(field.value);
                row.touched = row.touched || Number.isNaN(cents) || cents !== row.initial;
                render();
            });
            // On mobile, select the content on focus to replace the value in one gesture.
            field.addEventListener('focus', () => field.select());
            field.addEventListener('blur', () => {
                row.tidy();
            });
            field.addEventListener('keydown', (event) => {
                if (event.isComposing) {
                    return;
                }
                if (event.key === 'Enter') {
                    // Enter confirms the amount (the account becomes "entered") and moves to the next field, without sending.
                    event.preventDefault();
                    row.touched = true;
                    render();
                    focusFrom(index, event.shiftKey ? -1 : 1);
                } else if (event.key === '=' && row.previous !== null) {
                    event.preventDefault();
                    same();
                } else if (event.key === 'Escape') {
                    field.value = field.dataset.initial;
                    row.tidy();
                    row.touched = false;
                    render();
                }
            });
        });

        // "?" help: closes with a click elsewhere or with Escape.
        const help = document.querySelector('.entry-help-toggle');
        if (help) {
            document.addEventListener('click', (event) => {
                if (help.open && !help.contains(event.target)) {
                    help.open = false;
                }
            });
            help.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && help.open) {
                    help.open = false;
                    help.querySelector('summary').focus();
                }
            });
        }

        render();
    }

    /**
     * Tax household form: the number of adults or of children chosen in a list shows only the rows of these people
     * (first name, year of birth); the other rows are hidden, and ignored by the server. The group of the children
     * disappears when there is none.
     */
    function initHouseholdCount(select) {
        const kind = select.dataset.householdCount;
        const form = select.form ?? document;
        const rows = Array.from(form.querySelectorAll(`[data-household-row="${kind}"]`));
        const group = form.querySelector(`[data-household-group="${kind}"]`);
        const render = () => {
            const count = Number.parseInt(select.value, 10) || 0;
            rows.forEach((row) => {
                row.hidden = Number.parseInt(row.dataset.householdIndex, 10) > count;
            });
            if (group) {
                group.hidden = count === 0;
            }
        };
        select.addEventListener('change', render);
        render();
    }

    /**
     * Wealth page: a level of the pyramid, hovered, focused or clicked, highlights the lines of that level in the
     * other boxes (detail by level, split of the investments, year to date), and a line highlights its tier. A click
     * keeps the highlight (aria-pressed on the tier would not be read inside the picture: the lines keep their text);
     * a second click, a click elsewhere or Escape lets it go. The tiers of the pyramid carry their level in their class
     * (pyr-<level>, PyramidSvg, whose output the v1 reference results pin down): it is copied into data-level here.
     */
    function initLevelHighlight(scope) {
        scope.querySelectorAll('svg .pyr-tier').forEach((tier) => {
            const level = Array.from(tier.classList).find((name) => name.startsWith('pyr-') && name !== 'pyr-tier');
            if (level) {
                tier.dataset.level = level.slice('pyr-'.length);
            }
        });
        const items = Array.from(scope.querySelectorAll('[data-level]'));
        if (items.length === 0) {
            return;
        }
        let pinned = null;
        const show = (level) => {
            items.forEach((item) =>
                item.classList.toggle('is-level-highlighted', level !== null && item.dataset.level === level),
            );
            scope.classList.toggle('has-level-highlight', level !== null);
        };
        const levelOf = (target) => target.closest('[data-level]')?.dataset.level ?? null;
        scope.addEventListener('mouseover', (event) => {
            const level = levelOf(event.target);
            if (level !== null) {
                show(level);
            }
        });
        scope.addEventListener('mouseleave', () => show(pinned));
        scope.addEventListener('mouseout', (event) => {
            if (levelOf(event.target) !== null && levelOf(event.relatedTarget ?? scope) === null) {
                show(pinned);
            }
        });
        scope.addEventListener('focusin', (event) => {
            const level = levelOf(event.target);
            if (level !== null) {
                show(level);
            }
        });
        scope.addEventListener('focusout', () => show(pinned));
        scope.querySelectorAll('svg [data-level]').forEach((tier) => {
            tier.addEventListener('click', () => {
                pinned = pinned === tier.dataset.level ? null : tier.dataset.level;
                show(pinned ?? tier.dataset.level);
            });
        });
        document.addEventListener('click', (event) => {
            if (pinned !== null && !event.target.closest?.('svg [data-level]')) {
                pinned = null;
                show(levelOf(event.target));
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && pinned !== null) {
                pinned = null;
                show(null);
            }
        });
    }

    /**
     * Budget entry: amounts formatted, category totals, household incomes, transfer to the joint account and
     * remaining money recalculated while typing. An empty field is worth 0; an unreadable or negative amount does not
     * count (the server will refuse it). Enter moves to the next field; typing a fixed share chooses that split.
     */
    function initBudgetForm(form) {
        const { money, fieldText, parse } = amountTools();
        const fields = Array.from(form.querySelectorAll('[data-budget-income], [data-budget-amount]'));
        const incomes = fields.filter((field) => field.hasAttribute('data-budget-income'));
        const categories = Array.from(form.querySelectorAll('[data-budget-category]'), (section) => ({
            shared: section.dataset.scope === 'shared',
            fields: Array.from(section.querySelectorAll('[data-budget-amount]')),
            total: section.querySelector('[data-budget-category-total]'),
        }));
        const incomeTotal = form.querySelector('[data-budget-income-total]');
        const joint = form.querySelector('[data-budget-joint]');
        const available = form.querySelector('[data-budget-available]');
        const availableLabel = form.querySelector('[data-budget-available-label]');
        const valueOf = (field) => {
            const cents = parse(field.value);
            return cents === null || Number.isNaN(cents) || cents < 0 ? 0 : cents;
        };
        const tidy = (field) => {
            const cents = parse(field.value);
            if (cents !== null && !Number.isNaN(cents) && cents >= 0) {
                field.value = fieldText(cents);
            }
        };

        const render = () => {
            const income = incomes.reduce((sum, field) => sum + valueOf(field), 0);
            let spending = 0;
            let shared = 0;
            categories.forEach((category) => {
                const total = category.fields.reduce((sum, field) => sum + valueOf(field), 0);
                category.total.textContent = money(total);
                spending += total;
                shared += category.shared ? total : 0;
            });
            incomeTotal.textContent = money(income);
            joint.textContent = money(shared);
            const rest = income - spending;
            available.textContent = money(rest);
            available.classList.toggle('negative', rest < 0);
            availableLabel.textContent =
                rest < 0 ? availableLabel.dataset.labelOverrun : availableLabel.dataset.labelAvailable;
        };

        const submit = form.querySelector('button[type="submit"]');
        fields.forEach((field, index) => {
            if (!field.hasAttribute('aria-invalid')) {
                tidy(field);
            }
            field.addEventListener('input', render);
            field.addEventListener('focus', () => field.select());
            field.addEventListener('blur', () => tidy(field));
            field.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.isComposing) {
                    event.preventDefault();
                    tidy(field);
                    const next = fields[index + (event.shiftKey ? -1 : 1)];
                    (next ?? submit).focus();
                }
            });
        });
        const share = form.querySelector('[data-budget-share]');
        const fixed = form.querySelector('input[name="split_rule"][value="fixed"]');
        if (share && fixed) {
            share.addEventListener('input', () => {
                fixed.checked = true;
            });
        }

        render();
    }
})();
