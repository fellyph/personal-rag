import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export const projectRoot = path.resolve(__dirname, '../../..');
export const pluginPath = '/wordpress/wp-content/plugins/personal-rag';
export const wxrFixturePath = path.join(projectRoot, 'tests/fixtures/playground-docs.wxr.xml');
export const runtimePluginRoot = path.join(projectRoot, '.tmp/playground-plugin/personal-rag');

export function createPersonalRagBlueprint() {
	const wxrContents = fs.readFileSync(wxrFixturePath, 'utf8');

	return {
		$schema: 'https://playground.wordpress.net/blueprint-schema.json',
		landingPage: '/wp-admin/tools.php?page=personal-rag',
		preferredVersions: {
			php: '8.3',
			wp: 'latest',
		},
		login: true,
		steps: [
			{
				step: 'setSiteOptions',
				options: {
					blogname: 'Personal RAG E2E',
					permalink_structure: '/%postname%/',
				},
			},
			{
				step: 'activatePlugin',
				pluginPath: 'personal-rag/personal-rag.php',
			},
			{
				step: 'importWxr',
				file: {
					resource: 'literal',
					name: 'playground-docs.wxr.xml',
					contents: wxrContents,
				},
			},
			{
				step: 'runPHP',
				code: `<?php
require '/wordpress/wp-load.php';
$user_id = username_exists( 'reader' );
if ( ! $user_id ) {
	$user_id = wp_create_user( 'reader', 'password', 'reader@example.com' );
}
$user = new WP_User( $user_id );
$user->set_role( 'subscriber' );
`,
			},
		],
	};
}

export async function startPersonalRagPlayground() {
	prepareRuntimePluginMirror();

	const cli = await runCLI({
		command: 'server',
		mount: [
			{
				hostPath: runtimePluginRoot,
				vfsPath: pluginPath,
			},
		],
		blueprint: createPersonalRagBlueprint(),
	});
	const address = cli.server.address();
	const port = typeof address === 'object' && address ? address.port : 9400;

	return {
		...cli,
		serverUrl: `http://127.0.0.1:${port}`,
	};
}

function prepareRuntimePluginMirror() {
	fs.rmSync(path.dirname(runtimePluginRoot), { recursive: true, force: true });
	fs.mkdirSync(runtimePluginRoot, { recursive: true });

	for (const file of ['personal-rag.php', 'uninstall.php', 'readme.txt']) {
		fs.copyFileSync(path.join(projectRoot, file), path.join(runtimePluginRoot, file));
	}

	for (const directory of ['assets', 'includes', 'languages']) {
		const source = path.join(projectRoot, directory);
		const destination = path.join(runtimePluginRoot, directory);
		if (fs.existsSync(source)) {
			fs.cpSync(source, destination, { recursive: true });
		}
	}
}

export async function mockOllama(page) {
	const corsHeaders = {
		'access-control-allow-origin': '*',
		'access-control-allow-methods': 'GET,POST,OPTIONS',
		'access-control-allow-headers': 'content-type',
	};

	await page.route('http://localhost:11434/api/tags', async (route) => {
		await route.fulfill({
			status: 200,
			headers: corsHeaders,
			contentType: 'application/json',
			body: JSON.stringify({
				models: [
					{ name: 'embeddinggemma:latest' },
					{ name: 'gemma4:e4b' },
				],
			}),
		});
	});

	await page.route('http://localhost:11434/api/embed', async (route) => {
		if (route.request().method() === 'OPTIONS') {
			await route.fulfill({ status: 204, headers: corsHeaders });
			return;
		}

		const payload = route.request().postDataJSON();
		const input = Array.isArray(payload.input) ? payload.input : [payload.input || ''];

		await route.fulfill({
			status: 200,
			headers: corsHeaders,
			contentType: 'application/json',
			body: JSON.stringify({
				embeddings: input.map((value) => vectorForInput(value)),
			}),
		});
	});

	await page.route('http://localhost:11434/api/chat', async (route) => {
		if (route.request().method() === 'OPTIONS') {
			await route.fulfill({ status: 204, headers: corsHeaders });
			return;
		}

		const chunks = [
			{ message: { content: 'Blueprints use JSON configuration to set up WordPress Playground instances [1].' }, done: false },
			{ done: true },
		];

		await route.fulfill({
			status: 200,
			headers: corsHeaders,
			contentType: 'application/x-ndjson',
			body: chunks.map((chunk) => JSON.stringify(chunk)).join('\n') + '\n',
		});
	});
}

export async function loginAsSubscriber(page, baseUrl) {
	await page.goto(baseUrl);
	await page.goto(`${baseUrl}/wp-login.php`);

	if ((await page.locator('#user_login').count()) === 0) {
		await page.goto(`${baseUrl}/wp-login.php?action=logout`);
		const logoutLink = page.getByRole('link', { name: /log out/i });
		if (await logoutLink.count()) {
			await logoutLink.click();
		}
		await page.goto(`${baseUrl}/wp-login.php`);
	}

	await page.locator('#user_login').fill('reader');
	await page.locator('#user_pass').fill('password');
	await page.locator('#wp-submit').click();
	await page.waitForURL(/wp-admin|profile\.php|dashboard/, { timeout: 30_000 }).catch(() => {});
}

function vectorForInput(value) {
	const normalized = String(value || '').toLowerCase();

	if (normalized.includes('xdebug')) {
		return [0, 1, 0];
	}

	if (normalized.includes('service worker')) {
		return [0, 0, 1];
	}

	return [1, 0, 0];
}
