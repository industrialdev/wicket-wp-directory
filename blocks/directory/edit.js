/**
 * Editor UI for the Wicket Directory block: the settings sidebar and the
 * canvas (a placeholder until a directory is picked, then the server preview).
 */
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	Notice,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEntityRecords } from '@wordpress/core-data';
import { decodeEntities } from '@wordpress/html-entities';
import ServerSideRender from '@wordpress/server-side-render';

const POST_TYPE = 'wicket_directory';

// Same limits as BlockAttributes on the server.
const MIN_RESULTS = 1;
const MAX_RESULTS = 50;

/**
 * Sort options per directory type, printed by DirectoryBlock::editor_data()
 * from DirectoryType::sort_options(), so the tokens and labels match the
 * server. The first option is the type's default.
 *
 * @type {Object<string, Array<{value: string, label: string}>>}
 */
const SORT_OPTIONS = window.wicketDirectoryEditor?.sortOptions ?? {};

/**
 * Published directories, by title.
 *
 * `context: 'view'` because the default `edit` context needs manage_options
 * for this post type, and editors who place the block aren't admins. The
 * directory type comes from the `directory_type` REST field, since the config
 * meta is edit-context only.
 *
 * @return {{directories: Array<{id: number, title: string, type: string}>, isResolving: boolean, hasError: boolean}} Directories and the request state.
 */
function usePublishedDirectories() {
	const { records, isResolving, status } = useEntityRecords(
		'postType',
		POST_TYPE,
		{
			status: 'publish',
			per_page: -1,
			orderby: 'title',
			order: 'asc',
			context: 'view',
			_fields: [ 'id', 'title', 'directory_type' ],
		}
	);

	const directories = ( records ?? [] ).map( ( record ) => ( {
		id: record.id,
		title:
			decodeEntities( record.title?.rendered ?? '' ) ||
			sprintf(
				/* translators: %d: Directory post ID. */
				__( 'Directory #%d', 'wicket-directory' ),
				record.id
			),
		type: record.directory_type,
	} ) );

	const hasError = status === 'ERROR';

	return {
		directories,
		// `records` is null before the first request starts, too.
		isResolving: isResolving || ( records === null && ! hasError ),
		hasError,
	};
}

/**
 * The directory picker, shared by the sidebar and the placeholder.
 *
 * A saved directory that is no longer published stays selected, under an
 * "unavailable" option, so the block keeps pointing at it until the editor
 * picks another one.
 *
 * @param {Object}   props
 * @param {Array}    props.directories   Published directories.
 * @param {number}   props.directoryId   Selected directory ID (0 = none).
 * @param {boolean}  props.isUnavailable Whether the selected ID isn't published.
 * @param {Function} props.onChange      Gets the new directory ID.
 */
function DirectorySelect( {
	directories,
	directoryId,
	isUnavailable,
	onChange,
} ) {
	const options = [
		{ value: '0', label: __( 'Select a directory', 'wicket-directory' ) },
		...directories.map( ( directory ) => ( {
			value: String( directory.id ),
			label: directory.title,
		} ) ),
	];

	if ( isUnavailable ) {
		options.push( {
			value: String( directoryId ),
			label: sprintf(
				/* translators: %d: Directory post ID. */
				__( 'Unavailable directory (ID %d)', 'wicket-directory' ),
				directoryId
			),
			disabled: true,
		} );
	}

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Select a Directory', 'wicket-directory' ) }
			value={ String( directoryId ) }
			options={ options }
			onChange={ ( value ) => onChange( parseInt( value, 10 ) || 0 ) }
		/>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		directoryId,
		defaultSort,
		resultsPerPage,
		hideKeyword,
		hideLocation,
		hideFilters,
		hideOrderBy,
		collapseRefineOnMobile,
	} = attributes;

	const blockProps = useBlockProps();
	const { directories, isResolving, hasError } = usePublishedDirectories();

	const selected = directories.find(
		( directory ) => directory.id === directoryId
	);
	const isUnavailable =
		directoryId > 0 && ! isResolving && ! hasError && ! selected;

	// An unknown or other-type defaultSort shows (and renders as) the type's default.
	const sortOptions = SORT_OPTIONS[ selected?.type ] ?? [];
	const sortValue = sortOptions.some(
		( option ) => option.value === defaultSort
	)
		? defaultSort
		: sortOptions[ 0 ]?.value;

	// Sort tokens are per type, so a new directory starts on its type's default.
	const selectDirectory = ( id ) =>
		setAttributes( { directoryId: id, defaultSort: '' } );

	// RangeControl gives undefined when its number field is cleared.
	const setResultsPerPage = ( value ) => {
		const number = parseInt( value, 10 );

		if ( ! Number.isNaN( number ) ) {
			setAttributes( {
				resultsPerPage: Math.min(
					MAX_RESULTS,
					Math.max( MIN_RESULTS, number )
				),
			} );
		}
	};

	const picker = isResolving ? (
		<Spinner />
	) : (
		<DirectorySelect
			directories={ directories }
			directoryId={ directoryId }
			isUnavailable={ isUnavailable }
			onChange={ selectDirectory }
		/>
	);

	const notices = (
		<>
			{ hasError && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'The directories could not be loaded. Reload the editor to try again.',
						'wicket-directory'
					) }
				</Notice>
			) }
			{ isUnavailable && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The selected directory is no longer published, so visitors don’t see it. Publish it again or select another directory.',
						'wicket-directory'
					) }
				</Notice>
			) }
		</>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Directory', 'wicket-directory' ) }>
					{ notices }
					{ picker }
					{ sortOptions.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Default Sort Order',
								'wicket-directory'
							) }
							value={ sortValue }
							options={ sortOptions }
							onChange={ ( value ) =>
								setAttributes( { defaultSort: value } )
							}
						/>
					) }
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Number of Results to Display',
							'wicket-directory'
						) }
						min={ MIN_RESULTS }
						max={ MAX_RESULTS }
						step={ 1 }
						value={ resultsPerPage }
						onChange={ setResultsPerPage }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Hide Keyword Search',
							'wicket-directory'
						) }
						checked={ hideKeyword }
						onChange={ ( value ) =>
							setAttributes( { hideKeyword: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Hide Location Search',
							'wicket-directory'
						) }
						checked={ hideLocation }
						onChange={ ( value ) =>
							setAttributes( { hideLocation: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Hide Filters', 'wicket-directory' ) }
						checked={ hideFilters }
						onChange={ ( value ) =>
							setAttributes( { hideFilters: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Hide Order By', 'wicket-directory' ) }
						checked={ hideOrderBy }
						onChange={ ( value ) =>
							setAttributes( { hideOrderBy: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Collapse Refine Results by default on mobile/tablet',
							'wicket-directory'
						) }
						checked={ collapseRefineOnMobile }
						disabled={ hideFilters }
						onChange={ ( value ) =>
							setAttributes( { collapseRefineOnMobile: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ directoryId > 0 ? (
					// Disabled keeps the preview's links and form from leaving the editor.
					<Disabled>
						<ServerSideRender
							block="wicket/directory"
							attributes={ attributes }
							skipBlockSupportAttributes
						/>
					</Disabled>
				) : (
					<Placeholder
						icon="groups"
						label={ __( 'Wicket Directory', 'wicket-directory' ) }
						instructions={
							! isResolving && ! hasError && ! directories.length
								? __(
										'There are no published directories yet. An administrator can add one under Directories.',
										'wicket-directory'
								  )
								: __(
										'Select a directory to display. Only published directories are listed.',
										'wicket-directory'
								  )
						}
					>
						{ notices }
						{ ( isResolving || directories.length > 0 ) && picker }
					</Placeholder>
				) }
			</div>
		</>
	);
}
