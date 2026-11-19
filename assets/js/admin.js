/**
 * Directory edit screen: show the settings that fit the selected type and,
 * for groups, the selected Default View.
 *
 * The type select lives in the Directory panels (`data-saved-type`), as does
 * the Default View select (`data-wd-view-select`); the elements they toggle
 * can be anywhere in the settings box:
 *
 * - `[data-wd-type="…"]` shows only for that type, or those types when it
 *   lists several separated by spaces.
 * - `[data-wd-view="…"]` shows only for that Default View (or views), on top
 *   of its `data-wd-type`.
 * - `[data-wd-card="group|member"]` is a group directory's Group card or
 *   Group Member card settings. The Group List view shows the first, Group
 *   Members the second, and Group List + Member List the one picked with the
 *   card switch (`[data-wd-card-switch]` radios).
 * - `[data-wd-type-changed]` shows only while the selected type differs from
 *   the saved one; `[data-wd-type-unchanged]` only while it matches.
 *
 * Plain script, no build step. Without it the box shows the saved type's
 * settings, every tab panel and every item's options.
 */
( function () {
	const box = document.querySelector( '[data-wicket-directory-settings]' );

	if ( ! box ) {
		return;
	}

	const savedType = box.dataset.savedType;
	const select = box.querySelector( 'select[name$="[type]"]' );
	const viewSelect = box.querySelector( '[data-wd-view-select]' );
	const cardSwitch = Array.from(
		document.querySelectorAll( '[data-wd-card-switch]' )
	);
	const views = { groups: 'group', members: 'member' };

	const toggle = ( selector, hide ) => {
		document.querySelectorAll( selector ).forEach( ( el ) => {
			el.hidden = hide( el );
		} );
	};

	const lists = ( value, item ) => value.split( ' ' ).includes( item );

	const update = () => {
		const type = select ? select.value : savedType;
		const view = viewSelect ? viewSelect.value : '';
		const card =
			views[ view ] ||
			cardSwitch.find( ( input ) => input.checked )?.value ||
			'group';

		toggle(
			'[data-wd-type], [data-wd-view], [data-wd-card]',
			( el ) =>
				( el.dataset.wdType !== undefined &&
					! lists( el.dataset.wdType, type ) ) ||
				( el.dataset.wdView !== undefined &&
					! lists( el.dataset.wdView, view ) ) ||
				( el.dataset.wdCard !== undefined &&
					el.dataset.wdCard !== card )
		);
		toggle( '[data-wd-type-changed]', () => type === savedType );
		toggle( '[data-wd-type-unchanged]', () => type !== savedType );
	};

	select?.addEventListener( 'change', update );
	viewSelect?.addEventListener( 'change', update );
	cardSwitch.forEach( ( input ) =>
		input.addEventListener( 'change', update )
	);

	update();
} )();

/**
 * Tabs: show one panel at a time, and remember the last one for this session.
 */
( function () {
	const bar = document.querySelector( '[data-wd-tabs]' );

	if ( ! bar ) {
		return;
	}

	const storageKey = 'wicket-directory-admin-tab';
	const tabs = Array.from( bar.querySelectorAll( '[data-wd-tab]' ) );
	const panels = document.querySelectorAll( '[data-wd-tab-panel]' );

	const show = ( key, focus = false ) => {
		tabs.forEach( ( tab ) => {
			const active = tab.dataset.wdTab === key;

			tab.classList.toggle( 'is-active', active );
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			tab.tabIndex = active ? 0 : -1;

			if ( active && focus ) {
				tab.focus();
			}
		} );
		panels.forEach( ( panel ) => {
			panel.hidden = panel.dataset.wdTabPanel !== key;
		} );

		try {
			window.sessionStorage.setItem( storageKey, key );
		} catch ( e ) {
			// Storage can be unavailable; the first tab shows next time.
		}
	};

	let initial = tabs[ 0 ].dataset.wdTab;

	try {
		const stored = window.sessionStorage.getItem( storageKey );

		if ( tabs.some( ( tab ) => tab.dataset.wdTab === stored ) ) {
			initial = stored;
		}
	} catch ( e ) {
		// Keep the first tab.
	}

	bar.addEventListener( 'click', ( event ) => {
		const tab = event.target.closest( '[data-wd-tab]' );

		if ( tab ) {
			show( tab.dataset.wdTab );
		}
	} );

	bar.addEventListener( 'keydown', ( event ) => {
		const step = { ArrowRight: 1, ArrowLeft: -1 }[ event.key ];
		const current = tabs.indexOf( event.target );

		if ( step && current !== -1 ) {
			event.preventDefault();
			show(
				tabs[ ( current + step + tabs.length ) % tabs.length ].dataset
					.wdTab,
				true
			);
		}
	} );

	bar.hidden = false;
	show( initial );
} )();

/**
 * Popovers (`details[data-wd-popover]`): one open at a time; a click outside
 * or Escape closes it.
 */
( function () {
	const popovers = () =>
		document.querySelectorAll( 'details[data-wd-popover][open]' );

	document.addEventListener( 'click', ( event ) => {
		popovers().forEach( ( popover ) => {
			if ( ! popover.contains( event.target ) ) {
				popover.open = false;
			}
		} );
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key !== 'Escape' ) {
			return;
		}

		popovers().forEach( ( popover ) => {
			popover.open = false;

			if ( popover.contains( popover.ownerDocument.activeElement ) ) {
				popover.querySelector( 'summary' ).focus();
			}
		} );
	} );
} )();

/**
 * Chip pickers: the chips mirror the ticked checkboxes in the Add menu, and a
 * chip's remove button unticks its checkbox.
 */
( function () {
	document.querySelectorAll( '[data-wd-chips]' ).forEach( ( picker ) => {
		const list = picker.querySelector( '[data-wd-chips-list]' );
		const boxes = Array.from(
			picker.querySelectorAll( 'input[type="checkbox"]' )
		);
		const removeLabel = picker.dataset.removeLabel || '%s';

		const chip = ( input ) => {
			const item = document.createElement( 'li' );
			const label = document.createElement( 'span' );
			const remove = document.createElement( 'button' );
			const icon = document.createElement( 'span' );
			const text = document.createElement( 'span' );

			item.className = 'wd-chip';
			item.dataset.value = input.value;
			label.className = 'wd-chip__label';
			label.textContent = input.dataset.label;
			remove.type = 'button';
			remove.className = 'wd-chip__remove';
			remove.dataset.wdChipRemove = '';
			icon.className = 'dashicons dashicons-no-alt';
			icon.setAttribute( 'aria-hidden', 'true' );
			text.className = 'screen-reader-text';
			text.textContent = removeLabel.replace( '%s', input.dataset.label );
			remove.append( icon, text );
			item.append( label, remove );

			return item;
		};

		const render = () => {
			list.replaceChildren(
				...boxes.filter( ( input ) => input.checked ).map( chip )
			);
		};

		picker.addEventListener( 'change', render );

		list.addEventListener( 'click', ( event ) => {
			const item = event.target
				.closest( '[data-wd-chip-remove]' )
				?.closest( '.wd-chip' );
			const input = boxes.find(
				( candidate ) => candidate.value === item?.dataset.value
			);

			if ( input ) {
				input.checked = false;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				picker.querySelector( 'summary' ).focus();
			}
		} );

		render();
	} );
} )();

/**
 * Card View: a ticked item reveals its options. A data-field item's picker is
 * disabled while it's unticked, so the reference saves as off. A contact
 * item shows the hint for its ticked flags; the Organization Contact item the
 * hint for its chosen source (`data-wd-mode` radios).
 *
 * Items can nest (Organization Contact holds an email and a phone rule), so
 * each item only reads and toggles its own elements, not a nested item's.
 */
( function () {
	const items = document.querySelectorAll( '[data-wd-item]' );

	const own = ( item, selector ) =>
		Array.from( item.querySelectorAll( selector ) ).filter(
			( el ) => el.closest( '[data-wd-item]' ) === item
		);

	const reveal = ( item, focus = false ) => {
		const on = own( item, '[data-wd-reveal-toggle]' )[ 0 ]?.checked;
		const options = own( item, '[data-wd-reveal]' )[ 0 ];

		if ( ! options ) {
			return;
		}

		options.hidden = ! on;

		if ( item.dataset.wdItem === 'ref' ) {
			const picker = options.querySelector( 'select' );

			picker.disabled = ! on;

			if ( on && focus ) {
				picker.focus();
			}
		}
	};

	const hint = ( item ) => {
		const flag = ( name ) =>
			own( item, `[data-wd-flag="${ name }"]` )[ 0 ]?.checked;
		const mode = own( item, '[data-wd-mode]' ).find(
			( input ) => input.checked
		);
		const directory = flag( 'directory' );
		const primary = flag( 'primary' );
		let state = 'none';

		if ( mode ) {
			state = mode.value;
		} else if ( directory && primary ) {
			state = 'both';
		} else if ( directory ) {
			state = 'directory';
		} else if ( primary ) {
			state = 'primary';
		}

		own( item, '[data-wd-hint]' ).forEach( ( el ) => {
			el.hidden = el.dataset.wdHint !== state;
		} );
	};

	items.forEach( ( item ) => {
		item.addEventListener( 'change', ( event ) => {
			// A nested item handles its own changes.
			if ( event.target.closest( '[data-wd-item]' ) !== item ) {
				return;
			}

			if ( event.target.matches( '[data-wd-reveal-toggle]' ) ) {
				reveal( item, true );
			} else if (
				event.target.matches( '[data-wd-flag], [data-wd-mode]' )
			) {
				hint( item );
			}
		} );

		reveal( item );
		hint( item );
	} );
} )();

/**
 * Card View layout: Standard (the checklist) or Advance (the card template).
 *
 * Each card's settings (`[data-wd-layout]`; a group directory has two) show
 * the panel of their checked `data-wd-layout-mode` radio. The dirty flag
 * (`[data-wd-dirty]`, disabled until now so it posts only with the script)
 * says whether the admin changed the template:
 *
 * - typing in the template or inserting a field sets it;
 * - Standard → Advance fills the template with the one the checklist stands
 *   for (`data-wd-generated`, as last saved) unless it's dirty and not empty;
 *   a note says so when the checklist has changed since the page loaded;
 * - Advance → Standard clears the template and the flag, after a confirm
 *   (`data-wd-confirm`) when it's dirty; cancelling stays in Advance.
 *
 * The "Select a Field…" picker and the custom field path insert `{{tag}}` at
 * the cursor. Neither posts.
 */
( function () {
	document.querySelectorAll( '[data-wd-layout]' ).forEach( ( root ) => {
		const radios = Array.from(
			root.querySelectorAll( '[data-wd-layout-mode]' )
		);
		const editor = root.querySelector(
			'[data-wd-layout-panel="advanced"]'
		);

		if ( ! radios.length || ! editor ) {
			return;
		}

		const panels = root.querySelectorAll( '[data-wd-layout-panel]' );
		const standard = root.querySelector(
			'[data-wd-layout-panel="standard"]'
		);
		const template = editor.querySelector( '[data-wd-template]' );
		const dirtyInput = editor.querySelector( '[data-wd-dirty]' );
		const stale = editor.querySelector( '[data-wd-stale]' );
		const tools = editor.querySelector( '[data-wd-editor-tools]' );
		const picker = editor.querySelector( '[data-wd-field-picker]' );
		const customToggle = editor.querySelector( '[data-wd-custom-toggle]' );
		const custom = editor.querySelector( '[data-wd-custom]' );
		const customPath = editor.querySelector( '[data-wd-custom-path]' );
		const customInsert = editor.querySelector( '[data-wd-custom-insert]' );
		let dirty = dirtyInput.value === '1';
		let standardChanged = false;
		let mode = radios.find( ( radio ) => radio.checked )?.value;

		const setDirty = ( value ) => {
			dirty = value;
			dirtyInput.value = value ? '1' : '0';
		};

		const show = () => {
			panels.forEach( ( panel ) => {
				panel.hidden = panel.dataset.wdLayoutPanel !== mode;
			} );
		};

		const select = ( value ) => {
			radios.forEach( ( radio ) => {
				radio.checked = radio.value === value;
			} );
		};

		const insert = ( tag ) => {
			const token = `{{${ tag }}}`;

			template.focus();
			template.setRangeText(
				token,
				template.selectionStart,
				template.selectionEnd,
				'end'
			);
			setDirty( true );
		};

		radios.forEach( ( radio ) =>
			radio.addEventListener( 'change', () => {
				if ( ! radio.checked || radio.value === mode ) {
					return;
				}

				const empty = template.value.trim() === '';

				if ( radio.value === 'advanced' ) {
					if ( ! dirty || empty ) {
						template.value = editor.dataset.wdGenerated || '';
						setDirty( false );
						stale.hidden = ! standardChanged;
					}
				} else {
					if (
						dirty &&
						! empty &&
						// eslint-disable-next-line no-alert
						! window.confirm( editor.dataset.wdConfirm )
					) {
						select( mode );
						return;
					}

					template.value = '';
					setDirty( false );
					stale.hidden = true;
				}

				mode = radio.value;
				show();
			} )
		);

		standard?.addEventListener( 'change', () => {
			standardChanged = true;
		} );

		template.addEventListener( 'input', () => setDirty( true ) );

		picker.addEventListener( 'change', () => {
			if ( picker.value !== '' ) {
				insert( picker.value );
				picker.value = '';
			}
		} );

		customToggle.addEventListener( 'click', () => {
			custom.hidden = ! custom.hidden;
			customToggle.setAttribute(
				'aria-expanded',
				custom.hidden ? 'false' : 'true'
			);

			if ( ! custom.hidden ) {
				customPath.focus();
			}
		} );

		const insertCustom = () => {
			const tag = customPath.value.replace( /[{}\s]/g, '' );

			if ( tag === '' ) {
				customPath.focus();
				return;
			}

			insert( tag );
			customPath.value = '';
		};

		customInsert.addEventListener( 'click', insertCustom );

		// Enter inserts the path instead of saving the post.
		customPath.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				insertCustom();
			}
		} );

		dirtyInput.disabled = false;
		tools.hidden = false;
		show();
	} );
} )();

/**
 * Filters tab: add, move and remove facet rows.
 *
 * Rows post `{name}[i][source|schema_key|field|label]`; after every change the
 * rows are renumbered in DOM order, so the saved order is the order shown.
 * Picker choices already in the list are disabled: a facet can be added once.
 * The picker's search box filters its choices by label and code. A group
 * directory has two lists (the group list's and the member lists'), each
 * with its own name and id prefix.
 */
document
	.querySelectorAll( '[data-wicket-directory-filters]' )
	.forEach( ( box ) => {
		const name = box.dataset.name;
		const idPrefix = box.dataset.idPrefix || 'wicket-directory-facet';
		const rows = box.querySelector( '[data-wd-facet-rows]' );
		const empty = box.querySelector( '[data-wd-facet-empty]' );
		const picker = box.querySelector( '[data-wd-facet-picker]' );
		const search = box.querySelector( '[data-wd-facet-search]' );
		const noMatch = box.querySelector( '[data-wd-facet-no-match]' );
		const choices = Array.from(
			box.querySelectorAll( '[data-wd-facet-choice]' )
		);
		const template = box.querySelector( '[data-wd-facet-template]' );

		const sync = () => {
			const all = Array.from(
				rows.querySelectorAll( '[data-wd-facet-row]' )
			);
			const keys = all.map( ( row ) => row.dataset.facetKey );

			all.forEach( ( row, index ) => {
				row.querySelectorAll( '[data-wd-facet-input]' ).forEach(
					( input ) => {
						input.name = `${ name }[${ index }][${ input.dataset.wdFacetInput }]`;
					}
				);
				row.querySelector( '[data-wd-facet-move="-1"]' ).disabled =
					index === 0;
				row.querySelector( '[data-wd-facet-move="1"]' ).disabled =
					index === all.length - 1;
			} );

			choices.forEach( ( choice ) => {
				choice.disabled = keys.includes( choice.value );
			} );

			empty.hidden = keys.length > 0;
		};

		const filter = () => {
			const query = search.value.trim().toLowerCase();
			let shown = 0;

			box.querySelectorAll( '[data-wd-facet-group]' ).forEach(
				( group ) => {
					let groupShown = 0;

					group
						.querySelectorAll( '[data-wd-facet-choice]' )
						.forEach( ( choice ) => {
							const match =
								query === '' ||
								choice.textContent
									.toLowerCase()
									.includes( query );

							choice.hidden = ! match;
							groupShown += match ? 1 : 0;
						} );

					group.hidden = groupShown === 0;
					shown += groupShown;
				}
			);

			noMatch.hidden = shown > 0;
		};

		const add = ( choice ) => {
			if ( choice.disabled ) {
				return;
			}

			const row = template.content
				.querySelector( '[data-wd-facet-row]' )
				.cloneNode( true );
			const values = {
				source: choice.dataset.source,
				schema_key: choice.dataset.schemaKey,
				field: choice.dataset.field,
				label: choice.dataset.default,
			};

			row.dataset.facetKey = choice.value;
			row.querySelector( '[data-wd-facet-name]' ).textContent =
				choice.dataset.name;
			row.querySelector( '[data-wd-facet-code]' ).textContent =
				choice.dataset.code;
			row.querySelectorAll( '[data-wd-facet-input]' ).forEach(
				( input ) => {
					input.value = values[ input.dataset.wdFacetInput ];
				}
			);

			const label = row.querySelector( '[data-wd-facet-input="label"]' );
			label.placeholder = choice.dataset.placeholder;
			label.id = `${ idPrefix }-${ choice.value }`;
			row.querySelector( 'label' ).htmlFor = label.id;

			rows.append( row );
			sync();
			picker.open = false;
			search.value = '';
			filter();
			label.focus();
		};

		picker.addEventListener( 'toggle', () => {
			if ( picker.open ) {
				search.focus();
			}
		} );

		search.addEventListener( 'input', filter );

		// Enter in the search box adds the first match instead of saving the post.
		search.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Enter' ) {
				event.preventDefault();

				const first = choices.find(
					( choice ) => ! choice.hidden && ! choice.disabled
				);

				if ( first ) {
					add( first );
				}
			}
		} );

		box.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( 'button' );
			const row = button?.closest( '[data-wd-facet-row]' );

			if ( ! button ) {
				return;
			}

			if ( button.matches( '[data-wd-facet-choice]' ) ) {
				add( button );
			} else if ( row && button.matches( '[data-wd-facet-remove]' ) ) {
				row.remove();
				sync();
				picker.querySelector( 'summary' ).focus();
			} else if ( row && button.matches( '[data-wd-facet-move]' ) ) {
				const up = button.dataset.wdFacetMove === '-1';
				const sibling = up
					? row.previousElementSibling
					: row.nextElementSibling;

				if ( sibling ) {
					sibling.insertAdjacentElement(
						up ? 'beforebegin' : 'afterend',
						row
					);
					sync();
					( button.disabled
						? row.querySelector(
								`[data-wd-facet-move="${ up ? 1 : -1 }"]`
						  )
						: button
					).focus();
				}
			}
		} );

		sync();
		filter();
	} );

/**
 * Developer tab: Copy buttons (`[data-wd-copy]`) put their text on the
 * clipboard and say "Copied" (`data-wd-copied`) for a moment. When the browser
 * refuses, the name next to the button is selected instead, ready for
 * Ctrl/Cmd+C. The buttons stay hidden where the Clipboard API isn't available
 * (it needs a secure context); the names can still be selected by hand.
 */
( function () {
	const buttons = document.querySelectorAll( '[data-wd-copy]' );

	if ( ! buttons.length || ! window.navigator.clipboard ) {
		return;
	}

	buttons.forEach( ( button ) => {
		const label = button.textContent.trim();
		let timer;

		button.hidden = false;
		button.addEventListener( 'click', () => {
			window.navigator.clipboard
				.writeText( button.dataset.wdCopy )
				.then( () => {
					button.textContent = button.dataset.wdCopied || 'Copied';
					window.clearTimeout( timer );
					timer = window.setTimeout( () => {
						button.textContent = label;
					}, 1500 );
				} )
				.catch( () => {
					const code = button.parentElement.querySelector( 'code' );
					const range = code.ownerDocument.createRange();
					const selection =
						code.ownerDocument.defaultView.getSelection();

					range.selectNodeContents( code );
					selection.removeAllRanges();
					selection.addRange( range );
				} );
		} );
	} );
} )();

/**
 * Card View: the live card preview (`[data-wd-preview]`).
 *
 * While the panel is on screen, every change in the settings box posts the
 * box's card-related sections (type, eligibility, cards, member list) to the
 * preview endpoint (`data-url`, REST nonce `data-nonce`), a moment after the
 * last change. The server renders one card with them; nothing is saved. The
 * first response also brings the frame's document (the site's stylesheets),
 * set once as the frame's `srcdoc`; later cards replace the root's content.
 *
 * - The card: a group directory's Group Member card when its settings are
 *   the ones shown (`[data-wd-card="member"]` not hidden), else the main card.
 * - Width (`data-wd-preview-width`): the frame takes the width's pixels
 *   (`data-widths`) and is scaled down when the panel is narrower, so the
 *   theme's breakpoints see a real desktop, tablet or phone. Remembered in
 *   localStorage.
 * - Previous / Next step through the matching records.
 * - Links and forms in the card do nothing.
 *
 * A failed request says so and keeps the last card. WordPress's heartbeat
 * refreshes the REST nonce on a long edit.
 */
( function () {
	const panel = document.querySelector( '[data-wd-preview]' );
	const box = panel?.closest( '.wicket-directory-admin' );

	if ( ! panel || ! box || ! window.fetch || ! window.URLSearchParams ) {
		return;
	}

	const form = box.closest( 'form' );
	const stage = panel.querySelector( '[data-wd-preview-stage]' );
	const frame = panel.querySelector( '[data-wd-preview-frame]' );
	const status = panel.querySelector( '[data-wd-preview-status]' );
	const records = panel.querySelector( '[data-wd-preview-records]' );
	const widthButtons = Array.from(
		panel.querySelectorAll( '[data-wd-preview-width]' )
	);
	const texts = JSON.parse( panel.dataset.texts || '{}' );
	const widths = JSON.parse( panel.dataset.widths || '{}' );
	const storageKey = 'wicket-directory-preview-width';
	const sections =
		/^wicket_directory\[(type|eligibility|card|member_card|roster)\]/;

	let nonce = panel.dataset.nonce;
	let width = Object.keys( widths )[ 0 ];
	let sample = 1;
	let total = 0;
	let visible = false;
	let pending = true;
	let timer;
	let lastKey = '';
	let request = 0;
	let controller;
	let frameState = 'none'; // none | loading | ready
	let queued = null;
	let observer;

	try {
		const stored = window.localStorage.getItem( storageKey );

		if ( stored && widths[ stored ] ) {
			width = stored;
		}
	} catch ( e ) {
		// Storage can be unavailable; keep the first width.
	}

	const format = ( text, ...values ) =>
		values.reduce(
			( result, value, index ) =>
				result.replace( `%${ index + 1 }$s`, String( value ) ),
			text || ''
		);

	const say = ( text, kind = '' ) => {
		status.textContent = text;
		status.dataset.kind = kind;
	};

	const card = () => {
		const member = box.querySelector(
			'[data-wd-tab-panel="card"] [data-wd-card="member"]'
		);

		return member && ! member.hidden ? 'member' : 'card';
	};

	const collect = () => {
		const params = new window.URLSearchParams();

		if ( form ) {
			new window.FormData( form ).forEach( ( value, name ) => {
				if ( typeof value === 'string' && sections.test( name ) ) {
					params.append( name, value );
				}
			} );
		}

		params.set( 'card', card() );
		params.set( 'sample', String( sample ) );

		return params;
	};

	const doc = () => {
		try {
			return frame.contentDocument;
		} catch ( e ) {
			return null;
		}
	};

	const layout = () => {
		const target = widths[ width ] || stage.clientWidth;
		const scale = Math.min( 1, stage.clientWidth / target );
		const frameDoc = frameState === 'ready' ? doc() : null;
		const height = frameDoc
			? Math.ceil( frameDoc.documentElement.scrollHeight )
			: 0;

		frame.style.width = `${ target }px`;
		frame.style.height = `${ height }px`;
		frame.style.transform = scale < 1 ? `scale(${ scale })` : '';
		stage.style.height = height ? `${ Math.ceil( height * scale ) }px` : '';
		stage.classList.toggle( 'is-scaled', scale < 1 );
	};

	const setWidth = ( key ) => {
		width = key;
		widthButtons.forEach( ( button ) => {
			button.setAttribute(
				'aria-pressed',
				button.dataset.wdPreviewWidth === key ? 'true' : 'false'
			);
		} );

		try {
			window.localStorage.setItem( storageKey, key );
		} catch ( e ) {
			// Not remembered; fine.
		}

		layout();
	};

	const inject = ( data ) => {
		const root = doc()?.getElementById( 'wicket-directory-preview-root' );

		if ( ! root ) {
			return;
		}

		root.innerHTML = data.html || '';
		layout();
	};

	const show = ( data ) => {
		total = Number( data.total ) || 0;
		sample = Number( data.number ) || 1;
		records.hidden = data.sample !== '' || total < 2;

		if ( data.sample ) {
			say( texts[ data.sample ] || '', 'sample' );
		} else if ( data.group ) {
			say( format( texts.member, sample, total, data.group ) );
		} else if ( total > 0 ) {
			say( format( texts.record, sample, total ) );
		} else {
			say( '' );
		}

		if ( data.frame && frameState === 'none' ) {
			frameState = 'loading';
			queued = data;
			frame.srcdoc = data.frame;
		} else if ( frameState === 'loading' ) {
			queued = data;
		} else {
			inject( data );
		}
	};

	const refresh = ( force = false ) => {
		window.clearTimeout( timer );

		if ( ! visible ) {
			pending = true;
			return;
		}

		pending = false;

		const params = collect();
		const key = params.toString();

		if ( ! force && key === lastKey ) {
			return;
		}

		lastKey = key;

		if ( frameState === 'none' ) {
			params.set( 'frame', '1' );
		}

		const current = ++request;

		controller?.abort();
		controller = window.AbortController
			? new window.AbortController()
			: null;
		panel.setAttribute( 'aria-busy', 'true' );
		say( texts.loading, 'loading' );

		window
			.fetch( panel.dataset.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce },
				body: params,
				signal: controller?.signal,
			} )
			.then( ( response ) =>
				response.ok
					? response.json()
					: Promise.reject( new Error( String( response.status ) ) )
			)
			.then( ( data ) => {
				if ( current !== request ) {
					return;
				}

				show( data );
				// The server may have moved to another record (past the last one).
				lastKey = collect().toString();
			} )
			.catch( ( error ) => {
				if ( current !== request || error?.name === 'AbortError' ) {
					return;
				}

				lastKey = '';
				say( texts.error, 'error' );
			} )
			.finally( () => {
				if ( current === request ) {
					panel.removeAttribute( 'aria-busy' );
				}
			} );
	};

	const schedule = () => {
		window.clearTimeout( timer );
		timer = window.setTimeout( refresh, 400 );
	};

	frame.addEventListener( 'load', () => {
		const frameDoc = doc();

		if ( frameState !== 'loading' || ! frameDoc ) {
			return;
		}

		frameState = 'ready';

		// A preview: links and forms in the card go nowhere.
		frameDoc.addEventListener( 'click', ( event ) => {
			if ( event.target.closest?.( 'a[href], button[type="submit"]' ) ) {
				event.preventDefault();
			}
		} );
		frameDoc.addEventListener( 'submit', ( event ) =>
			event.preventDefault()
		);

		const FrameObserver = frame.contentWindow?.ResizeObserver;

		if ( FrameObserver ) {
			new FrameObserver( layout ).observe( frameDoc.documentElement );
		}

		if ( queued ) {
			inject( queued );
			queued = null;
		}
	} );

	[ 'input', 'change' ].forEach( ( type ) =>
		box.addEventListener( type, ( event ) => {
			if ( ! panel.contains( event.target ) ) {
				schedule();
			}
		} )
	);

	// Buttons that change settings without an input event (insert a custom field path, remove a chip).
	box.addEventListener( 'click', ( event ) => {
		if (
			! panel.contains( event.target ) &&
			event.target.closest?.( 'button' )
		) {
			schedule();
		}
	} );

	widthButtons.forEach( ( button ) =>
		button.addEventListener( 'click', () =>
			setWidth( button.dataset.wdPreviewWidth )
		)
	);

	panel.querySelectorAll( '[data-wd-preview-step]' ).forEach( ( button ) =>
		button.addEventListener( 'click', () => {
			if ( total > 1 ) {
				const step = Number( button.dataset.wdPreviewStep );

				sample = ( ( sample - 1 + step + total ) % total ) + 1;
				refresh( true );
			}
		} )
	);

	window.addEventListener( 'resize', layout );

	window.jQuery?.( document ).on( 'heartbeat-tick', ( event, data ) => {
		if ( data?.rest_nonce ) {
			nonce = data.rest_nonce;
		}
	} );

	// Only fetch while the panel is on screen: not on another tab, in a closed box or scrolled away.
	if ( window.IntersectionObserver ) {
		observer = new window.IntersectionObserver(
			( entries ) => {
				visible = entries.some( ( entry ) => entry.isIntersecting );

				if ( visible ) {
					layout();

					if ( pending ) {
						refresh();
					}
				}
			},
			{ rootMargin: '200px 0px' }
		);
		observer.observe( panel );
	} else {
		visible = true;
	}

	panel.hidden = false;
	setWidth( width );

	if ( ! observer ) {
		refresh();
	}
} )();
