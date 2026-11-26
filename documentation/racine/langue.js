// Root of the documentation: opens the site in the browser's language, as the application chooses its own (first
// preferred language among French and English, otherwise French).
const languages = navigator.languages?.length ? navigator.languages : [navigator.language ?? ''];
const preferred = languages
    .map((tag) => String(tag).slice(0, 2).toLowerCase())
    .find((code) => code === 'fr' || code === 'en');
location.replace(`${preferred ?? 'fr'}/`);
