(function (wp, $) {
	// --- IMPORTS ---
	const el = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const render = wp.element.render;
	const registerPlugin = wp.plugins.registerPlugin;
	const registerFormatType = wp.richText.registerFormatType;
	const applyFormat = wp.richText.applyFormat;
	const RichTextToolbarButton = wp.blockEditor.RichTextToolbarButton;
	const PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	const Modal = wp.components.Modal;
	const Button = wp.components.Button;
	const TextControl = wp.components.TextControl;
	const SelectControl = wp.components.SelectControl;
	const CheckboxControl = wp.components.CheckboxControl;
	const withSelect = wp.data.withSelect;
	const withDispatch = wp.data.withDispatch;
	const compose = wp.compose.compose;
	const useState = wp.element.useState;
	const useEffect = wp.element.useEffect;
	const useRef = wp.element.useRef;

	// --- HELPERS ---
	function escapeRegExp(string) {
		return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	// Decode HTML entities (e.g., &#8217; -> ')
	function decodeHTML(html) {
		if (!html) return html;
		const txt = document.createElement('textarea');
		txt.innerHTML = html;
		return txt.value;
	}

	// Strip Gutenberg block comments but keep HTML tags
	function stripGutenbergComments(html) {
		if (!html) return html;
		// Matches <!-- wp:something ... --> and <!-- /wp:something -->
		return html.replace(/<!--\s*\/?wp:.*?\s*-->/g, '');
	}

	// Create a regex pattern that handles keywords with or without special characters
	function createKeywordRegex(keyword) {
		const escaped = escapeRegExp(keyword);
		// Check if keyword contains only word characters (letters, digits, underscore)
		const isWordOnly = /^[\w]+$/.test(keyword);

		if (isWordOnly) {
			// For word-only keywords, use word boundaries
			// Pattern: match keyword with word boundaries, but not inside HTML tags
			// Use 'i' flag only (no 'g') to match first occurrence only.
			return new RegExp('([^>\\w]|^)(\\b' + escaped + '\\b)(?![\\w-])(?![^<]*>)', 'i');
		} else {
			// For keywords with special chars (like "llms.txt"), match exactly without word boundaries
			// Pattern: match keyword, ensuring we're not inside HTML tags
			return new RegExp('([^>\\S]|^|>)(' + escaped + ')(?![^<]*>)', 'i');
		}
	}

	// Recursively flatten blocks to include inner blocks (e.g., list items inside core/list)
	function flattenBlocks(blocks) {
		let result = [];
		blocks.forEach(function (block) {
			result.push(block);
			if (block.innerBlocks && block.innerBlocks.length > 0) {
				result = result.concat(flattenBlocks(block.innerBlocks));
			}
		});
		return result;
	}

	function getAgeOptions() {
		const opts = [{ label: 'All', value: 'all' }];
		const currentYear = new Date().getFullYear();
		for (let i = 1; i <= 5; i++) {
			const years = [];
			for (let j = 0; j <= i; j++) { years.push(currentYear - j); }
			opts.push({ label: 'Up to ' + i + ' year(s) old', value: years.join(',') });
		}
		return opts;
	}

	// Scroll sidebar to matching suggestion when a highlighted mark is clicked in the editor
	function attachMarkClickListeners() {
		const editorIframe = document.querySelector('iframe[name="editor-canvas"]');
		const contextDoc = editorIframe ? editorIframe.contentDocument : document;
		if (!contextDoc) return;

		const marks = contextDoc.querySelectorAll('mark.ilsej-highlight');
		marks.forEach(function (mark) {
			// Avoid attaching duplicate listeners
			if (mark.dataset.ilsejListenerAttached) return;
			mark.dataset.ilsejListenerAttached = 'true';

			mark.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				const keyword = mark.textContent.trim().toLowerCase();

				// Switch sidebar to Post tab (clicking in editor switches to Block tab)
				const editPost = wp.data.dispatch('core/edit-post');
				if (editPost && editPost.openGeneralSidebar) {
					editPost.openGeneralSidebar('edit-post/document');
				}

				// Small delay to let the sidebar tab switch render before scrolling
				setTimeout(function () {
					// Iterate instead of querySelector to handle special chars in keywords
					const allSuggestions = document.querySelectorAll('.ilsej-suggestion-list [data-ilsej-keyword]');
					let suggestionEl = null;
					allSuggestions.forEach(function (el) {
						if (el.getAttribute('data-ilsej-keyword') === keyword) suggestionEl = el;
					});
					if (!suggestionEl) return;

					suggestionEl.scrollIntoView({ behavior: 'smooth', block: 'center' });

					// Flash highlight effect
					suggestionEl.style.backgroundColor = '#fff3cd';
					setTimeout(function () { suggestionEl.style.backgroundColor = ''; }, 2000);
				}, 100);
			});
		});
	}

	// Re-apply yellow highlights from shared suggestions (used after save/autosave)
	function reapplyHighlights() {
		const currentSuggestions = sharedSidebarState.suggestions;
		if (!currentSuggestions || currentSuggestions.length === 0 || sharedSidebarState.isCleared) return;

		const blocks = wp.data.select('core/block-editor').getBlocks();
		const dispatch = wp.data.dispatch('core/block-editor');
		const highlightedKeywords = {};

		flattenBlocks(blocks).forEach(function (block) {
			if (['core/paragraph', 'core/list-item'].includes(block.name)) {
				let modifiedContent = block.attributes.content;
				let blockWasModified = false;
				currentSuggestions.forEach(function (item) {
					if (item.applied) return;
					const kwKey = item.keyword.toLowerCase();
					if (highlightedKeywords[kwKey]) return;
					const regex = createKeywordRegex(item.keyword);
					if (modifiedContent.match(regex)) {
						blockWasModified = true;
						highlightedKeywords[kwKey] = true;
						modifiedContent = modifiedContent.replace(regex, '$1<mark class="ilsej-highlight" style="background-color:#fff9c4; cursor:pointer;" data-ilsej-url="' + (item.api.metadata.url || '#') + '">$2</mark>');
					}
				});
				if (blockWasModified) dispatch.updateBlockAttributes(block.clientId, { content: modifiedContent });
			}
		});

		// Reset dirty state so WordPress doesn't think there are unsaved changes.
		// The marks are editor-only visual aids — the saved content is already clean.
		setTimeout(function () {
			const editorDispatch = wp.data.dispatch('core/editor');
			if (editorDispatch && editorDispatch.resetEditorBlocks) {
				editorDispatch.resetEditorBlocks(wp.data.select('core/block-editor').getBlocks());
			}
			attachMarkClickListeners();
		}, 500);
	}

	// Cleanup Helper - Remove all yellow highlights
	const removeMarks = function (currentBlocks, updateFn) {
		flattenBlocks(currentBlocks).forEach(function (block) {
			if (['core/paragraph', 'core/heading', 'core/list-item'].includes(block.name)) {
				const content = block.attributes.content;
				if (content && (content.includes('ilsej-highlight') || content.includes('background-color:#fff9c4') || content.includes('background-color:yellow'))) {
					// Remove all mark elements with ilsej-highlight class
					let newContent = content.replace(/<mark[^>]*class="ilsej-highlight"[^>]*>(.*?)<\/mark>/gi, '$1');
					// Also remove any mark elements with yellow background (fallback)
					newContent = newContent.replace(/<mark[^>]*style="[^"]*background-color:\s*(#fff9c4|yellow)[^"]*"[^>]*>(.*?)<\/mark>/gi, '$2');
					if (newContent !== content) {
						updateFn(block.clientId, { content: newContent });
					}
				}
			}
		});
	};

	// ======================================================
	// PART A: SIDEBAR BULK SUGGESTIONS (Right Panel)
	// ======================================================
	// --- PERSISTENT FILTER STORAGE (survives page refreshes via localStorage) ---
	const FILTER_STORAGE_KEY = 'ilsej_filter_selections';
	function loadSavedFilters() {
		try {
			const saved = localStorage.getItem(FILTER_STORAGE_KEY);
			return saved ? JSON.parse(saved) : {};
		} catch (e) { return {}; }
	}
	function saveFilters(cats, tags, postTypes) {
		try {
			localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify({
				selectedCats: cats, selectedTags: tags, selectedPostTypes: postTypes
			}));
		} catch (e) { /* quota exceeded or private mode */ }
	}

	const savedFilters = loadSavedFilters();

	// --- PERSISTENT STATE (Outside component to survive tab switches) ---
	const sharedSidebarState = {
		suggestions: [],
		statusMsg: '',
		selectedCats: savedFilters.selectedCats || ['all'],
		selectedTags: savedFilters.selectedTags || ['all'],
		selectedPostTypes: savedFilters.selectedPostTypes || ['all'],
		age: 'all',
		isCleared: false,
		isScanning: false,
		perf: null
	};
	let cachedFilters = null;

	let activeComponentInstance = null;

	const IlsejPanelComponent = function (props) {
		const blocks = props.blocks;
		const updateBlockAttributes = props.updateBlockAttributes;
		const propsRef = useRef(props);
		propsRef.current = props;

		// State
		const [categories, setCategories] = useState([{ label: 'All', value: 'all' }]);
		const [selectedCats, setSelectedCats] = useState(sharedSidebarState.selectedCats);
		const [tags, setTags] = useState([{ label: 'All', value: 'all' }]);
		const [selectedTags, setSelectedTags] = useState(sharedSidebarState.selectedTags);
		const [postTypes, setPostTypes] = useState([{ label: 'All', value: 'all' }]);
		const [selectedPostTypes, setSelectedPostTypes] = useState(sharedSidebarState.selectedPostTypes);
		const [age, setAge] = useState(sharedSidebarState.age);
		const [suggestions, setSuggestions] = useState(sharedSidebarState.suggestions);
		const [isScanning, setIsScanning] = useState(sharedSidebarState.isScanning);
		const [statusMsg, setStatusMsg] = useState(sharedSidebarState.statusMsg);
		const [isCleared, setIsCleared] = useState(sharedSidebarState.isCleared);
		const [perf, setPerf] = useState(sharedSidebarState.perf);

		// Update shared state when local state changes
		useEffect(function () {
			sharedSidebarState.suggestions = suggestions;
			sharedSidebarState.statusMsg = statusMsg;
			sharedSidebarState.selectedCats = selectedCats;
			sharedSidebarState.selectedTags = selectedTags;
			sharedSidebarState.selectedPostTypes = selectedPostTypes;
			sharedSidebarState.age = age;
			sharedSidebarState.isCleared = isCleared;
			sharedSidebarState.isScanning = isScanning;
			sharedSidebarState.perf = perf;
			saveFilters(selectedCats, selectedTags, selectedPostTypes);
		}, [suggestions, statusMsg, selectedCats, selectedTags, selectedPostTypes, age, isCleared, isScanning, perf]);

		// Register active instance for global callbacks
		useEffect(function () {
			activeComponentInstance = {
				setSuggestions: setSuggestions,
				setIsScanning: setIsScanning,
				setStatusMsg: setStatusMsg,
				setIsCleared: setIsCleared,
				setPerf: setPerf
			};
			return function () { activeComponentInstance = null; };
		}, []);

		useEffect(function () {
			// Fetch Categories, Tags, and Post Types (with caching)
			if (cachedFilters) {
				if (cachedFilters.categories) {
					setCategories(cachedFilters.categories.map(function (c) { return { label: c.text, value: c.value }; }));
				}
				if (cachedFilters.tags) {
					setTags(cachedFilters.tags.map(function (t) { return { label: t.text, value: t.value }; }));
				}
				if (cachedFilters.post_types) {
					setPostTypes(cachedFilters.post_types.map(function (pt) { return { label: pt.text, value: pt.value }; }));
				}
			} else {
				$.post(ILSEJ.ajaxUrl, { action: 'ilsej_get_all_editor_filters', nonce: ILSEJ.nonce }, function (resp) {
					if (resp && resp.success && resp.data) {
						cachedFilters = resp.data;
						if (resp.data.categories) {
							const formattedCats = resp.data.categories.map(function (c) { return { label: c.text, value: c.value }; });
							setCategories(formattedCats);
						}
						if (resp.data.tags) {
							const formattedTags = resp.data.tags.map(function (t) { return { label: t.text, value: t.value }; });
							setTags(formattedTags);
						}
						if (resp.data.post_types) {
							const formattedPTs = resp.data.post_types.map(function (pt) { return { label: pt.text, value: pt.value }; });
							setPostTypes(formattedPTs);
						}
					}
				}).fail(function () {
					console.warn('ILSEJ: Failed to load editor filters.');
				});
			}

			// CLEAR LOCAL UI STATE ON SAVE (If mounted)
			let wasSaving = false;
			const unsubscribe = wp.data.subscribe(function () {
				const isSaving = wp.data.select('core/editor').isSavingPost();
				const isAutosaving = wp.data.select('core/editor').isAutosavingPost();

				if (isSaving && !isAutosaving && !wasSaving) {
					wasSaving = true;
					// Reset local state to clear the results box
					setSuggestions([]);
					setStatusMsg('');
					setIsCleared(false);
					setPerf(null);
				}

				if (!isSaving) {
					wasSaving = false;
				}
			}); return function () {
				unsubscribe();
			};
		}, []);

		useEffect(function () {
			const handleManualCleared = function () {
				setIsCleared(true);
			};
			window.addEventListener('ilsej-highlights-cleared', handleManualCleared);
			return function () {
				window.removeEventListener('ilsej-highlights-cleared', handleManualCleared);
			};
		}, []);

		const cleanHighlights = function (silent) {
			// Use latest blocks from props to ensure we clear all current highlights
			const currentBlocks = propsRef.current.blocks || blocks;
			removeMarks(currentBlocks, updateBlockAttributes);
			setIsCleared(true);
			if (silent !== true) {
				setStatusMsg('Highlights cleared.');
			}
		};

		const restoreHighlights = function () {
			// Use latest blocks from props
			const currentBlocks = propsRef.current.blocks || blocks;
			let foundCount = 0;
			const highlightedKeywords = {};
			flattenBlocks(currentBlocks).forEach(function (block) {
				if (['core/paragraph', 'core/list-item'].includes(block.name)) {
					let modifiedContent = block.attributes.content;
					let blockWasModified = false;
					suggestions.forEach(function (item) {
						const kwKey = item.keyword.toLowerCase();
						if (highlightedKeywords[kwKey]) { return; }
						const regex = createKeywordRegex(item.keyword);
						if (modifiedContent.match(regex)) {
							blockWasModified = true; foundCount++;
							highlightedKeywords[kwKey] = true;
							modifiedContent = modifiedContent.replace(regex, '$1<mark class="ilsej-highlight" style="background-color:#fff9c4; cursor:pointer;" data-ilsej-url="' + (item.api.metadata.url || '#') + '">$2</mark>');
						}
					});
					if (blockWasModified) updateBlockAttributes(block.clientId, { content: modifiedContent });
				}
			});
			setIsCleared(false);
			setStatusMsg('Found ' + foundCount + ' matches.');
			// Re-attach click listeners after restoring highlights
			setTimeout(attachMarkClickListeners, 500);
		};

		const toggleCategory = function (value) {
			const allIndividual = categories.filter(function (c) { return c.value !== 'all'; }).map(function (c) { return c.value; });
			if (value === 'all') {
				setSelectedCats(selectedCats.includes('all') ? [] : ['all']);
				return;
			}
			let newCats;
			if (selectedCats.includes('all')) {
				newCats = allIndividual.filter(function (c) { return c !== value; });
			} else {
				newCats = [].concat(selectedCats);
				if (newCats.includes(value)) { newCats = newCats.filter(function (c) { return c !== value; }); }
				else { newCats.push(value); }
			}
			if (allIndividual.length > 0 && allIndividual.every(function (v) { return newCats.includes(v); })) { newCats = ['all']; }
			setSelectedCats(newCats);
		};

		const togglePostType = function (value) {
			const allIndividual = postTypes.filter(function (pt) { return pt.value !== 'all'; }).map(function (pt) { return pt.value; });
			if (value === 'all') {
				setSelectedPostTypes(selectedPostTypes.includes('all') ? [] : ['all']);
				return;
			}
			let newPTs;
			if (selectedPostTypes.includes('all')) {
				newPTs = allIndividual.filter(function (pt) { return pt !== value; });
			} else {
				newPTs = [].concat(selectedPostTypes);
				if (newPTs.includes(value)) { newPTs = newPTs.filter(function (pt) { return pt !== value; }); }
				else { newPTs.push(value); }
			}
			if (allIndividual.length > 0 && allIndividual.every(function (v) { return newPTs.includes(v); })) { newPTs = ['all']; }
			setSelectedPostTypes(newPTs);
		};

		const toggleTag = function (value) {
			const allIndividual = tags.filter(function (t) { return t.value !== 'all'; }).map(function (t) { return t.value; });
			if (value === 'all') {
				setSelectedTags(selectedTags.includes('all') ? [] : ['all']);
				return;
			}
			let newTags;
			if (selectedTags.includes('all')) {
				newTags = allIndividual.filter(function (t) { return t !== value; });
			} else {
				newTags = [].concat(selectedTags);
				if (newTags.includes(value)) { newTags = newTags.filter(function (t) { return t !== value; }); }
				else { newTags.push(value); }
			}
			if (allIndividual.length > 0 && allIndividual.every(function (v) { return newTags.includes(v); })) { newTags = ['all']; }
			setSelectedTags(newTags);
		};

		const scrollToLocation = function (keyword, url) {
			const editorIframe = document.querySelector('iframe[name="editor-canvas"]');
			const contextDoc = editorIframe ? editorIframe.contentDocument : document;
			if (!contextDoc) return;
			let targetElement = null;

			// First, look for an already-applied link matching this keyword + URL.
			if (url) {
				const links = contextDoc.querySelectorAll('a[href="' + url + '"]');
				for (let j = 0; j < links.length; j++) {
					if (links[j].textContent.trim().toLowerCase() === keyword.trim().toLowerCase()) { targetElement = links[j]; break; }
				}
			}

			// Fall back to the first highlighted mark (case-insensitive to match highlight regex).
			if (!targetElement) {
				const elements = contextDoc.querySelectorAll('mark.ilsej-highlight');
				const kwLower = keyword.trim().toLowerCase();
				for (let i = 0; i < elements.length; i++) {
					if (elements[i].textContent.trim().toLowerCase() === kwLower) { targetElement = elements[i]; break; }
				}
			}

			if (targetElement) {
				targetElement.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
				targetElement.style.outline = '3px solid #d32f2f';
				targetElement.style.transition = 'all 0.3s ease';
				setTimeout(function () { targetElement.style.outline = 'none'; }, 2000);
			}
		};

		const runScan = function () {
			setIsScanning(true);
			sharedSidebarState.isScanning = true;
			setSuggestions([]);
			setStatusMsg('Scanning...');
			setPerf(null); // Clear previous perf data
			// Clear all existing yellow highlights first (silent=true to avoid message blink)
			cleanHighlights(true);
			let fullContent = '';
			if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
				fullContent = wp.data.select('core/editor').getEditedPostContent() || '';
				// NEW: Strip Gutenberg comments to keep content clean but leave HTML tags like <h2>
				fullContent = stripGutenbergComments(fullContent);
			} else {
				// Fallback for non-editor contexts if needed, though runScan is editor-only
				const currentBlocks = propsRef.current.blocks || blocks;
				currentBlocks.forEach(function (b) { if (b.attributes.content) fullContent += b.attributes.content + ' '; });
			}

			const catPayload = selectedCats.includes('all') ? [] : selectedCats;
			const tagPayload = selectedTags.includes('all') ? [] : selectedTags;
			const postTypePayload = selectedPostTypes.includes('all') ? [] : selectedPostTypes;
			const agePayload = (age === 'all') ? [] : age.split(',');

			// Get current post ID from WordPress data or ILSEJ object
			let currentPostId;
			if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
				try {
					currentPostId = wp.data.select('core/editor').getCurrentPostId() || 0;
				} catch (e) {
					currentPostId = ILSEJ.postId || 0;
				}
			} else {
				currentPostId = ILSEJ.postId || 0;
			}

			$.post(ILSEJ.ajaxUrl, {
				action: 'ilsej_get_suggestions', nonce: ILSEJ.nonce,
				content: fullContent,
				post_id: currentPostId, // Current post ID to exclude from suggestions
				filters: { category: catPayload, tags: tagPayload, post_type: postTypePayload, publish_year: agePayload }
			}, function (resp) {
				sharedSidebarState.isScanning = false;
				if (!resp || !resp.success || !resp.data || !resp.data.suggestions || resp.data.suggestions.length === 0) {
					sharedSidebarState.statusMsg = 'No suggestions.';
					sharedSidebarState.suggestions = [];
					sharedSidebarState.perf = null;
					if (activeComponentInstance) {
						activeComponentInstance.setIsScanning(false);
						activeComponentInstance.setSuggestions([]);
						activeComponentInstance.setStatusMsg('No suggestions.');
						activeComponentInstance.setPerf(null);
					}
					return;
				}

				// Apply highlights globally if callback runs
				const newSuggestions = resp.data.suggestions;
				const perfData = resp.data.perf;
				let foundCount = 0;
				const blocks = wp.data.select('core/block-editor').getBlocks();
				const dispatch = wp.data.dispatch('core/block-editor');

				const highlightedKeywords = {};
				flattenBlocks(blocks).forEach(function (block) {
					if (['core/paragraph', 'core/list-item'].includes(block.name)) {
						let modifiedContent = block.attributes.content;
						let blockWasModified = false;
						newSuggestions.forEach(function (item) {
							const kwKey = item.keyword.toLowerCase();
							if (highlightedKeywords[kwKey]) { return; }
							const regex = createKeywordRegex(item.keyword);
							if (modifiedContent.match(regex)) {
								blockWasModified = true; foundCount++;
								highlightedKeywords[kwKey] = true;
								modifiedContent = modifiedContent.replace(regex, '$1<mark class="ilsej-highlight" style="background-color:#fff9c4; cursor:pointer;" data-ilsej-url="' + (item.api.metadata.url || '#') + '">$2</mark>');
							}
						});
						if (blockWasModified) dispatch.updateBlockAttributes(block.clientId, { content: modifiedContent });
					}
				});

				// Attach click listeners to highlighted marks after DOM updates
				setTimeout(attachMarkClickListeners, 500);

				sharedSidebarState.suggestions = newSuggestions;
				sharedSidebarState.isCleared = false;
				sharedSidebarState.statusMsg = 'Found ' + foundCount + ' matches.';
				sharedSidebarState.perf = perfData;

				if (activeComponentInstance) {
					activeComponentInstance.setIsScanning(false);
					activeComponentInstance.setSuggestions(newSuggestions);
					activeComponentInstance.setIsCleared(false);
					activeComponentInstance.setStatusMsg('Found ' + foundCount + ' matches.');
					activeComponentInstance.setPerf(perfData);
				}
			}, 'json').fail(function () {
				sharedSidebarState.isScanning = false;
				sharedSidebarState.statusMsg = 'Error.';
				sharedSidebarState.perf = null;
				if (activeComponentInstance) {
					activeComponentInstance.setIsScanning(false);
					activeComponentInstance.setStatusMsg('Error.');
					activeComponentInstance.setPerf(null);
				}
			});
		};

		const applyLink = function (item, index) {
			const keyword = item.keyword; const url = item.api.metadata.url; let appliedCount = 0;
			// Use latest blocks from props (flatten to reach list items)
			const currentBlocks = propsRef.current.blocks || blocks;
			let linked = false;
			flattenBlocks(currentBlocks).forEach(function (block) {
				if (linked) { return; }
				if (block.attributes.content && block.attributes.content.includes('class="ilsej-highlight"')) {
					const content = block.attributes.content;
					// Use 'i' flag (no 'g') to replace only the first occurrence.
					const regexMark = new RegExp('<mark class="ilsej-highlight"[^>]*>(' + escapeRegExp(keyword) + ')</mark>', 'i');
					if (content.match(regexMark)) {
						const newContent = content.replace(regexMark, '<a href="' + url + '">$1</a>');
						updateBlockAttributes(block.clientId, { content: newContent });
						appliedCount++;
						linked = true;
					}
				}
			});
			if (appliedCount > 0) {
				const updatedSug = [].concat(suggestions);
				updatedSug[index].applied = true; updatedSug[index].count = appliedCount;
				setSuggestions(updatedSug);
			}
		};

		const copyUrl = function (url, onSuccess) {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(function () { if (onSuccess) onSuccess(); });
			} else {
				const $temp = $('<input>'); $('body').append($temp); $temp.val(url).select(); document.execCommand('copy'); $temp.remove();
				if (onSuccess) onSuccess();
			}
		};

		// Render Sidebar
		return el('div', { className: 'ilsej-panel-content' },
			ILSEJ.enableCategoryFilter && el('div', { style: { marginBottom: '15px' } },
				el('label', { style: { display: 'block', marginBottom: '5px', fontSize: '11px', fontWeight: '500', textTransform: 'uppercase' } }, 'Primary Categories Filter'),
				el('div', { style: { maxHeight: '150px', overflowY: 'auto', border: '1px solid #757575', padding: '8px', borderRadius: '2px', backgroundColor: '#fff' } },
					categories.map(function (cat) {
						return el('div', { key: cat.value, style: { marginBottom: '8px' } }, el(CheckboxControl, { label: cat.label, checked: selectedCats.includes('all') || selectedCats.includes(cat.value), onChange: function () { toggleCategory(cat.value); } }));
					})
				)
			),
			ILSEJ.enableTagFilter && el('div', { style: { marginBottom: '15px' } },
				el('label', { style: { display: 'block', marginBottom: '5px', fontSize: '11px', fontWeight: '500', textTransform: 'uppercase' } }, 'Tags Filter'),
				el('div', { style: { maxHeight: '150px', overflowY: 'auto', border: '1px solid #757575', padding: '8px', borderRadius: '2px', backgroundColor: '#fff' } },
					tags.map(function (tag) {
						return el('div', { key: tag.value, style: { marginBottom: '8px' } }, el(CheckboxControl, { label: tag.label, checked: selectedTags.includes('all') || selectedTags.includes(tag.value), onChange: function () { toggleTag(tag.value); } }));
					})
				)
			),
			el('div', { style: { marginBottom: '15px' } },
				el('label', { style: { display: 'block', marginBottom: '5px', fontSize: '11px', fontWeight: '500', textTransform: 'uppercase' } }, 'Post Types Filter'),
				el('div', { style: { maxHeight: '150px', overflowY: 'auto', border: '1px solid #757575', padding: '8px', borderRadius: '2px', backgroundColor: '#fff' } },
					postTypes.map(function (pt) {
						return el('div', { key: pt.value, style: { marginBottom: '8px' } }, el(CheckboxControl, { label: pt.label, checked: selectedPostTypes.includes('all') || selectedPostTypes.includes(pt.value), onChange: function () { togglePostType(pt.value); } }));
					})
				)
			),
			el(SelectControl, { label: 'Article Age Filter', value: age, options: getAgeOptions(), onChange: function (val) { setAge(val); } }),
			el(Button, { isPrimary: true, isBusy: isScanning, onClick: runScan, style: { width: '100%', marginBottom: '10px' } }, 'Run Bulk Auto Suggestion'),
			el('div', { style: { fontSize: '12px', color: '#666', marginBottom: '10px' } }, statusMsg),
			suggestions.length > 0 && el('div', { style: { marginBottom: '15px' } },
				isCleared ?
					el(Button, { isPrimary: true, isSmall: true, onClick: restoreHighlights, style: { width: '100%' } }, 'Restore Highlights') :
					el(Button, { isSecondary: true, isSmall: true, onClick: function () { cleanHighlights(); }, style: { width: '100%' } }, 'Clear All Highlights')
			),
			suggestions.length > 0 && el('div', { className: 'ilsej-suggestion-list', style: { borderTop: '1px solid #ddd', paddingTop: '10px' } },
				suggestions.map(function (item, index) {
					const meta = item.api.metadata; const date = new Date(meta.publish_date).toLocaleDateString(); const isApplied = item.applied;
					return el('div', { key: index, id: 'ilsej-suggestion-' + item.keyword.toLowerCase().replace(/[^a-z0-9]/g, '-'), 'data-ilsej-keyword': item.keyword.toLowerCase(), style: { borderBottom: '1px solid #eee', padding: '10px 0', opacity: isApplied ? 0.6 : 1, transition: 'background-color 0.3s ease' } },
						el('div', { style: { fontWeight: 'bold', fontSize: '14px', cursor: 'pointer', display: 'flex', alignItems: 'center' }, onClick: function () { scrollToLocation(item.keyword, item.api.metadata.url); } },
							el('span', { style: { marginRight: '5px' } }, (index + 1) + '.'),
							el('span', { className: 'dashicons dashicons-location', style: { fontSize: '16px', marginRight: '4px', color: '#0073aa' } }),
							el('span', { style: { textDecoration: 'underline', textDecorationStyle: 'dotted', textWrap: 'auto' } }, item.keyword)
						),
						el('div', { style: { fontSize: '11px', color: '#999', marginLeft: '20px', fontStyle: 'italic', marginBottom: '5px' }, title: 'An embedding was generated from the context text for the suggested phrase.' }, item.context),
						el('a', { href: meta.url, target: '_blank', rel: 'noopener noreferrer', style: { display: 'block', fontSize: '12px', margin: '0 0 5px 20px', textDecoration: 'none', color: '#0073aa' } }, decodeHTML(meta.title || '')),
						el('div', { style: { fontSize: '11px', color: '#888', margin: '0 0 8px 20px' } }, meta.category + ' | ' + date + ' | Relevance Score: ' + (item.api.score ? item.api.score.toFixed(3) : '0')),
						el('div', { style: { display: 'flex', gap: '5px', marginLeft: '20px' } },
							el(Button, { isPrimary: !isApplied, disabled: isApplied, onClick: function () { applyLink(item, index); } }, isApplied ? 'Applied' : 'Apply'),
							el(Button, { isSecondary: true, onClick: function (e) { const btn = e.target; copyUrl(meta.url, function () { btn.innerText = 'Copied!'; setTimeout(function () { btn.innerText = 'Copy URL'; }, 1000); }); } }, 'Copy URL')
						)
					);
				}),
				suggestions.length > 0 && perf && el('div', { style: { fontSize: '10px', color: '#999', marginTop: '10px', borderTop: '1px solid #eee', paddingTop: '8px' } },
					el('div', { style: { fontWeight: '600', marginBottom: '3px', textTransform: 'uppercase' } }, 'Performance Scan Details'),
					perf.extraction_provider && el('div', {}, 'Entity Extraction (' + perf.extraction_provider + '): ' + parseFloat(perf.extraction_time).toFixed(3) + 's'),
					el('div', {}, 'Vertex AI Index Search: ' + parseFloat(perf.vertex_time).toFixed(3) + 's (' + perf.vertex_method + ')'),
					el('div', {}, 'Pinecone Retrieval: ' + parseFloat(perf.pinecone_time).toFixed(3) + 's (' + perf.pinecone_calls + ' calls)')
				)
			)
		);
	};

	const IlsejPanel = compose(
		withSelect(function (select) { return { blocks: select('core/block-editor').getBlocks() }; }),
		withDispatch(function (dispatch) { return { updateBlockAttributes: dispatch('core/block-editor').updateBlockAttributes }; })
	)(IlsejPanelComponent);

	// --- REGISTER PLUGIN (Sidebar) ---
	registerPlugin('ilsej-sidebar', {
		icon: 'admin-links',
		render: function () {
			return el(PluginDocumentSettingPanel, {
				name: 'ilsej-panel',
				title: 'Internal Links Suggestions',
				className: 'ilsej-gutenberg-sidebar'
			}, el(IlsejPanel));
		}
	});

	// --- GLOBAL SAVE LISTENER (Always active) ---
	let wasSavingGlobal = false;
	wp.data.subscribe(function () {
		const editor = wp.data.select('core/editor');
		if (!editor) return;

		const isSaving = editor.isSavingPost();
		const isAutosaving = editor.isAutosavingPost();

		if (isSaving && !wasSavingGlobal) {
			wasSavingGlobal = true;

			// 1. Clear Highlights in all blocks (using global dispatch).
			// Runs on both save and autosave/preview so marks never reach the frontend.
			const blocks = wp.data.select('core/block-editor').getBlocks();
			removeMarks(blocks, wp.data.dispatch('core/block-editor').updateBlockAttributes);

			// 2. Clear Global Shared State only on explicit save (not autosave/preview)
			if (!isAutosaving) {
				sharedSidebarState.suggestions = [];
				sharedSidebarState.statusMsg = '';
				sharedSidebarState.isScanning = false;
			}
		}

		if (!isSaving && wasSavingGlobal) {
			wasSavingGlobal = false;
			// Save completed — restore highlights in the editor so they remain visible
			setTimeout(reapplyHighlights, 300);
		} else if (!isSaving) {
			wasSavingGlobal = false;
		}
	});


	// ======================================================

	// --- HOVER CLEANUP (Unified for Gutenberg) ---
	let ilsejCleanupTimer = null;
	const saveButtons = '.editor-post-publish-button, .editor-post-publish-panel__toggle, .editor-post-save-draft';

	$(document).on('mouseenter', saveButtons, function () {
		if (!ilsejCleanupTimer) {
			ilsejCleanupTimer = setTimeout(function () {
				const blocks = wp.data.select('core/block-editor').getBlocks();
				removeMarks(blocks, wp.data.dispatch('core/block-editor').updateBlockAttributes);
				// Also update shared state
				sharedSidebarState.isCleared = true;
				sharedSidebarState.statusMsg = 'Highlights cleared (auto).';
				// Dispatch event for active sidebar instance
				window.dispatchEvent(new CustomEvent('ilsej-highlights-cleared'));
			}, 500);
		}
	});

	$(document).on('mouseleave', saveButtons, function () {
		if (ilsejCleanupTimer) {
			clearTimeout(ilsejCleanupTimer);
			ilsejCleanupTimer = null;
		}
	});

	// PART B: TOOLBAR SINGLE SUGGESTION (Format Type)
	// ======================================================
	const IlsejToolbarModal = function (props) {
		const selectedText = props.text || '';
		const toolbarSavedFilters = loadSavedFilters();

		// State
		const [anchorText, setAnchorText] = useState(selectedText);
		const [categories, setCategories] = useState([{ label: 'All', value: 'all' }]);
		const [selectedCats, setSelectedCats] = useState(toolbarSavedFilters.selectedCats || ['all']);
		const [tags, setTags] = useState([{ label: 'All', value: 'all' }]);
		const [selectedTags, setSelectedTags] = useState(toolbarSavedFilters.selectedTags || ['all']);
		const [postTypes, setPostTypes] = useState([{ label: 'All', value: 'all' }]);
		const [selectedPostTypes, setSelectedPostTypes] = useState(toolbarSavedFilters.selectedPostTypes || ['all']);
		const [age, setAge] = useState('all');
		const [suggestions, setSuggestions] = useState([]);
		const [isLoading, setIsLoading] = useState(false);
		const [errorMsg, setErrorMsg] = useState('');
		const [perf, setPerf] = useState(null);

		// Sync filter selections to localStorage and shared sidebar state
		useEffect(function () {
			saveFilters(selectedCats, selectedTags, selectedPostTypes);
			sharedSidebarState.selectedCats = selectedCats;
			sharedSidebarState.selectedTags = selectedTags;
			sharedSidebarState.selectedPostTypes = selectedPostTypes;
		}, [selectedCats, selectedTags, selectedPostTypes]);

		useEffect(function () {
			if (cachedFilters) {
				if (cachedFilters.categories) {
					setCategories(cachedFilters.categories.map(function (c) { return { label: c.text, value: c.value }; }));
				}
				if (cachedFilters.tags) {
					setTags(cachedFilters.tags.map(function (t) { return { label: t.text, value: t.value }; }));
				}
				if (cachedFilters.post_types) {
					setPostTypes(cachedFilters.post_types.map(function (pt) { return { label: pt.text, value: pt.value }; }));
				}
			} else {
				$.post(ILSEJ.ajaxUrl, { action: 'ilsej_get_all_editor_filters', nonce: ILSEJ.nonce }, function (resp) {
					if (resp && resp.success && resp.data) {
						cachedFilters = resp.data;
						if (resp.data.categories) {
							setCategories(resp.data.categories.map(function (c) { return { label: c.text, value: c.value }; }));
						}
						if (resp.data.tags) {
							setTags(resp.data.tags.map(function (t) { return { label: t.text, value: t.value }; }));
						}
						if (resp.data.post_types) {
							setPostTypes(resp.data.post_types.map(function (pt) { return { label: pt.text, value: pt.value }; }));
						}
					}
				}).fail(function () {
					console.warn('ILSEJ: Failed to load editor filters.');
				});
			}
		}, []);

		const toggleCategory = function (value) {
			const allIndividual = categories.filter(function (c) { return c.value !== 'all'; }).map(function (c) { return c.value; });
			if (value === 'all') {
				setSelectedCats(selectedCats.includes('all') ? [] : ['all']);
				return;
			}
			let newCats;
			if (selectedCats.includes('all')) {
				newCats = allIndividual.filter(function (c) { return c !== value; });
			} else {
				newCats = [].concat(selectedCats);
				if (newCats.includes(value)) newCats = newCats.filter(function (c) { return c !== value; });
				else newCats.push(value);
			}
			if (allIndividual.length > 0 && allIndividual.every(function (v) { return newCats.includes(v); })) { newCats = ['all']; }
			setSelectedCats(newCats);
		};

		const toggleTag = function (value) {
			const allIndividual = tags.filter(function (t) { return t.value !== 'all'; }).map(function (t) { return t.value; });
			if (value === 'all') {
				setSelectedTags(selectedTags.includes('all') ? [] : ['all']);
				return;
			}
			let newTags;
			if (selectedTags.includes('all')) {
				newTags = allIndividual.filter(function (t) { return t !== value; });
			} else {
				newTags = [].concat(selectedTags);
				if (newTags.includes(value)) newTags = newTags.filter(function (t) { return t !== value; });
				else newTags.push(value);
			}
			if (allIndividual.length > 0 && allIndividual.every(function (v) { return newTags.includes(v); })) { newTags = ['all']; }
			setSelectedTags(newTags);
		};

		const togglePostType = function (value) {
			const allIndividual = postTypes.filter(function (pt) { return pt.value !== 'all'; }).map(function (pt) { return pt.value; });
			if (value === 'all') {
				setSelectedPostTypes(selectedPostTypes.includes('all') ? [] : ['all']);
				return;
			}
			let newPTs;
			if (selectedPostTypes.includes('all')) {
				newPTs = allIndividual.filter(function (pt) { return pt !== value; });
			} else {
				newPTs = [].concat(selectedPostTypes);
				if (newPTs.includes(value)) newPTs = newPTs.filter(function (pt) { return pt !== value; });
				else newPTs.push(value);
			}
			if (allIndividual.length > 0 && allIndividual.every(function (v) { return newPTs.includes(v); })) { newPTs = ['all']; }
			setSelectedPostTypes(newPTs);
		};

		// NEW: Copy Helper for Modal
		const copyUrl = function (url, onSuccess) {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(function () { if (onSuccess) onSuccess(); });
			} else {
				const $temp = $('<input>'); $('body').append($temp); $temp.val(url).select(); document.execCommand('copy'); $temp.remove();
				if (onSuccess) onSuccess();
			}
		};

		const fetchSuggestions = function () {
			setIsLoading(true); setSuggestions([]); setErrorMsg(''); setPerf(null); // Added setPerf(null)

			const catPayload = selectedCats.includes('all') ? [] : selectedCats;
			const tagPayload = selectedTags.includes('all') ? [] : selectedTags;
			const postTypePayload = selectedPostTypes.includes('all') ? [] : selectedPostTypes;
			const agePayload = (age === 'all') ? [] : age.split(',');

			// Get current post ID from WordPress data or ILSEJ object
			let currentPostId;
			if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
				try {
					currentPostId = wp.data.select('core/editor').getCurrentPostId() || 0;
				} catch (e) {
					currentPostId = ILSEJ.postId || 0;
				}
			} else {
				currentPostId = ILSEJ.postId || 0;
			}

			$.post(ILSEJ.ajaxUrl, {
				action: 'ilsej_get_suggestions',
				nonce: ILSEJ.nonce,
				mode: 'anchor',
				content: anchorText,
				post_id: currentPostId, // Current post ID to exclude from suggestions
				filters: { category: catPayload, tags: tagPayload, post_type: postTypePayload, publish_year: agePayload }
			}, function (resp) {
				setIsLoading(false);
				if (resp && resp.success && resp.data && resp.data.suggestions) { // Updated condition
					setSuggestions(resp.data.suggestions);
					setPerf(resp.data.perf); // Set performance data
				} else {
					setErrorMsg('No suggestions found.'); // Updated error message
				}
			}, 'json').fail(function () {
				setIsLoading(false);
				setErrorMsg('Error fetching suggestions.'); // Updated error message
			});
		};

		const applyLink = function (url) {
			props.onApply(url, anchorText);
		};

		return el(Modal, { title: 'Internal Link Based on Anchor', onRequestClose: props.onClose, style: { minWidth: '700px' } },
			el('div', { className: 'ilsej-modal-content' },
				el(TextControl, { label: 'Change Text', value: anchorText, onChange: function (v) { setAnchorText(v); }, style: { marginBottom: '20px' } }),

				el('div', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '20px', marginBottom: '20px' } },
					ILSEJ.enableCategoryFilter && el('div', {},
						el('label', { style: { display: 'block', fontSize: '11px', fontWeight: '500', textTransform: 'uppercase', marginBottom: '5px' } }, 'Primary Categories Filter'),
						el('div', { style: { maxHeight: '120px', overflowY: 'auto', border: '1px solid #757575', padding: '8px' } },
							categories.map(function (cat) {
								return el('div', { key: cat.value, style: { marginBottom: '8px' } }, el(CheckboxControl, { label: cat.label, checked: selectedCats.includes('all') || selectedCats.includes(cat.value), onChange: function () { toggleCategory(cat.value); } }));
							})
						)
					),
					ILSEJ.enableTagFilter && el('div', {},
						el('label', { style: { display: 'block', fontSize: '11px', fontWeight: '500', textTransform: 'uppercase', marginBottom: '5px' } }, 'Tags Filter'),
						el('div', { style: { maxHeight: '120px', overflowY: 'auto', border: '1px solid #757575', padding: '8px' } },
							tags.map(function (tag) {
								return el('div', { key: tag.value, style: { marginBottom: '8px' } }, el(CheckboxControl, { label: tag.label, checked: selectedTags.includes('all') || selectedTags.includes(tag.value), onChange: function () { toggleTag(tag.value); } }));
							})
						)
					),
					el('div', {},
						el('label', { style: { display: 'block', fontSize: '11px', fontWeight: '500', textTransform: 'uppercase', marginBottom: '5px' } }, 'Post Types Filter'),
						el('div', { style: { maxHeight: '120px', overflowY: 'auto', border: '1px solid #757575', padding: '8px' } },
							postTypes.map(function (pt) {
								return el('div', { key: pt.value, style: { marginBottom: '8px' } }, el(CheckboxControl, { label: pt.label, checked: selectedPostTypes.includes('all') || selectedPostTypes.includes(pt.value), onChange: function () { togglePostType(pt.value); } }));
							})
						)
					),
					el('div', {},
						el(SelectControl, { label: 'Article Age Filter', value: age, options: getAgeOptions(), onChange: function (v) { setAge(v); } })
					)
				),

				el(Button, { isPrimary: true, isBusy: isLoading, onClick: fetchSuggestions, style: { width: '100%', marginBottom: '20px' } }, 'Search Suggestions'),

				errorMsg && el('div', { style: { color: 'red', marginBottom: '10px' } }, errorMsg),

				el('div', { style: { maxHeight: '300px', overflowY: 'auto', borderTop: '1px solid #ddd' } },
					suggestions.map(function (item, index) {
						const meta = item.metadata || {};
						const date = new Date(meta.publish_date).toLocaleDateString();
						const url = meta.url || item.id;
						const score = item.score ? item.score.toFixed(3) : '';

						return el('div', { key: index, style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 0', borderBottom: '1px solid #eee' } },
							el('div', { style: { maxWidth: '80%' } },
								el('a', { href: url, target: '_blank', rel: 'noopener noreferrer', style: { fontWeight: 'bold', color: '#0073aa', textDecoration: 'none' } }, (index + 1) + '. ' + decodeHTML(meta.title || '')),
								el('div', { style: { fontSize: '11px', color: '#555' } }, meta.category + ' | ' + date + ' | Relevance Score: ' + score)
							),
							el('div', { style: { display: 'flex', gap: '5px' } },
								el(Button, { isSecondary: true, onClick: function () { applyLink(url); } }, 'Apply'),
								// ADDED COPY BUTTON HERE
								el(Button, { isSecondary: true, onClick: function (e) { const btn = e.target; copyUrl(meta.url, function () { btn.innerText = 'Copied!'; setTimeout(function () { btn.innerText = 'Copy URL'; }, 1000); }); } }, 'Copy URL')
							)
						);
					}),
					suggestions.length > 0 && perf && el('div', { style: { fontSize: '10px', color: '#999', marginTop: '10px', borderTop: '1px solid #eee', paddingTop: '8px' } },
						el('div', { style: { fontWeight: '600', marginBottom: '3px', textTransform: 'uppercase' } }, 'Performance Scan Details'),
						perf.extraction_provider && el('div', {}, 'Entity Extraction (' + perf.extraction_provider + '): ' + parseFloat(perf.extraction_time).toFixed(3) + 's'),
						el('div', {}, 'Vertex AI Index Search: ' + parseFloat(perf.vertex_time).toFixed(3) + 's (' + perf.vertex_method + ')'),
						el('div', {}, 'Pinecone Retrieval: ' + parseFloat(perf.pinecone_time).toFixed(3) + 's (' + perf.pinecone_calls + ' calls)')
					)
				)
			)
		);
	};

	const IlsejFormat = function (props) {
		const [isModalOpen, setModalOpen] = useState(false);
		const selectedText = props.value.text.slice(props.value.start, props.value.end);

		return el(Fragment, {},
			el(RichTextToolbarButton, {
				icon: 'search',
				title: 'Internal Link Based on Anchor',
				onClick: function () { setModalOpen(true); },
				isActive: isModalOpen
			}),
			isModalOpen && el(IlsejToolbarModal, {
				text: selectedText,
				onClose: function () { setModalOpen(false); },
				onApply: function (url, text) {
					const linkedValue = applyFormat(props.value, { type: 'core/link', attributes: { url: url } });
					props.onChange(linkedValue);
					setModalOpen(false);
				}
			})
		);
	};

	registerFormatType('ilsej/suggest-link', {
		title: 'Internal Link Based on Anchor',
		tagName: 'span',
		className: 'ilsej-suggest-link-active',
		edit: IlsejFormat
	});



})(window.wp, jQuery);