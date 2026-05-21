import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { PanelBody, Button, Spinner, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';

const SIDEBAR_NAME = 'wpcontentai-sidebar';

export default function Sidebar() {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const content = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const title = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
		[]
	);
	const { editPost } = useDispatch( editorStore );

	const callApi = async ( route, input ) => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `/wpcontentai/v1/${ route }`,
				method: 'POST',
				data: { input },
			} );
			editPost( { content: `${ content }\n\n${ result.text }` } );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
		}
		setBusy( false );
	};

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME }>
				WPContentAI
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={ SIDEBAR_NAME } title="WPContentAI">
				<PanelBody>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => callApi( 'generate', title ) }
					>
						Text generieren
					</Button>
					<p />
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => callApi( 'optimize', content ) }
					>
						Inhalt optimieren
					</Button>
					{ busy && <Spinner /> }
				</PanelBody>
			</PluginSidebar>
		</>
	);
}
