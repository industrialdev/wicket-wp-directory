/**
 * Editor registration for the Wicket Directory block.
 *
 * Title, attributes, supports and styles come from block.json, which the
 * server registers first. The block is dynamic: it saves nothing and both the
 * frontend and the editor preview render through DirectoryRenderer.
 */
import { registerBlockType } from '@wordpress/blocks';

import Edit from './edit';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
