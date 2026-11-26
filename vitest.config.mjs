// Unit tests of the browser JavaScript (assets/js), in a simulated DOM (jsdom). Node is only used for the tools.
import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.mjs'],
        reporters: ['default', 'junit'],
        outputFile: { junit: 'reports/js-junit.xml' },
    },
});
