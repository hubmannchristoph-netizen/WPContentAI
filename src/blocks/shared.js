import { useBlockProps } from '@wordpress/block-editor';
import {
	Placeholder,
	TextControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Gemeinsames Editor-UI für die KI-Blöcke.
 *
 * Zeigt ein Eingabefeld (optional) und einen Button. Beim Generieren wird
 * `generate()` aufgerufen; bei Erfolg ersetzt sich der Block per
 * `replaceBlocks` durch die zurückgegebenen Standard-Blöcke.
 *
 * @param {Object}   props
 * @param {string}   props.clientId    Client-ID des Blocks.
 * @param {boolean}  props.hasPrompt   Ob ein Prompt-Eingabefeld gezeigt wird.
 * @param {string}   props.label       Beschriftung des Blocks.
 * @param {string}   props.buttonLabel Beschriftung des Buttons.
 * @param {Function} props.generate    async ( prompt, context ) => Block[].
 * @return {Element} Das Editor-Element.
 */
export default function AiBlockEdit( {
	clientId,
	hasPrompt,
	label,
	buttonLabel,
	generate,
} ) {
	const blockProps = useBlockProps();
	const [ prompt, setPrompt ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const context = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const { replaceBlocks } = useDispatch( blockEditorStore );

	const run = async () => {
		setBusy( true );
		setError( '' );
		try {
			const blocks = await generate( prompt, context );
			replaceBlocks( clientId, blocks );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
			setBusy( false );
		}
	};

	return (
		<div { ...blockProps }>
			<Placeholder label={ label }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ hasPrompt && (
					<TextControl
						__nextHasNoMarginBottom
						label="Prompt"
						value={ prompt }
						onChange={ setPrompt }
					/>
				) }
				<Button
					variant="primary"
					disabled={ busy || ( hasPrompt && ! prompt ) }
					onClick={ run }
				>
					{ buttonLabel }
				</Button>
				{ busy && <Spinner /> }
			</Placeholder>
		</div>
	);
}
