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
	await expect(page.getByText('Ready for answers')).toBeVisible();
	await expect(page.getByLabel('Ollama embedding endpoint')).toHaveValue('http://localhost:11434');

	await page.getByTestId('personal-rag-test-ollama').click();
	await expect(page.getByTestId('personal-rag-app').getByText('Ollama is reachable and the embedding model is available.')).toBeVisible();
});

test('admin can save connector-aware Personal RAG settings', async ({ page }) => {
	await openPersonalRag(page);

	await page.getByLabel('Preferred chat model').fill('llama3.2');
	await page.getByRole('button', { name: 'Save settings' }).click();

	await expect(page.getByTestId('personal-rag-app').getByText('Settings saved.')).toBeVisible();
	await expect(page.getByLabel('Preferred chat model')).toHaveValue('llama3.2');
});

test('rebuilds embeddings and answers from mocked local sources', async ({ page }) => {
	await openPersonalRag(page);

	await page.getByTestId('personal-rag-rebuild').click();
	await expect(page.getByTestId('personal-rag-app').getByText(/Index is ready\. Embedded \d+ chunks locally\./)).toBeVisible({
		timeout: 120_000,
	});
	await expect(page.getByTestId('personal-rag-status')).toContainText('Embedded');

	await page.locator('#personal-rag-question').fill('How do I use WordPress Playground Blueprints?');
	await page.getByTestId('personal-rag-ask').click();

	await expect(page.getByTestId('personal-rag-answer')).toContainText('Blueprints use JSON configuration');
	await expect(page.getByTestId('personal-rag-answer')).toContainText('Sources');
	await expect(page.getByTestId('personal-rag-answer').locator('ol li').first()).toBeVisible();
});

test('reader can open the app but cannot manage the local index', async ({ page }) => {
	await loginAsSubscriber(page, cli.serverUrl);
	await page.goto(`${cli.serverUrl}/wp-admin/tools.php?page=personal-rag`);

	await expect(page.getByTestId('personal-rag-app')).toBeVisible();
	await expect(page.getByTestId('personal-rag-rebuild')).toHaveCount(0);
	await expect(page.getByTestId('personal-rag-reset')).toHaveCount(0);

	const status = await page.evaluate(async () => {
		const response = await fetch(
			`${window.personalRagSettings.apiRoot}${window.personalRagSettings.restNamespace}/index/queue`,
			{
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.personalRagSettings.nonce,
			},
			body: JSON.stringify({ force: true }),
			}
		);
		return response.status;
	});

	expect(status).toBe(403);
});

async function openPersonalRag(page) {
	await page.goto(cli.serverUrl);
	await page.goto(`${cli.serverUrl}/wp-admin/tools.php?page=personal-rag`);
}
