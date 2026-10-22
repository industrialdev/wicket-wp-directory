/**
 * Minimal editor registration for the Wicket Directory block.
 *
 * Plain browser JS with no build step: it puts the block in the inserter and
 * previews it through the server renderer. Title, attributes and supports come
 * from block.json, which the server registers first. The full editor (settings
 * sidebar, placeholder, draft notice) replaces this file with the
 * @wordpress/scripts build.
 */
( function ( blocks, element, blockEditor, ServerSideRender ) {
	blocks.registerBlockType( 'wicket/directory', {
		edit: function ( props ) {
			return element.createElement(
				'div',
				blockEditor.useBlockProps(),
				element.createElement( ServerSideRender, {
					block: 'wicket/directory',
					attributes: props.attributes,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.serverSideRender );
