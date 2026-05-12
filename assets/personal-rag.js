(function () {
	'use strict';

	var config = window.personalRagSettings || {};
	var defaults = config.defaults || {};
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : {};
	var __ = i18n.__ || function (text) {
		return text;
	};
	var sprintf = i18n.sprintf || function (format) {
		var args = Array.prototype.slice.call(arguments, 1);
		var index = 0;
		return String(format).replace(/%(\d+\$)?[sd]/g, function (token, position) {
			if (position) {
				return args[parseInt(position, 10) - 1];
			}
			return args[index++];
		});
	};
	var state = {
		status: null,
		isBusy: false,
		settings: loadSettings(),
	};

	function loadSettings() {
		return {
			endpoint: localStorage.getItem('personalRag_endpoint') || defaults.endpoint || 'http://localhost:11434',
			embeddingModel: localStorage.getItem('personalRag_embeddingModel') || defaults.embeddingModel || 'embeddinggemma',
			chatModel: localStorage.getItem('personalRag_chatModel') || defaults.chatModel || 'gemma4:e4b',
			topK: parseInt(localStorage.getItem('personalRag_topK') || defaults.topK || '5', 10),
		};
	}

	function saveSettings() {
		localStorage.setItem('personalRag_endpoint', state.settings.endpoint);
		localStorage.setItem('personalRag_embeddingModel', state.settings.embeddingModel);
		localStorage.setItem('personalRag_chatModel', state.settings.chatModel);
		localStorage.setItem('personalRag_topK', String(state.settings.topK));
	}

	function endpoint() {
		return String(state.settings.endpoint || '').replace(/\/+$/, '');
	}

	function rest(path, options) {
		options = options || {};
		var headers = options.headers || {};
		headers['X-WP-Nonce'] = config.nonce;
		if (options.body && !headers['Content-Type']) {
			headers['Content-Type'] = 'application/json';
		}

		return fetch(config.restUrl + path, Object.assign({}, options, { headers: headers }))
			.then(function (response) {
				return response.json().catch(function () {
					return {};
					}).then(function (body) {
						if (!response.ok) {
							/* translators: %d: HTTP status code. */
							throw new Error(body.message || sprintf(__('Request failed with status %d', 'personal-rag'), response.status));
						}
						return body;
					});
			});
	}

	function ollamaFetch(path, body, stream) {
		return fetch(endpoint() + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body),
			}).then(function (response) {
				if (!response.ok) {
					return response.text().then(function (text) {
						/* translators: %d: HTTP status code. */
						throw new Error(text || sprintf(__('Ollama request failed with status %d', 'personal-rag'), response.status));
					});
				}
				return stream ? response : response.json();
			}).catch(function (error) {
				if (error instanceof TypeError) {
					throw new Error(__('Could not reach Ollama from this browser origin.', 'personal-rag') + ' ' + corsHint());
				}
				throw error;
			});
	}

	function corsHint() {
		/* translators: %s: Current browser origin. */
		return sprintf(__('Start Ollama with OLLAMA_ORIGINS="%s" or add this origin to your existing OLLAMA_ORIGINS value.', 'personal-rag'), window.location.origin);
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			}[char];
		});
	}

	function setBusy(isBusy) {
		state.isBusy = isBusy;
		document.querySelectorAll('#personal-rag-app button, #personal-rag-app input, #personal-rag-app textarea').forEach(function (el) {
			if (el.dataset.alwaysEnabled === '1') {
				return;
			}
			el.disabled = isBusy;
		});
	}

	function setNotice(message, type) {
		var notice = document.getElementById('personal-rag-notice');
		if (!notice) {
			return;
		}
		notice.className = 'personal-rag-notice personal-rag-notice-' + (type || 'info');
		notice.textContent = message || '';
		notice.hidden = !message;
	}

	function render() {
		var root = document.getElementById('personal-rag-app');
		if (!root) {
			return;
		}

		root.innerHTML = [
			'<div class="personal-rag-shell" data-testid="personal-rag-app">',
			'<header class="personal-rag-header">',
			'<div>',
			'<h1>' + escapeHtml(__('Personal RAG', 'personal-rag')) + '</h1>',
			'<p>' + escapeHtml(__('Ask questions against your local WordPress posts and pages using Ollama.', 'personal-rag')) + '</p>',
			'</div>',
			'<button type="button" class="button" id="personal-rag-refresh" data-testid="personal-rag-refresh">' + escapeHtml(__('Refresh', 'personal-rag')) + '</button>',
			'</header>',
			'<div id="personal-rag-notice" class="personal-rag-notice" hidden></div>',
			'<section class="personal-rag-band personal-rag-settings">',
			'<h2>' + escapeHtml(__('Local Models', 'personal-rag')) + '</h2>',
			'<div class="personal-rag-grid">',
			fieldHtml(__('Ollama endpoint', 'personal-rag'), 'endpoint', state.settings.endpoint, 'http://localhost:11434'),
			fieldHtml(__('Embedding model', 'personal-rag'), 'embeddingModel', state.settings.embeddingModel, 'embeddinggemma'),
			fieldHtml(__('Chat model', 'personal-rag'), 'chatModel', state.settings.chatModel, 'gemma4:e4b'),
			fieldHtml(__('Sources', 'personal-rag'), 'topK', state.settings.topK, '5', 'number'),
			'</div>',
			'<div class="personal-rag-actions">',
			'<button type="button" class="button" id="personal-rag-test" data-testid="personal-rag-test-ollama">' + escapeHtml(__('Test Ollama', 'personal-rag')) + '</button>',
			'<span class="personal-rag-help">' + sprintf(
				/* translators: 1: Embedding model command. 2: Chat model command. */
				escapeHtml(__('Required local models: %1$s and %2$s.', 'personal-rag')),
				'<code>ollama pull embeddinggemma</code>',
				'<code>ollama pull gemma4:e4b</code>'
			) + '</span>',
			'</div>',
			'</section>',
			config.canManageOptions ? indexHtml() : '',
			'<section class="personal-rag-band">',
			'<div class="personal-rag-chat-header">',
			'<h2>' + escapeHtml(__('Ask Your Site', 'personal-rag')) + '</h2>',
			'<span id="personal-rag-chat-state"></span>',
			'</div>',
			'<textarea id="personal-rag-question" rows="4" placeholder="' + escapeHtml(__('Ask something covered by your posts or pages.', 'personal-rag')) + '"></textarea>',
			'<div class="personal-rag-actions">',
			'<button type="button" class="button button-primary" id="personal-rag-ask" data-testid="personal-rag-ask">' + escapeHtml(__('Ask', 'personal-rag')) + '</button>',
			'</div>',
			'<div id="personal-rag-answer" class="personal-rag-answer" data-testid="personal-rag-answer" hidden></div>',
			'</section>',
			'</div>',
		].join('');

		bindEvents();
		renderStatus();
	}

	function fieldHtml(label, key, value, placeholder, type) {
		return '<label class="personal-rag-field">' +
			'<span>' + escapeHtml(label) + '</span>' +
			'<input type="' + (type || 'text') + '" data-setting="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '" placeholder="' + escapeHtml(placeholder) + '">' +
			'</label>';
	}

	function indexHtml() {
		return [
			'<section class="personal-rag-band">',
			'<div class="personal-rag-index-head">',
			'<h2>' + escapeHtml(__('Local Index', 'personal-rag')) + '</h2>',
			'<div id="personal-rag-status" class="personal-rag-status" data-testid="personal-rag-status"></div>',
			'</div>',
			'<div class="personal-rag-actions">',
			'<button type="button" class="button" id="personal-rag-queue" data-testid="personal-rag-queue">' + escapeHtml(__('Queue Changed Content', 'personal-rag')) + '</button>',
			'<button type="button" class="button button-primary" id="personal-rag-index" data-testid="personal-rag-rebuild">' + escapeHtml(__('Rebuild Embeddings', 'personal-rag')) + '</button>',
			'<button type="button" class="button" id="personal-rag-reset" data-testid="personal-rag-reset">' + escapeHtml(__('Reset Index', 'personal-rag')) + '</button>',
			'</div>',
			'<progress id="personal-rag-progress" value="0" max="100" hidden></progress>',
			'</section>',
		].join('');
	}

	function bindEvents() {
		document.getElementById('personal-rag-refresh').addEventListener('click', refreshStatus);
		document.getElementById('personal-rag-test').addEventListener('click', testOllama);
		document.getElementById('personal-rag-ask').addEventListener('click', askQuestion);

		document.querySelectorAll('[data-setting]').forEach(function (input) {
			input.addEventListener('change', function () {
				var key = input.dataset.setting;
				var value = input.type === 'number' ? parseInt(input.value || '5', 10) : input.value.trim();
				if (key === 'topK') {
					value = Math.max(1, Math.min(12, value || 5));
				}
				state.settings[key] = value;
				saveSettings();
			});
		});

		if (config.canManageOptions) {
			document.getElementById('personal-rag-queue').addEventListener('click', function () {
				queueIndex(false);
			});
			document.getElementById('personal-rag-index').addEventListener('click', rebuildEmbeddings);
			document.getElementById('personal-rag-reset').addEventListener('click', resetIndex);
		}
	}

	function refreshStatus() {
		return rest('/status').then(function (status) {
			state.status = status;
			renderStatus();
			return status;
		}).catch(function (error) {
			setNotice(error.message, 'error');
		});
	}

	function renderStatus() {
		var statusEl = document.getElementById('personal-rag-status');
		if (!statusEl) {
			return;
		}

		var status = state.status;
		if (!status) {
			statusEl.textContent = __('Loading status...', 'personal-rag');
			return;
		}

		statusEl.innerHTML = [
			statHtml(__('Sources', 'personal-rag'), status.sources),
			statHtml(__('Chunks', 'personal-rag'), status.chunks),
			statHtml(__('Queued', 'personal-rag'), status.queued),
			statHtml(__('Embedded', 'personal-rag'), status.embedded),
		].join('');
	}

	function statHtml(label, value) {
		return '<span><strong>' + escapeHtml(value) + '</strong>' + escapeHtml(label) + '</span>';
	}

	function testOllama() {
		setBusy(true);
		setNotice(__('Testing Ollama...', 'personal-rag'), 'info');
		return fetch(endpoint() + '/api/tags')
			.then(function (response) {
				if (!response.ok) {
					/* translators: %d: HTTP status code. */
					throw new Error(sprintf(__('Ollama responded with status %d', 'personal-rag'), response.status));
				}
				return response.json();
			})
			.then(function (data) {
				var models = (data.models || []).map(function (model) {
					return model.name;
				});
				var missing = [state.settings.embeddingModel, state.settings.chatModel].filter(function (model) {
					return models.indexOf(model) === -1 && models.indexOf(model + ':latest') === -1;
				});
				if (missing.length) {
					/* translators: %s: Comma-separated model names. */
					setNotice(sprintf(__('Ollama is reachable, but these models were not listed: %s.', 'personal-rag'), missing.join(', ')), 'warning');
				} else {
					setNotice(__('Ollama is reachable and the configured models are available.', 'personal-rag'), 'success');
				}
			})
			.catch(function (error) {
				if (error instanceof TypeError) {
					setNotice(__('Could not reach Ollama.', 'personal-rag') + ' ' + corsHint(), 'error');
				} else {
					setNotice(error.message, 'error');
				}
			})
			.finally(function () {
				setBusy(false);
			});
	}

	function queueIndex(force) {
		setBusy(true);
		setNotice(force ? __('Rebuilding local content chunks...', 'personal-rag') : __('Queueing changed content...', 'personal-rag'), 'info');
		return rest('/index/queue', {
			method: 'POST',
			body: JSON.stringify({ force: !!force }),
		}).then(function (result) {
			state.status = result.status;
			renderStatus();
			/* translators: 1: Queued source count. 2: Unchanged source count. */
			setNotice(sprintf(__('Queued %1$d sources. %2$d unchanged.', 'personal-rag'), result.queued, result.unchanged), 'success');
			return result;
		}).catch(function (error) {
			setNotice(error.message, 'error');
		}).finally(function () {
			setBusy(false);
		});
	}

	function rebuildEmbeddings() {
		setBusy(true);
		setProgress(0);
		setNotice(__('Preparing local index...', 'personal-rag'), 'info');

		return rest('/index/queue', {
			method: 'POST',
			body: JSON.stringify({ force: true }),
		}).then(function (result) {
			state.status = result.status;
			renderStatus();
			return embedQueuedChunks();
		}).catch(function (error) {
			setNotice(error.message, 'error');
		}).finally(function () {
			setProgress(null);
			setBusy(false);
		});
	}

	function embedQueuedChunks() {
		var initialQueued = state.status ? state.status.queued : 0;
		var completed = 0;

		function nextBatch() {
			return rest('/index/batch?limit=8').then(function (batch) {
				var items = batch.items || [];
				state.status = batch.status || state.status;
				renderStatus();

				if (!items.length) {
					/* translators: %d: Embedded chunk count. */
					setNotice(sprintf(__('Index is ready. Embedded %d chunks locally.', 'personal-rag'), completed), 'success');
					setProgress(100);
					return null;
				}

				var inputs = items.map(function (item) {
					return 'title: ' + (item.title || __('Untitled', 'personal-rag')) + ' | text: ' + item.text;
				});

				/* translators: 1: Chunk count. 2: Embedding model name. */
				setNotice(sprintf(__('Embedding %1$d chunks with %2$s...', 'personal-rag'), items.length, state.settings.embeddingModel), 'info');
				return embed(inputs).then(function (embeddings) {
					var payload = items.map(function (item, index) {
						return {
							chunkId: item.id,
							vector: floatsToBase64(embeddings[index]),
							dimensions: embeddings[index].length,
						};
					});

					return rest('/index/embeddings', {
						method: 'POST',
						body: JSON.stringify({
							model: state.settings.embeddingModel,
							items: payload,
						}),
					});
				}).then(function (result) {
					completed += result.saved || items.length;
					state.status = result.status;
					renderStatus();
					if (initialQueued > 0) {
						setProgress(Math.min(99, Math.round((completed / initialQueued) * 100)));
					}
					return nextBatch();
				});
			});
		}

		return nextBatch();
	}

	function setProgress(value) {
		var progress = document.getElementById('personal-rag-progress');
		if (!progress) {
			return;
		}
		if (value === null) {
			progress.hidden = true;
			progress.value = 0;
			return;
		}
		progress.hidden = false;
		progress.value = value;
	}

	function embed(input) {
		return ollamaFetch('/api/embed', {
			model: state.settings.embeddingModel,
			input: input,
		}).then(function (data) {
			var embeddings = normalizeEmbeddings(data);
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
			return data.data.map(function (item) {
				return item.embedding;
			}).filter(Boolean);
		}
		return [];
	}

	function resetIndex() {
		if (!window.confirm(__('Reset the local RAG index? Your posts and pages will not be deleted.', 'personal-rag'))) {
			return;
		}

		setBusy(true);
		setNotice(__('Resetting index...', 'personal-rag'), 'info');
		return rest('/reset', {
			method: 'POST',
			body: JSON.stringify({}),
		}).then(function (status) {
			state.status = status;
			renderStatus();
			setNotice(__('Index reset.', 'personal-rag'), 'success');
		}).catch(function (error) {
			setNotice(error.message, 'error');
		}).finally(function () {
			setBusy(false);
		});
	}

	function askQuestion() {
		var questionEl = document.getElementById('personal-rag-question');
		var question = questionEl.value.trim();
		if (!question) {
			questionEl.focus();
			return;
		}

		var answer = document.getElementById('personal-rag-answer');
		answer.hidden = false;
		answer.innerHTML = '<div class="personal-rag-loading">' + escapeHtml(__('Searching local content...', 'personal-rag')) + '</div>';
		setBusy(true);
		setNotice('', 'info');

		return embed('task: search result | query: ' + question)
			.then(function (embeddings) {
				return rest('/search', {
					method: 'POST',
					body: JSON.stringify({
						vector: floatsToBase64(embeddings[0]),
						model: state.settings.embeddingModel,
						topK: state.settings.topK,
					}),
				});
			})
			.then(function (search) {
				return streamAnswer(question, search.matches || []);
			})
			.catch(function (error) {
				answer.innerHTML = '<div class="personal-rag-error">' + escapeHtml(error.message) + '</div>';
			})
			.finally(function () {
				setBusy(false);
			});
	}

	function streamAnswer(question, matches) {
		var answer = document.getElementById('personal-rag-answer');
		var messages = buildMessages(question, matches);
		var sourceHtml = renderSources(matches);

		answer.innerHTML = '<div class="personal-rag-answer-text personal-rag-markdown"></div>' + sourceHtml;
		var textEl = answer.querySelector('.personal-rag-answer-text');
		var content = '';

		return ollamaFetch('/api/chat', {
			model: state.settings.chatModel,
			stream: true,
			messages: messages,
			options: {
				temperature: 0.2,
			},
		}, true).then(function (response) {
			var reader = response.body.getReader();
			var decoder = new TextDecoder();
			var buffer = '';

			function pump() {
				return reader.read().then(function (result) {
					if (result.done) {
						textEl.innerHTML = formatAnswer(stripThinking(content));
						return;
					}
					buffer += decoder.decode(result.value, { stream: true });
					var lines = buffer.split('\n');
					buffer = lines.pop();
					lines.forEach(function (line) {
						if (!line.trim()) {
							return;
						}
						try {
							var chunk = JSON.parse(line);
							if (chunk.message && chunk.message.content) {
								content += chunk.message.content;
								textEl.innerHTML = formatAnswer(stripThinking(content));
							}
						} catch (error) {}
					});
					return pump();
				});
			}

			return pump();
		});
	}

	function buildMessages(question, matches) {
		var system = [
			__('You are a private RAG assistant running inside WordPress.', 'personal-rag'),
			__('Answer only from the provided local WordPress sources.', 'personal-rag'),
			__('Use citations like [1], [2] for claims.', 'personal-rag'),
			__('If the sources do not contain enough evidence, say that clearly and do not guess.', 'personal-rag'),
		].join(' ');

		var sources = matches.length ? matches.map(function (match, index) {
			return '[' + (index + 1) + '] ' + match.title + '\nURL: ' + match.url + '\nText: ' + match.text;
		}).join('\n\n') : __('No matching local sources were found.', 'personal-rag');

		return [
			{ role: 'system', content: system },
			/* translators: 1: User question. 2: Newline separator. 3: Local sources text. */
			{ role: 'user', content: sprintf(__('Question: %1$s%2$sLocal sources:%2$s%3$s', 'personal-rag'), question, '\n\n', sources) },
		];
	}

	function renderSources(matches) {
		if (!matches.length) {
			return '<div class="personal-rag-sources"><h3>' + escapeHtml(__('Sources', 'personal-rag')) + '</h3><p>' + escapeHtml(__('No local sources matched this question.', 'personal-rag')) + '</p></div>';
		}

		return '<div class="personal-rag-sources"><h3>' + escapeHtml(__('Sources', 'personal-rag')) + '</h3><ol>' + matches.map(function (match) {
			/* translators: %s: Search result score. */
			return '<li><a href="' + escapeHtml(match.url) + '" target="_blank" rel="noreferrer">' + escapeHtml(match.title) + '</a><span>' + escapeHtml(sprintf(__('Score %s', 'personal-rag'), match.score)) + '</span></li>';
		}).join('') + '</ol></div>';
	}

	function formatAnswer(value) {
		return renderMarkdown(value || __('No answer returned.', 'personal-rag'));
	}

	function renderMarkdown(value) {
		var lines = String(value || '').replace(/\r\n?/g, '\n').split('\n');
		var html = [];
		var index = 0;

		function isBlank(line) {
			return !line || !line.trim();
		}

		function isBlockStart(line) {
			return /^#{1,4}\s+/.test(line) ||
				/^```/.test(line) ||
				/^\s*[-*+]\s+/.test(line) ||
				/^\s*\d+[.)]\s+/.test(line) ||
				/^>\s?/.test(line);
		}

		while (index < lines.length) {
			var line = lines[index];

			if (isBlank(line)) {
				index++;
				continue;
			}

			var fence = line.match(/^```\s*([A-Za-z0-9_-]+)?\s*$/);
			if (fence) {
				var codeLines = [];
				index++;
				while (index < lines.length && !/^```\s*$/.test(lines[index])) {
					codeLines.push(lines[index]);
					index++;
				}
				if (index < lines.length) {
					index++;
				}
				html.push('<pre><code>' + escapeHtml(codeLines.join('\n')) + '</code></pre>');
				continue;
			}

			var heading = line.match(/^(#{1,4})\s+(.+)$/);
			if (heading) {
				var level = Math.min(4, heading[1].length + 1);
				html.push('<h' + level + '>' + renderInlineMarkdown(heading[2]) + '</h' + level + '>');
				index++;
				continue;
			}

			if (/^>\s?/.test(line)) {
				var quoteLines = [];
				while (index < lines.length && /^>\s?/.test(lines[index])) {
					quoteLines.push(lines[index].replace(/^>\s?/, ''));
					index++;
				}
				html.push('<blockquote>' + renderMarkdown(quoteLines.join('\n')) + '</blockquote>');
				continue;
			}

			if (/^\s*[-*+]\s+/.test(line)) {
				html.push(renderList(lines, index, false));
				while (index < lines.length && /^\s*[-*+]\s+/.test(lines[index])) {
					index++;
				}
				continue;
			}

			if (/^\s*\d+[.)]\s+/.test(line)) {
				html.push(renderList(lines, index, true));
				while (index < lines.length && /^\s*\d+[.)]\s+/.test(lines[index])) {
					index++;
				}
				continue;
			}

			var paragraph = [line.trim()];
			index++;
			while (index < lines.length && !isBlank(lines[index]) && !isBlockStart(lines[index])) {
				paragraph.push(lines[index].trim());
				index++;
			}
			html.push('<p>' + renderInlineMarkdown(paragraph.join(' ')) + '</p>');
		}

		return html.join('');
	}

	function renderList(lines, start, ordered) {
		var items = [];
		var pattern = ordered ? /^\s*\d+[.)]\s+(.+)$/ : /^\s*[-*+]\s+(.+)$/;
		var index = start;

		while (index < lines.length) {
			var match = lines[index].match(pattern);
			if (!match) {
				break;
			}
			items.push('<li>' + renderInlineMarkdown(match[1]) + '</li>');
			index++;
		}

		return '<' + (ordered ? 'ol' : 'ul') + '>' + items.join('') + '</' + (ordered ? 'ol' : 'ul') + '>';
	}

	function renderInlineMarkdown(value) {
		var html = escapeHtml(value);
		var codeSpans = [];

		html = html.replace(/`([^`]+)`/g, function (match, code) {
			var token = '\u0000CODE' + codeSpans.length + '\u0000';
			codeSpans.push('<code>' + code + '</code>');
			return token;
		});

		html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, function (match, label, url) {
			return '<a href="' + url + '" target="_blank" rel="noreferrer">' + label + '</a>';
		});
		html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');
		html = html.replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>');
		html = html.replace(/(^|[\s(])_([^_\n]+)_/g, '$1<em>$2</em>');

		codeSpans.forEach(function (code, codeIndex) {
			html = html.replace('\u0000CODE' + codeIndex + '\u0000', code);
		});

		return html;
	}

	function stripThinking(value) {
		return String(value || '')
			.replace(/<\|channel\>thought[\s\S]*?<channel\|>/g, '')
			.replace(/<think>[\s\S]*?<\/think>/g, '')
			.trim();
	}

	function floatsToBase64(values) {
		if (!Array.isArray(values) && !(values instanceof Float32Array)) {
			throw new Error(__('Invalid embedding vector.', 'personal-rag'));
		}
		var floats = values instanceof Float32Array ? values : new Float32Array(values);
		var bytes = new Uint8Array(floats.buffer);
		var binary = '';
		var chunkSize = 0x8000;
		for (var i = 0; i < bytes.length; i += chunkSize) {
			var chunk = bytes.subarray(i, i + chunkSize);
			binary += String.fromCharCode.apply(null, chunk);
		}
		return btoa(binary);
	}

	document.addEventListener('DOMContentLoaded', function () {
		render();
		refreshStatus();
	});
})();
