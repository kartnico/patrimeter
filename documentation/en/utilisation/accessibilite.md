# Accessibility

Patrimeter aims for level AA of the WCAG 2.2 guidelines (adopted by the French RGAA). No external audit has
been carried out: this page describes what is done and checked, and the known limits. Report any difficulty to
the administrator of your instance.

## Sight: low vision and colour blindness

- **Contrast**: at least 4.5:1 for all text, 3:1 for field borders, the focus outline and graphical elements,
  in the light theme as in the dark theme.
- **Colour blindness**: the six colours of the pyramid remain distinct from one another when simulating
  protanopia, deuteranopia and tritanopia. Above all, **no information relies on colour alone**:
    - each level of the pyramid shows its name, its amount and its share;
    - in the history chart, each level also has its own **pattern** (hatching, dots, lines…), and the table of
      monthly values gives the same figures;
    - increases and decreases carry their sign (+ or −), carried-over balances the "carried over" label, loans in
      the monthly update the "owed" label, the "−" sign and a dotted underline, input errors a written message.
- **Dark theme** if you wish (button at the top of the screen), otherwise the system's theme.
- **Zoom**: the page follows the browser's zoom and text size; designed for mobile first (checked at 390 pixels
  wide), it rearranges itself into a single column instead of scrolling sideways.
- **Screen readers**: language declared, headings in order, named navigation areas, fields linked to their
  label, announced messages (confirmations and errors), pyramid and scales described by a text alternative,
  chart backed by a table.

These colour checks are automatic: a continuous integration test fails if a changed colour drops below these
thresholds.

## Hearing: deafness and hearing loss

Patrimeter plays **no sound, no video, no audio content**: all the information is written.

- Confirmations, warnings and errors appear on screen, as text; confirmation and reset links and
  administration alerts arrive **by email**.
- Authentication requires **no call, no voice code, no audio CAPTCHA**: authenticator app (code shown), backup
  codes, passkeys or security keys.
- No action is time-limited by a sound signal; the session expires after a period of inactivity, back to the
  sign-in page.

## Keyboard and motor skills

- Everything can be done with the keyboard; the focus is always visible (thick outline).
- The **Patrimeter** logo, at the top of every page, leads back to the Net worth page. The header, the navigation
  and the content are marked-up areas: a screen reader goes straight to the content.
- Input fields are 44 pixels high, except those of the monthly update, more compact to fit on a table row (at
  least 24 pixels, like isolated buttons and links).
- Monthly update: **Enter** moves to the next field, **Shift + Enter** to the previous one, **Esc** restores
  the suggested amount ([monthly update](saisie.md#entering-quickly-with-the-keyboard)).
- Chart animations are turned off if the system asks to reduce motion.

## Known limits

- The history chart is an image for screen readers: its content is in the table placed below it.
- The interface and this documentation exist in French and in English; the technical pages about developing
  Patrimeter (architecture, continuous integration…) only exist in French.
