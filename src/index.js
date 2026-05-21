import { registerPlugin } from '@wordpress/plugins';
import Sidebar from './sidebar';

registerPlugin( 'wpcontentai', {
	render: Sidebar,
} );
