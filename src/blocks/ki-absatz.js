import { registerBlockType, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import AiBlockEdit from './shared';

registerBlockType( 'wpcontentai/ki-absatz', {
	apiVersion: 3,
	title: 'KI-Absatz',
	category: 'text',
	icon: 'editor-paragraph',
	edit: ( props ) => (
		<AiBlockEdit
			clientId={ props.clientId }
			hasPrompt
			label="KI-Absatz"
			buttonLabel="Absatz erzeugen"
			generate={ async ( prompt, context ) => {
				const r = await apiFetch( {
					path: '/wpcontentai/v1/block',
					method: 'POST',
					data: { kind: 'absatz', prompt, context },
				} );
				return [
					createBlock( 'core/paragraph', { content: r.text } ),
				];
			} }
		/>
	),
	save: () => null,
} );
