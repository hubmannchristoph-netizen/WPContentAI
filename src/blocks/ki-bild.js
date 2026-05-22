import { registerBlockType, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import AiBlockEdit from './shared';

registerBlockType( 'wpcontentai/ki-bild', {
	apiVersion: 3,
	title: 'KI-Bild',
	category: 'media',
	icon: 'format-image',
	edit: ( props ) => (
		<AiBlockEdit
			clientId={ props.clientId }
			hasPrompt
			label="KI-Bild"
			buttonLabel="Bild erzeugen"
			generate={ async ( prompt ) => {
				const r = await apiFetch( {
					path: '/wpcontentai/v1/image',
					method: 'POST',
					data: { prompt },
				} );
				return [
					createBlock( 'core/image', { id: r.id, url: r.url } ),
				];
			} }
		/>
	),
	save: () => null,
} );
