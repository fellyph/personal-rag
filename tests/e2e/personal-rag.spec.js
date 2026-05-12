import { expect, test } from '@playwright/test';
import { mockOllama, startPersonalRagPlayground, loginAsSubscriber } from './support/playground.js';

let cli;

test.beforeAll(async () => {
	cli = await startPersonalRagPlayground();
});

test.afterAll(async () => {
	if (cli?.[Symbol.asyncDispose]) {
		await cli[Symbol.asyncDispose]();
		return;
	}
	await cli?.server?.close();
});

test.beforeEach(async ({ page }) => {
	await mockOllama(page);
});

test('admin app renders and can reach mocked Ollama', async ({ page }) => {
	await openPersonalRag(page);

	await expect(page.getByTestId('personal-rag-app')).toBeVisible();
	await expect(page.getByRole('heading', { name: 'Personal RAG' })).toBeVisible();
	await expect(page.getByLabel('Ollama endpoint')).toHaveValue('http://localhost:11434');

	await page.getByTestId('personal-rag-test-ollama').click();
	await expect(page.getByText('Ollama is reachable and the configured models are available.')).toBeVisible();
});

test('rebuilds embeddings and answers from mocked local sources', async ({ page }) => {
	await openPersonalRag(page);

	await page.getByTestId('personal-rag-rebuild').click();
	await expect(page.getByText(/Index is ready\. Embedded \d+ chunks locally\./)).toBeVisible({
		timeout: 120_000,
	});
	await expect(page.getByTestId('personal-rag-status')).toContainText('Embedded');

	await page.getByLabel('Sources').fill('3');
	await page.locator('#personal-rag-question').fill('How do I use WordPress Playground Blueprints?');
	await page.getByTestId('personal-rag-ask').click();

	await expect(page.getByTestId('personal-rag-answer')).toContainText('Blueprints use JSON configuration');
	await expect(page.getByTestId('personal-rag-answer')).toContainText('Sources');
	await expect(page.getByTestId('personal-rag-answer').locator('ol li')).toHaveCount(3);
});

test('reader can open the app but cannot manage the local index', async ({ page }) => {
	await loginAsSubscriber(page, cli.serverUrl);
	await page.goto(`${cli.serverUrl}/wp-admin/tools.php?page=personal-rag`);

	await expect(page.getByTestId('personal-rag-app')).toBeVisible();
	await expect(page.getByTestId('personal-rag-rebuild')).toHaveCount(0);
	await expect(page.getByTestId('personal-rag-reset')).toHaveCount(0);

	const status = await page.evaluate(async () => {
		const response = await fetch(`${window.personalRagSettings.restUrl}/index/queue`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.personalRagSettings.nonce,
			},
			body: JSON.stringify({ force: true }),
		});
		return response.status;
	});

	expect(status).toBe(403);
});

async function openPersonalRag(page) {
	await page.goto(cli.serverUrl);
	await page.goto(`${cli.serverUrl}/wp-admin/tools.php?page=personal-rag`);
}
