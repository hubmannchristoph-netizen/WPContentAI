import { registerPlugin } from '@wordpress/plugins';
import Sidebar from './sidebar';
import './blocks';

registerPlugin( 'wpcontentai', {
	render: Sidebar,
} );
