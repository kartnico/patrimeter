import { readFile } from 'node:fs/promises';
import { expect, logIn, seededUser, test } from './support/helpers.mjs';

// Width and height of a PNG, read from its IHDR chunk.
const pngSize = (bytes) => ({ width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) });

test("rapport : la carte occupe l'écran du téléphone et son image s'enregistre", async ({ page }) => {
    await logIn(page, seededUser(14));
    await page.goto('/rapport');

    const card = await page.locator('[data-report-card]').boundingBox();
    expect(card.x).toBe(0);
    expect(card.width).toBeGreaterThanOrEqual(389);
    expect(card.height).toBeGreaterThan(844 - 100);
    // The first share button shows below the card without scrolling.
    const save = page.getByRole('button', { name: "Enregistrer l'image" });
    await expect(save).toBeInViewport();

    const [download] = await Promise.all([page.waitForEvent('download'), save.click()]);
    expect(download.suggestedFilename()).toMatch(/^patrimeter-\d{4}-\d{2}\.png$/);
    const bytes = await readFile(await download.path());
    expect(bytes.subarray(1, 4).toString()).toBe('PNG');
    expect(pngSize(bytes)).toEqual({ width: 1080, height: 1350 });
    await expect(page.getByRole('status')).toContainText('Image enregistrée');
});

test('rapport : Mastodon et X reçoivent le texte, sans montant', async ({ page, context }) => {
    await logIn(page, seededUser(15));
    await context.route(/^https:\/\/(mastodon\.example|x\.com)\//, (route) => route.fulfill({ body: 'ok' }));
    await page.goto('/rapport?montants=0');
    const text = await page.locator('[data-report-share]').getAttribute('data-share-text');
    expect(text).not.toContain('€');

    await page.getByRole('button', { name: 'Mastodon' }).click();
    const instance = page.getByLabel('Votre instance Mastodon');
    await instance.fill('mastodon');
    await page.getByRole('button', { name: 'Publier', exact: true }).click();
    await expect(instance).toHaveAttribute('aria-invalid', 'true');

    await instance.fill('@moi@Mastodon.Example');
    const [mastodon] = await Promise.all([
        context.waitForEvent('page'),
        page.getByRole('button', { name: 'Publier', exact: true }).click(),
    ]);
    await expect.poll(() => mastodon.url()).toBe(`https://mastodon.example/share?text=${encodeURIComponent(text)}`);
    await mastodon.close();

    const [x] = await Promise.all([
        context.waitForEvent('page'),
        page.getByRole('button', { name: 'X (Twitter)' }).click(),
    ]);
    await expect.poll(() => x.url()).toBe(`https://x.com/intent/post?text=${encodeURIComponent(text)}`);
});
