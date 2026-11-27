(function ($) {

	// --- PERSISTENT FILTER STORAGE (survives page refreshes via localStorage) ---
	const FILTER_STORAGE_KEY = 'ilsej_filter_selections';
	function loadSavedFilters() {
		try { const s = localStorage.getItem(FILTER_STORAGE_KEY); return s ? JSON.parse(s) : {}; }
		catch (e) { return {}; }
	}
	function saveFilterSelections(cats, tags, postTypes) {
		try { localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify({ selectedCats: cats, selectedTags: tags, selectedPostTypes: postTypes })); }
		catch (e) { /* quota exceeded or private mode */ }
	}
	function isFilterChecked(savedArr, value) {
		if (!savedArr || savedArr.length === 0) { return true; } // No saved state = all checked.
		if (savedArr.indexOf('all') !== -1) { return true; }
		return value === 'all' ? false : savedArr.indexOf(value) !== -1;
	}

	// --- 1. SETUP & FETCH CATEGORIES, TAGS AND POST TYPES ---
	if (typeof window.ilsej_categories_list === 'undefined') {
		window.ilsej_categories_list = [{ text: 'All', value: 'all' }];
		window.ilsej_tags_list = [{ text: 'All', value: 'all' }];
		window.ilsej_post_types_list = [{ text: 'All', value: 'all' }];

		$(document).ready(function () {
			const $existingCats = $('#categorychecklist label.selectit');
			if ($existingCats.length > 0) {
				$existingCats.each(function () {
					const catName = $(this).text().trim();
					if (catName) window.ilsej_categories_list.push({ text: catName, value: catName });
				});
			}
			$.post(ILSEJ.ajaxUrl, { action: 'ilsej_get_all_editor_filters', nonce: ILSEJ.nonce }, function (resp) {
				if (resp && resp.success && resp.data) {
					if (resp.data.categories) {
						window.ilsej_categories_list = resp.data.categories;
					}
					if (resp.data.tags) {
						window.ilsej_tags_list = resp.data.tags;
					}
					if (resp.data.post_types) {
						window.ilsej_post_types_list = resp.data.post_types;
					}
				}
			}, 'json').fail(function () {
				console.warn('ILSEJ: Failed to load editor filters.');
			});
		});
	}

	// --- HELPER FUNCTIONS ---
	function escapeHtml(str) {
		if (!str) return '';
		return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function unwrapHighlighted(editor) {
		const body = editor.getBody();
		const dom = editor.dom;
		const highlights = dom.select('span.ilsej-highlight', body);

		editor.undoManager.transact(function () {
			highlights.forEach(function (span) {
				dom.remove(span, true);
			});
		});

		editor.nodeChanged();
	}

	function escapeRegExp(string) {
		return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function decodeHTML(html) {
		if (!html) return html;
		const txt = document.createElement('textarea');
		txt.innerHTML = html;
		return txt.value;
	}

	function createKeywordRegex(keyword) {
		const escaped = escapeRegExp(keyword);
		const isWordOnly = /^[\w]+$/.test(keyword);

		if (isWordOnly) {
			// Use 'i' flag only (no 'g') to match first occurrence only.
			return new RegExp('([^>\\w]|^)(\\b' + escaped + '\\b)(?![\\w-])(?![^<]*>)', 'i');
		} else {
			return new RegExp('([^>\\S]|^|>)(' + escaped + ')(?![^<]*>)', 'i');
		}
	}

	// Remove highlight spans that ended up inside heading tags (h1-h6)
	function stripHighlightsFromHeadings(html) {
		return html.replace(/<(h[1-6])\b[^>]*>[\s\S]*?<\/\1>/gi, function (match) {
			return match.replace(/<span class="ilsej-highlight"[^>]*>([\s\S]*?)<\/span>/gi, '$1');
		});
	}

	let currentSuggestionsCache = [];

	function restoreClassicHighlights(editor, suggestions) {
		const body = editor.getBody();
		const bodyContent = body.innerHTML;
		let modifiedContent = bodyContent;

		suggestions.forEach(function (item) {
			const regex = createKeywordRegex(item.keyword);
			if (modifiedContent.match(regex)) {
				modifiedContent = modifiedContent.replace(regex, '$1<span class="ilsej-highlight" style="background-color: yellow;">$2</span>');
			}
		});

		// Remove any highlights that landed inside heading tags (h1-h6)
		modifiedContent = stripHighlightsFromHeadings(modifiedContent);

		if (modifiedContent !== bodyContent) {
			editor.undoManager.transact(function () {
				editor.dom.setHTML(body, modifiedContent);
			});
			editor.nodeChanged();
		}
	}

	// --- 3. MAIN BULK SUGGESTION FUNCTION ---
	window.fetchIlsejSuggestions = function (editor, mode) {
		const ageOptions = [{ text: 'All', value: 'all' }];
		const currentYear = new Date().getFullYear();
		for (let i = 1; i <= 5; i++) {
			const years = [];
			for (let j = 0; j <= i; j++) {
				years.push(currentYear - j);
			}
			ageOptions.push({ text: 'Up to ' + i + ' year(s) old', value: years.join(',') });
		}

		const savedState = loadSavedFilters();

		const cats = window.ilsej_categories_list || [{ text: 'All', value: 'all' }];
		if (cats.length > 0 && cats[0].value !== 'all') {
			cats.unshift({ text: 'All', value: 'all' });
		}

		let catHtml = '<div id="ilsej-bulk-cat-container" style="max-height:120px; overflow-y:auto; border:1px solid #ccc; padding:8px; background:#fff;">';
		cats.forEach(function (c) {
			const chk = isFilterChecked(savedState.selectedCats, c.value) ? ' checked' : '';
			catHtml += '<label style="display:block; margin-bottom:4px; font-size:13px;">' +
                '<input type="checkbox" class="ilsej-bulk-cb" value="' + escapeHtml(c.value) + '"' + chk + '> ' +
                escapeHtml(c.text) +
                '</label>';
		});
		catHtml += '</div>';

		const postTypes = window.ilsej_post_types_list || [{ text: 'All', value: 'all' }];
		if (postTypes.length > 0 && postTypes[0].value !== 'all') {
			postTypes.unshift({ text: 'All', value: 'all' });
		}

		let postTypeHtml = '<div id="ilsej-bulk-posttype-container" style="max-height:120px; overflow-y:auto; border:1px solid #ccc; padding:8px; background:#fff;">';
		postTypes.forEach(function (pt) {
			const chk = isFilterChecked(savedState.selectedPostTypes, pt.value) ? ' checked' : '';
			postTypeHtml += '<label style="display:block; margin-bottom:4px; font-size:13px;">' +
                '<input type="checkbox" class="ilsej-bulk-posttype-cb" value="' + escapeHtml(pt.value) + '"' + chk + '> ' +
                escapeHtml(pt.text) +
                '</label>';
		});
		postTypeHtml += '</div>';

		// Build tag filter HTML (conditionally)
		const showTagFilter = (typeof ILSEJ !== 'undefined' && ILSEJ.enableTagFilter);
		let tagHtml = '';
		if (showTagFilter) {
			const tagsList = window.ilsej_tags_list || [{ text: 'All', value: 'all' }];
			if (tagsList.length > 0 && tagsList[0].value !== 'all') {
				tagsList.unshift({ text: 'All', value: 'all' });
			}
			tagHtml = '<div id="ilsej-bulk-tag-container" style="max-height:120px; overflow-y:auto; border:1px solid #ccc; padding:8px; background:#fff;">';
			tagsList.forEach(function (t) {
				const chk = isFilterChecked(savedState.selectedTags, t.value) ? ' checked' : '';
				tagHtml += '<label style="display:block; margin-bottom:4px; font-size:13px;">' +
                    '<input type="checkbox" class="ilsej-bulk-tag-cb" value="' + escapeHtml(t.value) + '"' + chk + '> ' +
                    escapeHtml(t.text) +
                    '</label>';
			});
			tagHtml += '</div>';
		}

		// Build dynamic filter items based on enabled settings
		const showCatFilter = (typeof ILSEJ !== 'undefined' && ILSEJ.enableCategoryFilter);
		const filterItems = [];
		if (showCatFilter) {
			filterItems.push({
				type: 'container',
				html: '<label style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px;">Primary Category Filter</label>' + catHtml
			});
		}
		if (showTagFilter) {
			filterItems.push({
				type: 'container',
				html: '<label style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px;">Tags Filter</label>' + tagHtml
			});
		}
		filterItems.push({
			type: 'container',
			html: '<label style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px;">Post Type Filter</label>' + postTypeHtml
		});
		filterItems.push({
			type: 'container',
			items: [
				{ type: 'label', text: 'Article Age Filter:', style: 'font-weight:bold; font-size:12px; margin-bottom:5px; display:block;' },
				{ type: 'listbox', name: 'iage', id: 'article_age', values: ageOptions, style: 'min-width: 150px;' }
			]
		});

		const win = editor.windowManager.open({
			title: 'Internal Link Suggestions',
			width: 850,
			height: 600,
			buttons: [{ text: 'Close', onclick: function () { win.close(); } }],
			body: [
				{
					type: 'panel',
					layout: 'grid',
					columns: filterItems.length,
					spacing: 20,
					items: filterItems
				},
				{
					type: 'button',
					text: 'Run Bulk Suggestions',
					minWidth: 150,
					style: 'margin-top: 15px;',
					onclick: function () {
						runBulkAnalysis(editor, win);
					}
				},
				{
					type: 'container',
					html: '<div id="ilsej-results-area" style="margin-top:15px; height:400px; overflow-y:auto; border-top:1px solid #eee; padding:10px 0 100px 0;"></div>'
				}
			],
			onopen: function () { $('#mce-modal-block').remove(); },
			onclose: function () { unwrapHighlighted(editor); },
			onsubmit: function () { unwrapHighlighted(editor); }
		});

		setTimeout(function () {
			jQuery('.ilsej-bulk-cb').on('change', function () {
				const $this = jQuery(this);
				if ($this.val() === 'all') {
					jQuery('.ilsej-bulk-cb').not(this).prop('checked', $this.is(':checked'));
				} else if (!$this.is(':checked')) {
					jQuery('.ilsej-bulk-cb[value="all"]').prop('checked', false);
				} else {
					const allChecked = jQuery('.ilsej-bulk-cb').not('[value="all"]').length === jQuery('.ilsej-bulk-cb:checked').not('[value="all"]').length;
					if (allChecked) { jQuery('.ilsej-bulk-cb[value="all"]').prop('checked', true); }
				}
			});
			jQuery('.ilsej-bulk-tag-cb').on('change', function () {
				const $this = jQuery(this);
				if ($this.val() === 'all') {
					jQuery('.ilsej-bulk-tag-cb').not(this).prop('checked', $this.is(':checked'));
				} else if (!$this.is(':checked')) {
					jQuery('.ilsej-bulk-tag-cb[value="all"]').prop('checked', false);
				} else {
					const allChecked = jQuery('.ilsej-bulk-tag-cb').not('[value="all"]').length === jQuery('.ilsej-bulk-tag-cb:checked').not('[value="all"]').length;
					if (allChecked) { jQuery('.ilsej-bulk-tag-cb[value="all"]').prop('checked', true); }
				}
			});
			jQuery('.ilsej-bulk-posttype-cb').on('change', function () {
				const $this = jQuery(this);
				if ($this.val() === 'all') {
					jQuery('.ilsej-bulk-posttype-cb').not(this).prop('checked', $this.is(':checked'));
				} else if (!$this.is(':checked')) {
					jQuery('.ilsej-bulk-posttype-cb[value="all"]').prop('checked', false);
				} else {
					const allChecked = jQuery('.ilsej-bulk-posttype-cb').not('[value="all"]').length === jQuery('.ilsej-bulk-posttype-cb:checked').not('[value="all"]').length;
					if (allChecked) { jQuery('.ilsej-bulk-posttype-cb[value="all"]').prop('checked', true); }
				}
			});

			// Persist filter state to localStorage on any checkbox change.
			jQuery('.ilsej-bulk-cb, .ilsej-bulk-tag-cb, .ilsej-bulk-posttype-cb').on('change', function () {
				const cVals = []; jQuery('.ilsej-bulk-cb:checked').each(function () { cVals.push(jQuery(this).val()); });
				const tVals = []; jQuery('.ilsej-bulk-tag-cb:checked').each(function () { tVals.push(jQuery(this).val()); });
				const pVals = []; jQuery('.ilsej-bulk-posttype-cb:checked').each(function () { pVals.push(jQuery(this).val()); });
				saveFilterSelections(cVals.length ? cVals : ['all'], tVals.length ? tVals : ['all'], pVals.length ? pVals : ['all']);
			});
		}, 300);
	};

	function runBulkAnalysis(editor, win) {
		const $results = $('#ilsej-results-area');
		$results.html('<div style="padding:20px; text-align:center; color:#666;">Analyzing content and retrieving suggestions...</div>');

		let selectedCats = [];
		jQuery('#ilsej-bulk-cat-container .ilsej-bulk-cb:checked').each(function () { selectedCats.push(jQuery(this).val()); });
		if (selectedCats.indexOf('all') !== -1 || selectedCats.length === 0) { selectedCats = []; }

		let selectedTags = [];
		jQuery('#ilsej-bulk-tag-container .ilsej-bulk-tag-cb:checked').each(function () { selectedTags.push(jQuery(this).val()); });
		if (selectedTags.indexOf('all') !== -1 || selectedTags.length === 0) { selectedTags = []; }

		let selectedPostTypes = [];
		jQuery('#ilsej-bulk-posttype-container .ilsej-bulk-posttype-cb:checked').each(function () { selectedPostTypes.push(jQuery(this).val()); });
		if (selectedPostTypes.indexOf('all') !== -1 || selectedPostTypes.length === 0) { selectedPostTypes = []; }

		const rawAge = win.toJSON().iage;
		const finalAgeArray = (rawAge && typeof rawAge === 'string' && rawAge !== 'all') ? rawAge.split(',') : [];

		unwrapHighlighted(editor);
		const cleanContent = editor.getContent({ format: 'raw' });

		$.post(ILSEJ.ajaxUrl, {
			action: 'ilsej_get_suggestions',
			nonce: ILSEJ.nonce,
			content: cleanContent,
			post_id: ILSEJ.postId || 0,
			filters: { category: selectedCats, tags: selectedTags, post_type: selectedPostTypes, publish_year: finalAgeArray }
		}, function (resp) {
			if (!resp || !resp.success || !resp.data || !resp.data.suggestions || resp.data.suggestions.length === 0) {
				$results.html('<div style="padding:20px; text-align:center;">No suggestions found for these filters.</div>');
				$('#ilsej-bulk-toggles').hide();
				return;
			}

			const suggestions = resp.data.suggestions;
			const perf = resp.data.perf;
			currentSuggestionsCache = suggestions;
			let listHtml = '';
			const body = editor.getBody();
			const bodyContent = body.innerHTML;
			let modifiedContent = bodyContent;

			suggestions.forEach(function (item, index) {
				const meta = item.api.metadata || {};
				const title = decodeHTML(meta.title || 'No Title');
				const url = meta.url || '#';
				const date = meta.publish_date ? new Date(meta.publish_date).toLocaleDateString() : '';
				const score = item.api.score ? item.api.score.toFixed(3) : '0';

				const regex = createKeywordRegex(item.keyword);
				if (modifiedContent.match(regex)) {
					modifiedContent = modifiedContent.replace(regex, '$1<span class="ilsej-highlight" style="background-color: yellow;">$2</span>');
				}

				listHtml +=
                    '<div class="ilsej-row" style="display:flex; border-bottom:1px solid #f0f0f0; padding:15px 0;">' +
                    '<div style="width:30%; padding-right:10px; color:#333;">' +
                    '<div class="ilsej-scroll-trigger" data-keyword="' + escapeHtml(item.keyword) + '" data-url="' + escapeHtml(url) + '" style="font-weight:bold; cursor:pointer; color:#0073aa; display:flex; align-items:center;" title="Click to find in editor">' +
                    '<span style="margin-right:5px;">' + (index + 1) + '.</span>' +
                    '<span style="margin-right:5px;">📍</span>' +
                    '<span style="text-decoration:underline; text-decoration-style:dotted; text-wrap: auto; white-space: normal;">' + escapeHtml(item.keyword) + '</span>' +
                    '</div>' +
                    '<div style="font-weight:normal; font-size:11px; color:#999; margin-top:3px;text-wrap: auto;" title="An embedding was generated from the context text for the suggested phrase.">' + escapeHtml(item.context || '') + '</div>' +
                    '</div>' +
                    '<div style="width:45%; padding-right:10px;">' +
                    '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" style="font-weight:600; text-decoration:none; color:#0073aa;text-wrap: auto;">' + escapeHtml(title) + '</a>' +
                    '<div style="font-size:11px; color:#555; margin-top:3px;">' + escapeHtml(meta.category) + ' | ' + escapeHtml(date) + ' | Relevance Score: ' + escapeHtml(score) + '</div>' +
                    '</div>' +
                    '<div style="width:25%; display:flex; gap:5px; align-items: flex-start; justify-content: flex-end;">' +
                    '<button type="button" class="ilsej-apply-btn" data-url="' + escapeHtml(url) + '" data-keyword="' + escapeHtml(item.keyword) + '" style="cursor:pointer; background:#0073aa; color:#fff; border:none; padding:6px 10px; border-radius:3px; white-space: nowrap;">Apply</button>' +
                    '<button type="button" class="ilsej-copy-btn" data-url="' + escapeHtml(url) + '" style="cursor:pointer; background:#fff; border:1px solid #ccc; color:#555; padding:6px 10px; border-radius:3px; white-space: nowrap;">Copy URL</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
			});

			// Add a spacer at the end to prevent footer overlap
			listHtml += '<div style="height:80px; width:100%;"></div>';

			// Remove any highlights that landed inside heading tags (h1-h6)
			modifiedContent = stripHighlightsFromHeadings(modifiedContent);

			if (modifiedContent !== bodyContent) {
				const bookmark = editor.selection.getBookmark(2, true);
				const originalVisibility = body.style.visibility;
				body.style.visibility = 'hidden';
				editor.undoManager.transact(function () {
					editor.dom.setHTML(body, modifiedContent);
				});
				body.style.visibility = originalVisibility;
				try { editor.selection.moveToBookmark(bookmark); } catch (e) { /* Bookmark may be invalid after content change */ }
				editor.nodeChanged();
			}

			// Add toggle buttons above the results if not present
			if (!$('#ilsej-bulk-toggles').length) {
				$('<div id="ilsej-bulk-toggles" style="margin-bottom:10px; display:flex; gap:10px;">' +
                    '<button type="button" id="ilsej-clear-all" style="flex:1; cursor:pointer; background:#fff; border:1px solid #ccc; padding:6px; border-radius:3px;">Clear Highlights</button>' +
                    '<button type="button" id="ilsej-restore-all" style="flex:1; cursor:pointer; background:#0073aa; color:#fff; border:none; padding:6px; border-radius:3px; display:none;">Restore Highlights</button>' +
                    '</div>').insertBefore($results);
			}
			$results.html(listHtml);

			if (perf) {
				const perfHtml = '<div style="font-size:10px; color:#999; margin-top:15px; border-top:1px solid #eee; padding-top:8px; line-height:1.4;">' +
                    '<div style="font-weight:600; margin-bottom:3px; text-transform:uppercase;">Performance Scan Details</div>' +
                    (perf.extraction_provider ? 'Extraction (' + perf.extraction_provider + '): ' + parseFloat(perf.extraction_time).toFixed(3) + 's | ' : '') +
                    'Vertex AI: ' + parseFloat(perf.vertex_time).toFixed(3) + 's (' + perf.vertex_method + ') | ' +
                    'Pinecone: ' + parseFloat(perf.pinecone_time).toFixed(3) + 's (' + perf.pinecone_calls + ' calls)' +
                    '</div>';
				$results.append(perfHtml);
			}

			$('#ilsej-bulk-toggles').show();
			$('#ilsej-clear-all').show();
			$('#ilsej-restore-all').hide();

			$('#ilsej-clear-all').off('click').on('click', function () {
				unwrapHighlighted(editor);
				$(this).hide();
				$('#ilsej-restore-all').show();
			});

			$('#ilsej-restore-all').off('click').on('click', function () {
				restoreClassicHighlights(editor, currentSuggestionsCache);
				$(this).hide();
				$('#ilsej-clear-all').show();
			});

			$results.find('.ilsej-scroll-trigger').on('click', function () {
				const keyword = $(this).data('keyword');
				const linkUrl = $(this).data('url');
				let target = null;

				// First, look for an already-applied link matching this keyword + URL.
				if (linkUrl) {
					const links = editor.dom.select('a[href="' + linkUrl + '"]', editor.getBody());
					for (let j = 0; j < links.length; j++) {
						if ((links[j].innerText || '').trim().toLowerCase() === keyword.trim().toLowerCase()) { target = links[j]; break; }
					}
				}

				// Fall back to the first highlighted span (case-insensitive to match highlight regex).
				if (!target) {
					const spans = editor.dom.select('span.ilsej-highlight', editor.getBody());
					const kwLower = keyword.trim().toLowerCase();
					for (let i = 0; i < spans.length; i++) {
						if (spans[i].innerText.trim().toLowerCase() === kwLower) { target = spans[i]; break; }
					}
				}

				if (target) {
					target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
					editor.dom.setStyle(target, 'outline', '3px solid red');
					setTimeout(function () { editor.dom.setStyle(target, 'outline', 'none'); }, 2000);
				}
			});

			$results.find('.ilsej-apply-btn').on('click', function () {
				const btn = $(this);
				const url = btn.data('url');
				const keyword = btn.data('keyword');
				let applied = false;
				editor.undoManager.transact(function () {
					const highlights = editor.dom.select('span.ilsej-highlight', editor.getBody());
					for (let i = 0; i < highlights.length; i++) {
						const span = highlights[i];
						const spanText = span.textContent || span.innerText;
						if (spanText && spanText.toLowerCase().indexOf(keyword.toLowerCase()) !== -1) {
							const link = editor.dom.create('a', { href: url, target: '_blank' });
							while (span.firstChild) {
								link.appendChild(span.firstChild);
							}
							editor.dom.replace(link, span);
							applied = true;
							break; // Only link the first occurrence.
						}
					}
				});
				if (applied) {
					editor.nodeChanged();
					btn.text('Applied').css('background', '#46b450').prop('disabled', true);
					btn.closest('.ilsej-row').css('opacity', '0.5');
				}
			});

			$results.find('.ilsej-copy-btn').on('click', function () {
				const url = $(this).data('url');
				const $btn = $(this);
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(url).then(function () {
						$btn.text('Copied!'); setTimeout(function () { $btn.text('Copy URL'); }, 1500);
					});
				} else {
					const $temp = $('<input>'); $('body').append($temp); $temp.val(url).select(); document.execCommand('copy'); $temp.remove();
					$btn.text('Copied!'); setTimeout(function () { $btn.text('Copy URL'); }, 1500);
				}
			});

		}, 'json').fail(function () {
			$results.html('<div style="padding:20px; text-align:center; color:#d63638;">Network error — please try again.</div>');
		});
	}

	$(document).ready(function () {
		let highlightClearTimer = null;
		const saveButtons = '#publishing-action input[type="submit"], #publish, #save-post';

		$(document).on('mouseenter', saveButtons, function () {
			highlightClearTimer = setTimeout(function () {
				if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor) {
					unwrapHighlighted(tinyMCE.activeEditor);
					// Also toggle buttons in the modal if it's open
					$('#ilsej-clear-all').hide();
					$('#ilsej-restore-all').show();
				}
			}, 500);
		});

		$(document).on('mouseleave', saveButtons, function () {
			if (highlightClearTimer) {
				clearTimeout(highlightClearTimer);
				highlightClearTimer = null;
			}
		});
	});

})(jQuery);
