import { registerBlockType, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import AiBlockEdit from './shared';

registerBlockType( 'wpcontentai/ki-zusammenfassung', {
	apiVersion: 3,
	title: 'KI-Zusammenfassung',
	category: 'text',
	icon: 'excerpt-view',
	edit: ( props ) => (
		<AiBlockEdit
			clientId={ props.clientId }
			hasPrompt={ false }
			label="KI-Zusammenfassung"
			buttonLabel="Zusammenfassung erzeugen"
			generate={ async ( prompt, context ) => {
				const r = await apiFetch( {
					path: '/wpcontentai/v1/block',
					method: 'POST',
					data: { kind: 'zusammenfassung', prompt: '', context },
				} );
				return [
					createBlock( 'core/paragraph', { content: r.text } ),
				];
			} }
		/>
	),
	save: () => null,
} );
