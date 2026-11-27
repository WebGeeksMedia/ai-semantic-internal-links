tinymce.PluginManager.add('ilsej', function (editor, url) {
	editor.addButton('ilsejSuggestLinks', {
		text: 'Suggest Links',
		icon: false,
		type: 'menubutton',
		menu: [
			{
				text: 'Internal Link Based on Anchor',
				onclick: function () {
					openAnchorModal(editor);
				}
			},
			{
				text: 'Bulk Auto Suggestion',
				onclick: function () {
					if (typeof window.fetchIlsejSuggestions === 'function') {
						window.fetchIlsejSuggestions(editor, 'bulk');
					}
				}
			}
		]
	});
});

(function ($) {

	// 0. HELPER FUNCTION: Decode HTML entities
	function decodeHTML(html) {
		if (!html) return html;
		const txt = document.createElement('textarea');
		txt.innerHTML = html;
		return txt.value;
	}

	function escapeHtml(str) {
		if (!str) return '';
		return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

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
		if (!savedArr || savedArr.length === 0) { return true; }
		if (savedArr.indexOf('all') !== -1) { return true; }
		return value === 'all' ? false : savedArr.indexOf(value) !== -1;
	}

	// 1. GLOBAL STORAGE FOR CATEGORIES, TAGS AND POST TYPES
	window.ilsej_categories_list = [];
	window.ilsej_tags_list = [];
	window.ilsej_post_types_list = [];

	// 2. FETCH CATEGORIES, TAGS AND POST TYPES ON LOAD (AJAX + DOM FALLBACK)
	$(document).ready(function () {
		// A. Try to scrape existing list
		const $existingCats = $('#categorychecklist label.selectit');
		if ($existingCats.length > 0) {
			$existingCats.each(function () {
				const catName = $(this).text().trim();
				if (catName) window.ilsej_categories_list.push({ text: catName, value: catName });
			});
		}

		// B. AJAX Fetch
		$.post(ILSEJ.ajaxUrl, {
			action: 'ilsej_get_all_editor_filters',
			nonce: ILSEJ.nonce
		}, function (resp) {
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

	// --- HELPER FUNCTION: OPEN MODAL ---
	window.openAnchorModal = function (editor) {

		// 1. Get Selection
		let selectedText = editor.selection.getContent({ format: 'text' });
		if (!selectedText) {
			selectedText = jQuery('input[name="post_title"]').val();
		}

		// 2. Build Freshness Options
		const ageOptions = [{ text: 'All', value: 'all' }];
		const currentYear = (new Date()).getFullYear();
		for (let i = 1; i <= 5; i++) {
			const years = [];
			for (let j = 0; j <= i; j++) {
				years.push(currentYear - j);
			}
			ageOptions.push({
				text: 'Up to ' + i + ' year(s) old',
				value: years.join(',') // e.g., "2025,2024"
			});
		}

		// 3. Build Multi-Select Category HTML
		const savedState = loadSavedFilters();
		let cats = window.ilsej_categories_list;
		if (!cats || cats.length === 0) {
			cats = [{ text: 'All', value: 'all' }];
		}
		if (cats[0].value !== 'all') {
			cats.unshift({ text: 'All', value: 'all' });
		}

		let catHtml = '<div id="ilsej-cat-container" style="max-height:120px; overflow-y:auto; border:1px solid #ccc; padding:8px; background:#fff;">';
		cats.forEach(function (c) {
			const chk = isFilterChecked(savedState.selectedCats, c.value) ? ' checked' : '';
			catHtml += '<label style="display:block; margin-bottom:4px; font-size:13px;">' +
                '<input type="checkbox" class="ilsej-cat-cb" value="' + escapeHtml(c.value) + '"' + chk + '> ' +
                escapeHtml(c.text) +
                '</label>';
		});
		catHtml += '</div>';

		// 3b. Build Multi-Select Tag HTML (conditionally)
		const showTagFilter = (typeof ILSEJ !== 'undefined' && ILSEJ.enableTagFilter);
		let tagHtml = '';
		if (showTagFilter) {
			let tagsList = window.ilsej_tags_list || [];
			if (!tagsList || tagsList.length === 0) {
				tagsList = [{ text: 'All', value: 'all' }];
			}
			if (tagsList[0].value !== 'all') {
				tagsList.unshift({ text: 'All', value: 'all' });
			}
			tagHtml = '<div id="ilsej-tag-container" style="max-height:120px; overflow-y:auto; border:1px solid #ccc; padding:8px; background:#fff;">';
			tagsList.forEach(function (t) {
				const chk = isFilterChecked(savedState.selectedTags, t.value) ? ' checked' : '';
				tagHtml += '<label style="display:block; margin-bottom:4px; font-size:13px;">' +
                    '<input type="checkbox" class="ilsej-tag-cb" value="' + escapeHtml(t.value) + '"' + chk + '> ' +
                    escapeHtml(t.text) +
                    '</label>';
			});
			tagHtml += '</div>';
		}

		// 3c. Build Multi-Select Post Type HTML
		let postTypes = window.ilsej_post_types_list;
		if (!postTypes || postTypes.length === 0) {
			postTypes = [{ text: 'All', value: 'all' }];
		}
		if (postTypes[0].value !== 'all') {
			postTypes.unshift({ text: 'All', value: 'all' });
		}

		let postTypeHtml = '<div id="ilsej-posttype-container" style="max-height:120px; overflow-y:auto; border:1px solid #ccc; padding:8px; background:#fff;">';
		postTypes.forEach(function (pt) {
			const chk = isFilterChecked(savedState.selectedPostTypes, pt.value) ? ' checked' : '';
			postTypeHtml += '<label style="display:block; margin-bottom:4px; font-size:13px;">' +
                '<input type="checkbox" class="ilsej-posttype-cb" value="' + escapeHtml(pt.value) + '"' + chk + '> ' +
                escapeHtml(pt.text) +
                '</label>';
		});
		postTypeHtml += '</div>';

		// Build dynamic filter items based on enabled settings
		const showCatFilter = (typeof ILSEJ !== 'undefined' && ILSEJ.enableCategoryFilter);
		const filterItems = [];
		if (showCatFilter) {
			filterItems.push({
				type: 'container',
				html: '<label style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px;">Primary Categories</label>' + catHtml
			});
		}
		if (showTagFilter) {
			filterItems.push({
				type: 'container',
				html: '<label style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px;">Tags</label>' + tagHtml
			});
		}
		filterItems.push({
			type: 'container',
			html: '<label style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px;">Post Types</label>' + postTypeHtml
		});
		filterItems.push({
			type: 'container',
			items: [
				{ type: 'label', text: 'Freshness:', style: 'font-weight:bold; font-size:12px; margin-bottom:5px; display:block;' },
				{ type: 'listbox', name: 'iage', values: ageOptions, style: 'min-width: 100%;' }
			]
		});

		// 4. Open Window
		const win = editor.windowManager.open({
			title: 'Internal Link Based on Anchor',
			width: 750,
			height: 550,
			buttons: [{ text: 'Close', onclick: function () { win.close(); } }],
			body: [
				{
					type: 'textbox',
					multiline: true,
					name: 'anchor_text',
					label: 'Change Text',
					value: selectedText,
					id: 'ilsej-anchor-text',
					minHeight: 50,
					style: 'margin-bottom: 20px;'
				},
				{
					type: 'panel',
					layout: 'grid',
					columns: filterItems.length,
					spacing: 20,
					items: filterItems
				},
				{
					type: 'button',
					text: 'Search Suggestions',
					classes: 'primary',
					style: 'margin-top: 5px; width: 100%;',
					onclick: function () {
						const anchor = jQuery('#ilsej-anchor-text').val();
						const $dynamic = jQuery('#ilsej-dynamic');

						// --- A. GATHER CATEGORIES ---
						let selectedCats = [];
						jQuery('#ilsej-cat-container .ilsej-cat-cb:checked').each(function () {
							selectedCats.push(jQuery(this).val());
						});

						// Logic: If 'all' is selected or nothing selected, pass empty array
						if (selectedCats.indexOf('all') !== -1 || selectedCats.length === 0) {
							selectedCats = [];
						}

						// --- B. GATHER TAGS ---
						let selectedTags = [];
						jQuery('#ilsej-tag-container .ilsej-tag-cb:checked').each(function () {
							selectedTags.push(jQuery(this).val());
						});
						if (selectedTags.indexOf('all') !== -1 || selectedTags.length === 0) {
							selectedTags = [];
						}

						// --- C. GATHER POST TYPES ---
						let selectedPostTypes = [];
						jQuery('#ilsej-posttype-container .ilsej-posttype-cb:checked').each(function () {
							selectedPostTypes.push(jQuery(this).val());
						});

						// Logic: If 'all' is selected or nothing selected, pass empty array
						if (selectedPostTypes.indexOf('all') !== -1 || selectedPostTypes.length === 0) {
							selectedPostTypes = [];
						}

						// --- C. GATHER AGE (PUBLISH YEAR) ---
						const rawAge = win.toJSON().iage;
						let finalAgeArray;

						// Logic: Parse string to array, or pass empty array if 'all'
						if (rawAge && typeof rawAge === 'string' && rawAge !== 'all') {
							finalAgeArray = rawAge.split(',');
						} else {
							finalAgeArray = [];
						}

						$dynamic.html('<div style="padding:20px; text-align:center; color:#666;">Searching...</div>');

						// Get current post ID from page or ILSEJ object
						let currentPostId = 0;
						if (typeof ILSEJ !== 'undefined' && ILSEJ.postId) {
							currentPostId = ILSEJ.postId;
						} else {
							// Try to get from WordPress post edit page
							const postIdInput = jQuery('input[name="post_ID"]');
							if (postIdInput.length) {
								currentPostId = parseInt(postIdInput.val()) || 0;
							}
						}

						jQuery.post(ILSEJ.ajaxUrl, {
							action: 'ilsej_get_suggestions',
							nonce: ILSEJ.nonce,
							mode: 'anchor',
							content: anchor,
							post_id: currentPostId, // Current post ID to exclude from suggestions
							filters: {
								category: selectedCats,
								tags: selectedTags,
								post_type: selectedPostTypes,
								publish_year: finalAgeArray
							}
						}, function (resp) {
							if (!resp || resp.success === false || !resp.data || !resp.data.suggestions) {
								$dynamic.html('<div style="color:red; padding:10px;">Failed to fetch suggestions.</div>');
								return;
							}

							if (resp.data.suggestions.length === 0) {
								$dynamic.html('<div style="padding:10px;">No suggestions found.</div>');
								return;
							}

							let html = '';
							const suggestions = resp.data.suggestions;
							const perf = resp.data.perf;

							suggestions.slice(0, 5).forEach(function (item, idx) {
								const date = new Date(item.metadata.publish_date);
								const dateStr = date.toLocaleDateString();
								const url = item.metadata.url || item.id;
								const title = decodeHTML(item.metadata.title || '');
								const score = item.score ? item.score.toFixed(3) : '0';

								html += '<div class="ilsej-suggest-row" style="border-bottom:1px solid #eee; padding:10px 0; display:flex; justify-content:space-between; align-items:center;">' +
                                    '<div style="max-width:65%;">' +
                                    '<a style="font-weight:600; color:#0073aa; text-decoration:none;" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' + (idx + 1) + '. ' + escapeHtml(title) + '</a>' +
                                    '<div style="font-size:11px; color:#555; margin-top:2px;">' + escapeHtml(item.metadata.category) + ' | ' + escapeHtml(dateStr) + ' | Relevance Score: ' + escapeHtml(score) + '</div>' +
                                    '</div>' +
                                    '<div style="display:flex; gap:5px;">' +
                                    '<button class="button apply-ilink" style="height:30px; line-height:28px; padding:0 10px;">Apply</button>' +
                                    '<button class="button ilsej-copy-url" data-url="' + escapeHtml(url) + '" style="height:30px; line-height:28px; padding:0 10px;">Copy</button>' +
                                    '</div></div>';
							});

							// Add a spacer to prevent footer overlap
							html += '<div style="height:80px; width:100%;"></div>';

							$dynamic.html(html);

							if (perf) {
								const perfHtml = '<div style="font-size:10px; color:#999; margin-top:10px; border-top:1px solid #eee; padding-top:8px;">' +
                                    '<div style="font-weight:600; margin-bottom:3px; text-transform:uppercase;">Performance Stats</div>' +
                                    (perf.extraction_provider ? 'Extraction (' + perf.extraction_provider + '): ' + parseFloat(perf.extraction_time).toFixed(3) + 's<br>' : '') +
                                    'Vertex AI: ' + parseFloat(perf.vertex_time).toFixed(3) + 's (' + perf.vertex_method + ')<br>' +
                                    'Pinecone: ' + parseFloat(perf.pinecone_time).toFixed(3) + 's (' + perf.pinecone_calls + ' calls)' +
                                    '</div>';
								$dynamic.append(perfHtml);
							}

							setTimeout(function () {
								const bodyHeight = $dynamic[0].scrollHeight + 400;
								win.resizeTo(750, Math.min(bodyHeight, 700));
							}, 50);

							jQuery('.apply-ilink').off('click').on('click', function () {
								const url = jQuery(this).closest('.ilsej-suggest-row').find('a').attr('href');
								const selectedText = editor.selection.getContent({ format: 'text' });
								const text = jQuery('#ilsej-anchor-text').val() || anchor;

								// If the user didn't change the text in the textbox, use the selection's HTML 
								// to preserve formatting (bold, etc.)
								if (text.trim() === selectedText.trim()) {
									editor.execCommand('CreateLink', false, url);
								} else {
									editor.execCommand('mceInsertContent', false, '<a href="' + url + '">' + text + '</a>');
								}
								win.close();
							});

							jQuery('.ilsej-copy-url').off('click').on('click', function () {
								const url = jQuery(this).data('url');
								const $btn = jQuery(this);
								const oldText = $btn.text();
								if (navigator.clipboard && navigator.clipboard.writeText) {
									navigator.clipboard.writeText(url).then(function () {
										$btn.text('Copied!');
										setTimeout(function () { $btn.text(oldText); }, 1500);
									});
								} else {
									const $temp = jQuery('<input>');
									jQuery('body').append($temp);
									$temp.val(url).select();
									document.execCommand('copy');
									$temp.remove();
									$btn.text('Copied!');
									setTimeout(function () { $btn.text(oldText); }, 1500);
								}
							});

						}, 'json').fail(function () {
							$dynamic.html('<div style="color:red; padding:10px;">Network error — please try again.</div>');
						});
					}
				},
				{
					type: 'panel',
					html: '<div id="ilsej-dynamic" style="max-height:300px; overflow-y:auto; margin-top:15px; border-top:1px solid #ddd; padding-bottom:100px;"></div>'
				}
			],
			onopen: function () { $('#mce-modal-block').remove(); }
		});

		// 5. Handle "All" checkbox logic for Categories
		setTimeout(function () {
			jQuery('.ilsej-cat-cb').on('change', function () {
				const $this = jQuery(this);
				if ($this.val() === 'all') {
					jQuery('.ilsej-cat-cb').not(this).prop('checked', $this.is(':checked'));
				} else if (!$this.is(':checked')) {
					jQuery('.ilsej-cat-cb[value="all"]').prop('checked', false);
				} else {
					const allChecked = jQuery('.ilsej-cat-cb').not('[value="all"]').length === jQuery('.ilsej-cat-cb:checked').not('[value="all"]').length;
					if (allChecked) { jQuery('.ilsej-cat-cb[value="all"]').prop('checked', true); }
				}
			});
		}, 300);

		// 6. Handle "All" checkbox logic for Tags
		setTimeout(function () {
			jQuery('.ilsej-tag-cb').on('change', function () {
				const $this = jQuery(this);
				if ($this.val() === 'all') {
					jQuery('.ilsej-tag-cb').not(this).prop('checked', $this.is(':checked'));
				} else if (!$this.is(':checked')) {
					jQuery('.ilsej-tag-cb[value="all"]').prop('checked', false);
				} else {
					const allChecked = jQuery('.ilsej-tag-cb').not('[value="all"]').length === jQuery('.ilsej-tag-cb:checked').not('[value="all"]').length;
					if (allChecked) { jQuery('.ilsej-tag-cb[value="all"]').prop('checked', true); }
				}
			});
		}, 300);

		// 7. Handle "All" checkbox logic for Post Types
		setTimeout(function () {
			jQuery('.ilsej-posttype-cb').on('change', function () {
				const $this = jQuery(this);
				if ($this.val() === 'all') {
					jQuery('.ilsej-posttype-cb').not(this).prop('checked', $this.is(':checked'));
				} else if (!$this.is(':checked')) {
					jQuery('.ilsej-posttype-cb[value="all"]').prop('checked', false);
				} else {
					const allChecked = jQuery('.ilsej-posttype-cb').not('[value="all"]').length === jQuery('.ilsej-posttype-cb:checked').not('[value="all"]').length;
					if (allChecked) { jQuery('.ilsej-posttype-cb[value="all"]').prop('checked', true); }
				}
			});
		}, 300);

		// 8. Persist filter state to localStorage on any checkbox change.
		setTimeout(function () {
			jQuery('.ilsej-cat-cb, .ilsej-tag-cb, .ilsej-posttype-cb').on('change', function () {
				const cVals = []; jQuery('.ilsej-cat-cb:checked').each(function () { cVals.push(jQuery(this).val()); });
				const tVals = []; jQuery('.ilsej-tag-cb:checked').each(function () { tVals.push(jQuery(this).val()); });
				const pVals = []; jQuery('.ilsej-posttype-cb:checked').each(function () { pVals.push(jQuery(this).val()); });
				saveFilterSelections(cVals.length ? cVals : ['all'], tVals.length ? tVals : ['all'], pVals.length ? pVals : ['all']);
			});
		}, 300);
	};

})(jQuery);