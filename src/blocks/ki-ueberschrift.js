import { registerBlockType, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import AiBlockEdit from './shared';

registerBlockType( 'wpcontentai/ki-ueberschrift', {
	apiVersion: 3,
	title: 'KI-Überschrift + Abschnitt',
	category: 'text',
	icon: 'heading',
	edit: ( props ) => (
		<AiBlockEdit
			clientId={ props.clientId }
			hasPrompt
			label="KI-Überschrift + Abschnitt"
			buttonLabel="Überschrift + Abschnitt erzeugen"
			generate={ async ( prompt, context ) => {
				const r = await apiFetch( {
					path: '/wpcontentai/v1/block',
					method: 'POST',
					data: { kind: 'ueberschrift', prompt, context },
				} );
				return [
					createBlock( 'core/heading', {
						level: 2,
						content: r.heading,
					} ),
					createBlock( 'core/paragraph', { content: r.text } ),
				];
			} }
		/>
	),
	save: () => null,
} );
