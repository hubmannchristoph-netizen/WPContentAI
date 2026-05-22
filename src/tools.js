import {
	PanelBody,
	Button,
	Notice,
	TextControl,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';

/**
 * Werkzeug-Panel: vorhandenen Inhalt optimieren und einzelne Bilder einfügen.
 */
export default function Tools() {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ imagePrompt, setImagePrompt ] = useState( '' );

	const content = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const { insertBlocks } = useDispatch( blockEditorStore );

	const optimize = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: '/wpcontentai/v1/optimize',
				method: 'POST',
				data: { input: content },
			} );
			editPost( { content: `${ content }\n\n${ result.text }` } );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
		}
		setBusy( false );
	};

	const generateImage = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: '/wpcontentai/v1/image',
				method: 'POST',
				data: { prompt: imagePrompt },
			} );
			insertBlocks(
				createBlock( 'core/image', {
					id: result.id,
					url: result.url,
				} )
			);
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
		}
		setBusy( false );
	};

	return (
		<PanelBody title="Werkzeuge" initialOpen={ false }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<Button variant="secondary" disabled={ busy } onClick={ optimize }>
				Inhalt optimieren
			</Button>
			<p />
			<TextControl
				__nextHasNoMarginBottom
				label="Bild-Prompt"
				value={ imagePrompt }
				onChange={ setImagePrompt }
			/>
			<Button
				variant="secondary"
				disabled={ busy || ! imagePrompt }
				onClick={ generateImage }
			>
				Bild einfügen
			</Button>
			{ busy && <Spinner /> }
		</PanelBody>
	);
}
