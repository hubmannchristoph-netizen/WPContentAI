import {
	PanelBody,
	Button,
	Notice,
	TextControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';

const LENGTHS = [
	{ label: 'Kurz', value: 'kurz' },
	{ label: 'Mittel', value: 'mittel' },
	{ label: 'Lang', value: 'lang' },
];
const TONES = [
	{ label: 'Sachlich', value: 'sachlich' },
	{ label: 'Locker', value: 'locker' },
	{ label: 'Werblich', value: 'werblich' },
	{ label: 'Fachlich', value: 'fachlich' },
];
const IMAGE_COUNTS = [
	{ label: '0', value: '0' },
	{ label: '1', value: '1' },
	{ label: '2', value: '2' },
	{ label: '3', value: '3' },
];
const HEADINGS = [
	{ label: 'Mit Überschriften', value: 'mit' },
	{ label: 'Ohne Überschriften', value: 'ohne' },
];

/**
 * Vierstufiger Wizard: Thema → Optionen → Gliederung → Erzeugen.
 */
export default function Wizard() {
	const [ step, setStep ] = useState( 1 );
	const [ topic, setTopic ] = useState( '' );
	const [ length, setLength ] = useState( 'mittel' );
	const [ tone, setTone ] = useState( 'sachlich' );
	const [ imageCount, setImageCount ] = useState( '1' );
	const [ headings, setHeadings ] = useState( 'mit' );
	const [ outline, setOutline ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ progress, setProgress ] = useState( '' );

	const postContent = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const postTitle = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const { resetBlocks } = useDispatch( blockEditorStore );

	const loadOutline = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: '/wpcontentai/v1/outline',
				method: 'POST',
				data: {
					topic,
					length,
					tone,
					headings,
					image_count: parseInt( imageCount, 10 ),
				},
			} );
			setOutline( result );
			setStep( 3 );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
		}
		setBusy( false );
	};

	const composePost = async () => {
		if (
			( postTitle || postContent ) &&
			// eslint-disable-next-line no-alert
			! window.confirm(
				'Vorhandener Titel und Inhalt werden ersetzt. Fortfahren?'
			)
		) {
			return;
		}
		setBusy( true );
		setError( '' );
		setStep( 4 );
		setProgress( 'Text wird erzeugt …' );
		try {
			const composed = await apiFetch( {
				path: '/wpcontentai/v1/compose',
				method: 'POST',
				data: {
					topic,
					length,
					tone,
					image_count: parseInt( imageCount, 10 ),
					outline,
				},
			} );

			const blocks = [];
			const imageTotal = composed.blocks.filter(
				( b ) => b.type === 'image'
			).length;
			let imageDone = 0;

			for ( const item of composed.blocks ) {
				if ( item.type === 'heading' ) {
					blocks.push(
						createBlock( 'core/heading', {
							level: 2,
							content: item.text || '',
						} )
					);
				} else if ( item.type === 'paragraph' ) {
					blocks.push(
						createBlock( 'core/paragraph', {
							content: item.text || '',
						} )
					);
				} else if ( item.type === 'image' ) {
					imageDone += 1;
					setProgress( `Bild ${ imageDone } von ${ imageTotal } …` );
					try {
						const img = await apiFetch( {
							path: '/wpcontentai/v1/image',
							method: 'POST',
							data: { prompt: item.prompt || '' },
						} );
						blocks.push(
							createBlock( 'core/image', {
								id: img.id,
								url: img.url,
							} )
						);
					} catch ( e ) {
						blocks.push(
							createBlock( 'core/paragraph', {
								content: '[Bild konnte nicht erzeugt werden]',
							} )
						);
					}
				}
			}

			editPost( { title: composed.title } );
			resetBlocks( blocks );
			setProgress( 'Beitrag fertig erzeugt.' );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
			setStep( 3 );
		}
		setBusy( false );
	};

	const restart = () => {
		setStep( 1 );
		setOutline( null );
		setProgress( '' );
		setError( '' );
	};

	return (
		<PanelBody title="Beitrags-Wizard">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ step === 1 && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						label="Thema des Beitrags"
						value={ topic }
						onChange={ setTopic }
					/>
					<Button
						variant="primary"
						disabled={ ! topic }
						onClick={ () => setStep( 2 ) }
					>
						Weiter
					</Button>
				</>
			) }

			{ step === 2 && (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						label="Länge"
						value={ length }
						options={ LENGTHS }
						onChange={ setLength }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label="Tonfall"
						value={ tone }
						options={ TONES }
						onChange={ setTone }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label="Anzahl Bilder"
						value={ imageCount }
						options={ IMAGE_COUNTS }
						onChange={ setImageCount }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label="Überschriften"
						value={ headings }
						options={ HEADINGS }
						onChange={ setHeadings }
					/>
					<Button variant="secondary" onClick={ () => setStep( 1 ) }>
						Zurück
					</Button>{ ' ' }
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ loadOutline }
					>
						Gliederung erstellen
					</Button>
					{ busy && <Spinner /> }
				</>
			) }

			{ step === 3 && outline && (
				<>
					<p>
						<strong>{ outline.title }</strong>
					</p>
					{ outline.sections.length > 0 && (
						<ul>
							{ outline.sections.map( ( s, i ) => (
								<li key={ i }>{ s }</li>
							) ) }
						</ul>
					) }
					<p>Geplante Bilder: { imageCount }</p>
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ loadOutline }
					>
						Neu generieren
					</Button>{ ' ' }
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ composePost }
					>
						Passt, Beitrag erzeugen
					</Button>
					{ busy && <Spinner /> }
				</>
			) }

			{ step === 4 && (
				<>
					<p>{ progress }</p>
					{ busy && <Spinner /> }
					{ ! busy && (
						<Button variant="secondary" onClick={ restart }>
							Neuen Beitrag starten
						</Button>
					) }
				</>
			) }
		</PanelBody>
	);
}
