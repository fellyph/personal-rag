import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Notice,
	RangeControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { createElement, render, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, check, cloud, external, help, plugins, reset, update } from '@wordpress/icons';

const el = createElement;
const config = window.personalRagSettings || {};
const namespace = config.restNamespace || 'personal-rag/v1';
const emptySettings = {
	endpoint: 'http://localhost:11434',
	embeddingModel: 'embeddinggemma',
	chatModel: 'gemma4:e4b',
	topK: 5,
};

if (config.apiRoot && apiFetch.createRootURLMiddleware) {
	apiFetch.use(apiFetch.createRootURLMiddleware(config.apiRoot));
}
if (config.nonce && apiFetch.createNonceMiddleware) {
	apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));
}

function rest(path, options = {}) {
	return apiFetch({
		path: `/${namespace}${path}`,
		...options,
	});
}

function endpoint(settings) {
	return String(settings.endpoint || '').replace(/\/+$/, '');
}

function App() {
	const [settings, setSettings] = useState(emptySettings);
	const [draftSettings, setDraftSettings] = useState(emptySettings);
	const [aiStatus, setAiStatus] = useState(null);
	const [status, setStatus] = useState(null);
	const [notice, setNotice] = useState(null);
	const [isBusy, setIsBusy] = useState(false);
	const [progress, setProgress] = useState(null);
	const [question, setQuestion] = useState('');
	const [answer, setAnswer] = useState(null);

	const hasUnsavedSettings = useMemo(
		() => JSON.stringify(settings) !== JSON.stringify(draftSettings),
		[settings, draftSettings]
	);

	useEffect(() => {
		refreshAll();
	}, []);

	async function refreshAll() {
		setIsBusy(true);
		try {
			const [settingsResponse, indexStatus] = await Promise.all([
				rest('/settings'),
				rest('/status'),
			]);
			setSettings(settingsResponse.settings || emptySettings);
			setDraftSettings(settingsResponse.settings || emptySettings);
			setAiStatus(settingsResponse.ai || null);
			setStatus(indexStatus);
			setNotice(null);
		} catch (error) {
			setNotice({ status: 'error', message: error.message });
		} finally {
			setIsBusy(false);
		}
	}

	async function saveSettings() {
		setIsBusy(true);
		try {
			const response = await rest('/settings', {
				method: 'POST',
				data: draftSettings,
			});
			setSettings(response.settings || draftSettings);
			setDraftSettings(response.settings || draftSettings);
			setAiStatus(response.ai || aiStatus);
			setNotice({ status: 'success', message: __('Settings saved.', 'personal-rag') });
		} catch (error) {
			setNotice({ status: 'error', message: error.message });
		} finally {
			setIsBusy(false);
		}
	}

	async function refreshAiStatus() {
		setIsBusy(true);
		try {
			setAiStatus(await rest('/ai/status'));
			setNotice({ status: 'success', message: __('AI connector status refreshed.', 'personal-rag') });
		} catch (error) {
			setNotice({ status: 'error', message: error.message });
		} finally {
			setIsBusy(false);
		}
	}

	async function testOllamaEmbeddings() {
		setIsBusy(true);
		setNotice({ status: 'info', message: __('Testing Ollama embedding endpoint...', 'personal-rag') });
		try {
			const response = await fetch(`${endpoint(draftSettings)}/api/tags`);
			if (!response.ok) {
				throw new Error(
					sprintf(
						/* translators: %d: HTTP status code. */
						__('Ollama responded with status %d.', 'personal-rag'),
						response.status
					)
				);
			}
			const data = await response.json();
			const models = (data.models || []).map((model) => model.name);
			const missing = [draftSettings.embeddingModel].filter(
				(model) => !models.includes(model) && !models.includes(`${model}:latest`)
			);
			setNotice({
				status: missing.length ? 'warning' : 'success',
				message: missing.length
					? sprintf(
							/* translators: %s: Model names. */
							__('Ollama is reachable, but this embedding model was not listed: %s.', 'personal-rag'),
							missing.join(', ')
						)
					: __('Ollama is reachable and the embedding model is available.', 'personal-rag'),
			});
		} catch (error) {
			setNotice({ status: 'error', message: `${error.message} ${corsHint()}` });
		} finally {
			setIsBusy(false);
		}
	}

	async function queueIndex(force) {
		setIsBusy(true);
		try {
			const result = await rest('/index/queue', {
				method: 'POST',
				data: { force },
			});
			setStatus(result.status);
			setNotice({
				status: 'success',
				message: sprintf(
					/* translators: 1: Queued source count. 2: Unchanged source count. */
					__('Queued %1$d sources. %2$d unchanged.', 'personal-rag'),
					result.queued,
					result.unchanged
				),
			});
		} catch (error) {
			setNotice({ status: 'error', message: error.message });
		} finally {
			setIsBusy(false);
		}
	}

	async function rebuildEmbeddings() {
		setIsBusy(true);
		setProgress(0);
		setNotice({ status: 'info', message: __('Preparing local index...', 'personal-rag') });
		try {
			const queued = await rest('/index/queue', {
				method: 'POST',
				data: { force: true },
			});
			setStatus(queued.status);
			await embedQueuedChunks(queued.status?.queued || 0);
		} catch (error) {
			setNotice({ status: 'error', message: error.message });
		} finally {
			setProgress(null);
			setIsBusy(false);
		}
	}

	async function embedQueuedChunks(initialQueued) {
		let completed = 0;

		while (true) {
			const batch = await rest('/index/batch?limit=8');
			const items = batch.items || [];
			setStatus(batch.status || status);

			if (!items.length) {
				setProgress(100);
				setNotice({
					status: 'success',
					message: sprintf(
						/* translators: %d: Embedded chunk count. */
						__('Index is ready. Embedded %d chunks locally.', 'personal-rag'),
						completed
					),
				});
				return;
			}

			setNotice({
				status: 'info',
				message: sprintf(
					/* translators: 1: Chunk count. 2: Embedding model name. */
					__('Embedding %1$d chunks with %2$s...', 'personal-rag'),
					items.length,
					settings.embeddingModel
				),
			});

			const inputs = items.map(
				(item) => `title: ${item.title || __('Untitled', 'personal-rag')} | text: ${item.text}`
			);
			const embeddings = await embed(inputs, settings);
			const payload = items.map((item, index) => ({
				chunkId: item.id,
				vector: floatsToBase64(embeddings[index]),
				dimensions: embeddings[index].length,
			}));
			const saved = await rest('/index/embeddings', {
				method: 'POST',
				data: {
					model: settings.embeddingModel,
					items: payload,
				},
			});

			completed += saved.saved || items.length;
			setStatus(saved.status);
			if (initialQueued > 0) {
				setProgress(Math.min(99, Math.round((completed / initialQueued) * 100)));
			}
		}
	}

	async function resetIndex() {
		if (!window.confirm(__('Reset the local RAG index? Your posts and pages will not be deleted.', 'personal-rag'))) {
			return;
		}

		setIsBusy(true);
		try {
			const nextStatus = await rest('/reset', {
				method: 'POST',
				data: {},
			});
			setStatus(nextStatus);
			setAnswer(null);
			setNotice({ status: 'success', message: __('Index reset.', 'personal-rag') });
		} catch (error) {
			setNotice({ status: 'error', message: error.message });
		} finally {
			setIsBusy(false);
		}
	}

	async function askQuestion() {
		const trimmedQuestion = question.trim();
		if (!trimmedQuestion) {
			return;
		}

		setIsBusy(true);
		setAnswer({ loading: true });
		setNotice(null);
		try {
			const embeddings = await embed(`task: search result | query: ${trimmedQuestion}`, settings);
			const response = await rest('/answer', {
				method: 'POST',
				data: {
					question: trimmedQuestion,
					vector: floatsToBase64(embeddings[0]),
					topK: settings.topK,
				},
			});
			setAnswer(response);
			setAiStatus(response.ai || aiStatus);
		} catch (error) {
			setAnswer({ error: error.message });
		} finally {
			setIsBusy(false);
		}
	}

	function updateDraft(key, value) {
		setDraftSettings({
			...draftSettings,
			[key]: key === 'topK' ? Math.max(1, Math.min(12, parseInt(value || 5, 10))) : value,
		});
	}

	return el(
		'div',
		{ className: 'personal-rag-shell', 'data-testid': 'personal-rag-app' },
		el(Header, { isBusy, onRefresh: refreshAll }),
		notice &&
			el(Notice, {
				status: notice.status,
				isDismissible: false,
				className: 'personal-rag-notice',
			}, notice.message),
		el(AiSetupCard, { aiStatus, isBusy, onRefresh: refreshAiStatus }),
		el(SettingsCard, {
			canManage: config.canManageOptions,
			draftSettings,
			hasUnsavedSettings,
			isBusy,
			onChange: updateDraft,
			onSave: saveSettings,
			onTest: testOllamaEmbeddings,
		}),
		config.canManageOptions &&
			el(IndexCard, {
				status,
				progress,
				isBusy,
				onQueue: () => queueIndex(false),
				onRebuild: rebuildEmbeddings,
				onReset: resetIndex,
			}),
		el(AskCard, {
			aiStatus,
			answer,
			hasUnsavedSettings,
			isBusy,
			question,
			setQuestion,
			onAsk: askQuestion,
		})
	);
}

function Header({ isBusy, onRefresh }) {
	return el(
		'header',
		{ className: 'personal-rag-header' },
		el('div', null, el('h1', null, __('Personal RAG', 'personal-rag')), el('p', null, __('Local retrieval over WordPress content, configured through WordPress AI Connectors.', 'personal-rag'))),
		el(
			Button,
			{ icon: update, variant: 'secondary', onClick: onRefresh, disabled: isBusy, 'data-testid': 'personal-rag-refresh' },
			__('Refresh', 'personal-rag')
		)
	);
}

function AiSetupCard({ aiStatus, isBusy, onRefresh }) {
	const provider = aiStatus?.ollamaProvider || {};
	const ready = aiStatus?.textGenerationSupported;
	const statusText = ready
		? __('Ready for answers', 'personal-rag')
		: __('Needs connector setup', 'personal-rag');

	return el(
		Card,
		{ className: 'personal-rag-card personal-rag-card-ai' },
		el(CardHeader, null, el(SectionTitle, { icon: cloud, title: __('AI setup', 'personal-rag'), meta: statusText, good: ready })),
		el(
			CardBody,
			null,
			!ready &&
				el(Notice, { status: 'warning', isDismissible: false }, __('Answer generation requires WordPress AI Client text generation with a configured connector. Indexing and embeddings can still run.', 'personal-rag')),
			el(
				'div',
				{ className: 'personal-rag-connector-grid' },
				el(StatusPill, { label: __('AI Client', 'personal-rag'), active: aiStatus?.aiClientAvailable }),
				el(StatusPill, { label: __('AI support', 'personal-rag'), active: aiStatus?.aiSupported }),
				el(StatusPill, { label: __('Connectors API', 'personal-rag'), active: aiStatus?.connectorsAvailable }),
				el(StatusPill, { label: __('Ollama provider', 'personal-rag'), active: provider.active || provider.connectorRegistered })
			),
			el(
				'div',
				{ className: 'personal-rag-actions' },
				el(Button, { variant: 'secondary', icon: update, onClick: onRefresh, disabled: isBusy }, __('Check status', 'personal-rag')),
				aiStatus?.connectorsUrl &&
					el(ExternalLink, { href: aiStatus.connectorsUrl }, __('Open Settings > Connectors', 'personal-rag')),
				provider.active && aiStatus?.ollamaSettingsUrl
					? el(ExternalLink, { href: aiStatus.ollamaSettingsUrl }, __('Open Settings > Ollama', 'personal-rag'))
					: el(ExternalLink, { href: provider.installed ? provider.activateUrl : provider.installUrl }, provider.installed ? __('Activate AI Provider for Ollama', 'personal-rag') : __('Install AI Provider for Ollama', 'personal-rag'))
			),
			el('p', { className: 'personal-rag-help' }, __('API keys and remote provider credentials belong in Settings > Connectors. Personal RAG only stores local embedding preferences.', 'personal-rag'))
		)
	);
}

function SettingsCard({ canManage, draftSettings, hasUnsavedSettings, isBusy, onChange, onSave, onTest }) {
	return el(
		Card,
		{ className: 'personal-rag-card' },
		el(CardHeader, null, el(SectionTitle, { icon: plugins, title: __('Embedding configuration', 'personal-rag'), meta: __('Stored by Personal RAG', 'personal-rag') })),
		el(
			CardBody,
			null,
			el(
				'div',
				{ className: 'personal-rag-grid' },
				el(TextControl, {
					label: __('Ollama embedding endpoint', 'personal-rag'),
					value: draftSettings.endpoint,
					onChange: (value) => onChange('endpoint', value),
					disabled: !canManage || isBusy,
					placeholder: 'http://localhost:11434',
				}),
				el(TextControl, {
					label: __('Embedding model', 'personal-rag'),
					value: draftSettings.embeddingModel,
					onChange: (value) => onChange('embeddingModel', value),
					disabled: !canManage || isBusy,
					placeholder: 'embeddinggemma',
				}),
				el(TextControl, {
					label: __('Preferred chat model', 'personal-rag'),
					value: draftSettings.chatModel,
					onChange: (value) => onChange('chatModel', value),
					disabled: !canManage || isBusy,
					placeholder: 'gemma4:e4b',
				}),
				el(RangeControl, {
					label: __('Sources', 'personal-rag'),
					value: draftSettings.topK,
					min: 1,
					max: 12,
					onChange: (value) => onChange('topK', value),
					disabled: !canManage || isBusy,
				})
			),
			el(
				'div',
				{ className: 'personal-rag-actions' },
				canManage && el(Button, { variant: 'primary', onClick: onSave, disabled: isBusy || !hasUnsavedSettings }, __('Save settings', 'personal-rag')),
				el(Button, { variant: 'secondary', onClick: onTest, disabled: isBusy, 'data-testid': 'personal-rag-test-ollama' }, __('Test embeddings', 'personal-rag')),
				hasUnsavedSettings && el('span', { className: 'personal-rag-help' }, __('Save changes before rebuilding or asking.', 'personal-rag'))
			)
		)
	);
}

function IndexCard({ status, progress, isBusy, onQueue, onRebuild, onReset }) {
	return el(
		Card,
		{ className: 'personal-rag-card' },
		el(CardHeader, null, el(SectionTitle, { icon: update, title: __('Local index', 'personal-rag'), meta: __('Posts and pages', 'personal-rag') })),
		el(
			CardBody,
			null,
			el(
				'div',
				{ className: 'personal-rag-status', 'data-testid': 'personal-rag-status' },
				el(Stat, { label: __('Sources', 'personal-rag'), value: status?.sources }),
				el(Stat, { label: __('Chunks', 'personal-rag'), value: status?.chunks }),
				el(Stat, { label: __('Queued', 'personal-rag'), value: status?.queued }),
				el(Stat, { label: __('Embedded', 'personal-rag'), value: status?.embedded })
			),
			progress !== null && el('progress', { id: 'personal-rag-progress', value: progress, max: 100 }),
			el(
				'div',
				{ className: 'personal-rag-actions' },
				el(Button, { variant: 'secondary', onClick: onQueue, disabled: isBusy, 'data-testid': 'personal-rag-queue' }, __('Queue changed content', 'personal-rag')),
				el(Button, { variant: 'primary', onClick: onRebuild, disabled: isBusy, 'data-testid': 'personal-rag-rebuild' }, __('Rebuild embeddings', 'personal-rag')),
				el(Button, { variant: 'secondary', isDestructive: true, icon: reset, onClick: onReset, disabled: isBusy, 'data-testid': 'personal-rag-reset' }, __('Reset index', 'personal-rag'))
			)
		)
	);
}

function AskCard({ aiStatus, answer, hasUnsavedSettings, isBusy, question, setQuestion, onAsk }) {
	const canAsk = !hasUnsavedSettings && !!question.trim() && !isBusy;

	return el(
		Card,
		{ className: 'personal-rag-card' },
		el(CardHeader, null, el(SectionTitle, { icon: help, title: __('Ask your site', 'personal-rag'), meta: aiStatus?.textGenerationSupported ? __('Connector-backed answer generation', 'personal-rag') : __('Setup required for answers', 'personal-rag') })),
		el(
			CardBody,
			null,
			hasUnsavedSettings && el(Notice, { status: 'warning', isDismissible: false }, __('Save settings before asking so the server uses the same model preferences.', 'personal-rag')),
			el(TextareaControl, {
				label: __('Question', 'personal-rag'),
				id: 'personal-rag-question',
				value: question,
				onChange: setQuestion,
				disabled: isBusy,
				placeholder: __('Ask something covered by your posts or pages.', 'personal-rag'),
				rows: 4,
			}),
			el('div', { className: 'personal-rag-actions' }, el(Button, { variant: 'primary', onClick: onAsk, disabled: !canAsk, 'data-testid': 'personal-rag-ask' }, __('Ask', 'personal-rag'))),
			el(Answer, { answer })
		)
	);
}

function Answer({ answer }) {
	if (!answer) {
		return null;
	}

	if (answer.loading) {
		return el('div', { className: 'personal-rag-answer', 'data-testid': 'personal-rag-answer' }, el(Spinner), ' ', __('Searching local content...', 'personal-rag'));
	}

	if (answer.error) {
		return el('div', { className: 'personal-rag-answer personal-rag-error', 'data-testid': 'personal-rag-answer' }, answer.error);
	}

	return el(
		'div',
		{ className: 'personal-rag-answer', 'data-testid': 'personal-rag-answer' },
		el('div', { className: 'personal-rag-answer-text personal-rag-markdown', dangerouslySetInnerHTML: { __html: renderMarkdown(answer.answer) } }),
		el(Sources, { sources: answer.sources || [] })
	);
}

function Sources({ sources }) {
	return el(
		'div',
		{ className: 'personal-rag-sources' },
		el('h3', null, __('Sources', 'personal-rag')),
		sources.length
			? el(
					'ol',
					null,
					sources.map((source) =>
						el(
							'li',
							{ key: `${source.sourceId}-${source.chunkIndex}` },
							el('a', { href: source.url, target: '_blank', rel: 'noreferrer' }, source.title),
							el('span', null, sprintf(
								/* translators: %s: Search result score. */
								__('Score %s', 'personal-rag'),
								source.score
							))
						)
					)
				)
			: el('p', null, __('No local sources matched this question.', 'personal-rag'))
	);
}

function SectionTitle({ icon, title, meta, good }) {
	return el(
		'div',
		{ className: 'personal-rag-section-title' },
		el(Icon, { icon }),
		el('div', null, el('h2', null, title), meta && el('span', { className: good ? 'is-good' : '' }, meta))
	);
}

function StatusPill({ label, active }) {
	return el(
		'span',
		{ className: `personal-rag-pill ${active ? 'is-active' : 'is-inactive'}` },
		el(Icon, { icon: active ? check : external }),
		label
	);
}

function Stat({ label, value }) {
	return el('span', null, el('strong', null, Number.isFinite(value) ? value : 0), label);
}

function corsHint() {
	return sprintf(
		/* translators: %s: Current browser origin. */
		__('Start Ollama with OLLAMA_ORIGINS="%s" or add this origin to your existing OLLAMA_ORIGINS value.', 'personal-rag'),
		window.location.origin
	);
}

function embed(input, settings) {
	return fetch(`${endpoint(settings)}/api/embed`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({
			model: settings.embeddingModel,
			input,
		}),
	})
		.then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(
						text ||
							sprintf(
								/* translators: %d: HTTP status code. */
								__('Ollama embedding request failed with status %d.', 'personal-rag'),
								response.status
							)
					);
				});
			}
			return response.json();
		})
		.then((data) => {
			const embeddings = normalizeEmbeddings(data);
			if (!embeddings.length) {
				throw new Error(__('Ollama returned no embeddings.', 'personal-rag'));
			}
			return embeddings;
		});
}

function normalizeEmbeddings(data) {
	if (Array.isArray(data.embeddings)) {
		return data.embeddings;
	}
	if (Array.isArray(data.embedding)) {
		return [data.embedding];
	}
	if (Array.isArray(data.data)) {
		return data.data.map((item) => item.embedding).filter(Boolean);
	}
	return [];
}

function floatsToBase64(values) {
	const buffer = new ArrayBuffer(values.length * 4);
	const view = new DataView(buffer);
	values.forEach((value, index) => view.setFloat32(index * 4, value, true));
	let binary = '';
	new Uint8Array(buffer).forEach((byte) => {
		binary += String.fromCharCode(byte);
	});
	return window.btoa(binary);
}

function renderMarkdown(value) {
	const lines = String(value || __('No answer returned.', 'personal-rag')).replace(/\r\n?/g, '\n').split('\n');
	return lines
		.filter((line) => line.trim())
		.map((line) => {
			const heading = line.match(/^(#{1,4})\s+(.+)$/);
			if (heading) {
				const level = Math.min(4, heading[1].length + 1);
				return `<h${level}>${renderInlineMarkdown(heading[2])}</h${level}>`;
			}
			return `<p>${renderInlineMarkdown(line)}</p>`;
		})
		.join('');
}

function renderInlineMarkdown(value) {
	return escapeHtml(value)
		.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
		.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noreferrer">$1</a>')
		.replace(/`([^`]+)`/g, '<code>$1</code>');
}

function escapeHtml(value) {
	return String(value || '').replace(/[&<>"']/g, (char) => ({
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#039;',
	}[char]));
}

const root = document.getElementById('personal-rag-app');
if (root) {
	render(el(App), root);
}
