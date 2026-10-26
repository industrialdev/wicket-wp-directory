/**
 * Directory edit screen: show the settings that fit the selected type.
 *
 * The type select lives in the Directory panels (`data-saved-type`); the
 * elements it toggles can be anywhere in the settings box:
 *
 * - `[data-wd-type="…"]` shows only for that type.
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

	const toggle = ( selector, hide ) => {
		document.querySelectorAll( selector ).forEach( ( el ) => {
			el.hidden = hide( el );
		} );
	};

	const update = () => {
		const type = select ? select.value : savedType;

		toggle( '[data-wd-type]', ( el ) => el.dataset.wdType !== type );
		toggle( '[data-wd-type-changed]', () => type === savedType );
		toggle( '[data-wd-type-unchanged]', () => type !== savedType );
	};

	select?.addEventListener( 'change', update );

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
 * item shows the hint for its ticked flags.
 */
( function () {
	const items = document.querySelectorAll( '[data-wd-item]' );

	const reveal = ( item, focus = false ) => {
		const on = item.querySelector( '[data-wd-reveal-toggle]' )?.checked;
		const options = item.querySelector( '[data-wd-reveal]' );

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
			item.querySelector( `[data-wd-flag="${ name }"]` )?.checked;
		const directory = flag( 'directory' );
		const primary = flag( 'primary' );
		let state = 'none';

		if ( directory && primary ) {
			state = 'both';
		} else if ( directory ) {
			state = 'directory';
		} else if ( primary ) {
			state = 'primary';
		}

		item.querySelectorAll( '[data-wd-hint]' ).forEach( ( el ) => {
			el.hidden = el.dataset.wdHint !== state;
		} );
	};

	items.forEach( ( item ) => {
		item.addEventListener( 'change', ( event ) => {
			if ( event.target.matches( '[data-wd-reveal-toggle]' ) ) {
				reveal( item, true );
			} else if ( event.target.matches( '[data-wd-flag]' ) ) {
				hint( item );
			}
		} );

		reveal( item );
		hint( item );
	} );
} )();

/**
 * Filters tab: add, move and remove facet rows.
 *
 * Rows post `{name}[i][source|schema_key|field|label]`; after every change the
 * rows are renumbered in DOM order, so the saved order is the order shown.
 * Picker choices already in the list are disabled: a facet can be added once.
 * The picker's search box filters its choices by label and code.
 */
( function () {
	const box = document.querySelector( '[data-wicket-directory-filters]' );

	if ( ! box ) {
		return;
	}

	const name = box.dataset.name;
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

		box.querySelectorAll( '[data-wd-facet-group]' ).forEach( ( group ) => {
			let groupShown = 0;

			group
				.querySelectorAll( '[data-wd-facet-choice]' )
				.forEach( ( choice ) => {
					const match =
						query === '' ||
						choice.textContent.toLowerCase().includes( query );

					choice.hidden = ! match;
					groupShown += match ? 1 : 0;
				} );

			group.hidden = groupShown === 0;
			shown += groupShown;
		} );

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
		row.querySelectorAll( '[data-wd-facet-input]' ).forEach( ( input ) => {
			input.value = values[ input.dataset.wdFacetInput ];
		} );

		const label = row.querySelector( '[data-wd-facet-input="label"]' );
		label.placeholder = choice.dataset.placeholder;
		label.id = `wicket-directory-facet-${ choice.value }`;
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
} )();
