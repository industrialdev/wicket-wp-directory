/**
 * Directory edit screen: show the settings that fit the selected type.
 *
 * The type radios live in the Directory box (`data-saved-type`); the elements
 * they toggle can be in any of the directory's meta boxes:
 *
 * - `[data-wd-type="…"]` shows only for that type.
 * - `[data-wd-type-changed]` shows only while the selected type differs from
 *   the saved one; `[data-wd-type-unchanged]` only while it matches.
 *
 * Plain script, no build step. Without it the boxes show the saved type's settings.
 */
( function () {
	const box = document.querySelector( '[data-wicket-directory-settings]' );

	if ( ! box ) {
		return;
	}

	const savedType = box.dataset.savedType;
	const radios = 'input[type="radio"][name$="[type]"]';

	const toggle = ( selector, hide ) => {
		document.querySelectorAll( selector ).forEach( ( el ) => {
			el.hidden = hide( el );
		} );
	};

	const update = () => {
		const selected = box.querySelector( radios + ':checked' );
		const type = selected ? selected.value : savedType;

		toggle( '[data-wd-type]', ( el ) => el.dataset.wdType !== type );
		toggle( '[data-wd-type-changed]', () => type === savedType );
		toggle( '[data-wd-type-unchanged]', () => type !== savedType );
	};

	box.addEventListener( 'change', ( event ) => {
		if ( event.target.matches( radios ) ) {
			update();
		}
	} );

	update();
} )();

/**
 * Filters box: add, move and remove facet rows.
 *
 * Rows post `{name}[i][source|schema_key|field|label]`; after every change the
 * rows are renumbered in DOM order, so the saved order is the order shown.
 * Picker choices already in the list are disabled: a facet can be added once.
 */
( function () {
	const box = document.querySelector( '[data-wicket-directory-filters]' );

	if ( ! box ) {
		return;
	}

	const name = box.dataset.name;
	const rows = box.querySelector( '[data-wd-facet-rows]' );
	const empty = box.querySelector( '[data-wd-facet-empty]' );
	const choice = box.querySelector( '[data-wd-facet-choice]' );
	const template = box.querySelector( '[data-wd-facet-template]' );

	const sync = () => {
		const keys = [];

		rows.querySelectorAll( '[data-wd-facet-row]' ).forEach(
			( row, index ) => {
				keys.push( row.dataset.facetKey );
				row.querySelectorAll( '[data-wd-facet-input]' ).forEach(
					( input ) => {
						input.name = `${ name }[${ index }][${ input.dataset.wdFacetInput }]`;
					}
				);
			}
		);

		choice.querySelectorAll( 'option[value]' ).forEach( ( option ) => {
			option.disabled =
				option.value !== '' && keys.includes( option.value );
		} );

		if ( choice.selectedOptions[ 0 ]?.disabled ) {
			choice.value = '';
		}

		empty.hidden = keys.length > 0;
	};

	const add = () => {
		const option = choice.selectedOptions[ 0 ];

		if ( ! option || option.value === '' || option.disabled ) {
			return;
		}

		const row = template.content
			.querySelector( '[data-wd-facet-row]' )
			.cloneNode( true );
		const values = {
			source: option.dataset.source,
			schema_key: option.dataset.schemaKey,
			field: option.dataset.field,
			label: option.dataset.default,
		};

		row.dataset.facetKey = option.value;
		row.querySelector( '[data-wd-facet-name]' ).textContent =
			option.dataset.name;
		row.querySelectorAll( '[data-wd-facet-input]' ).forEach( ( input ) => {
			input.value = values[ input.dataset.wdFacetInput ];
		} );

		const label = row.querySelector( '[data-wd-facet-input="label"]' );
		label.placeholder = option.dataset.placeholder;
		label.id = `wicket-directory-facet-${ option.value }`;
		row.querySelector( 'label' ).htmlFor = label.id;

		rows.append( row );
		sync();
		label.focus();
	};

	box.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( 'button' );
		const row = button?.closest( '[data-wd-facet-row]' );

		if ( ! button ) {
			return;
		}

		if ( button.matches( '[data-wd-facet-add]' ) ) {
			add();
		} else if ( row && button.matches( '[data-wd-facet-remove]' ) ) {
			row.remove();
			sync();
			choice.focus();
		} else if ( row && button.matches( '[data-wd-facet-move]' ) ) {
			const sibling =
				button.dataset.wdFacetMove === '-1'
					? row.previousElementSibling
					: row.nextElementSibling;

			if ( sibling ) {
				sibling.insertAdjacentElement(
					button.dataset.wdFacetMove === '-1'
						? 'beforebegin'
						: 'afterend',
					row
				);
				sync();
				button.focus();
			}
		}
	} );

	sync();
} )();
