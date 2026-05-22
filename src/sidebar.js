import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
} from '@wordpress/edit-post';
import Wizard from './wizard';
import Tools from './tools';

const SIDEBAR_NAME = 'wpcontentai-sidebar';

export default function Sidebar() {
	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME }>
				WPContentAI
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={ SIDEBAR_NAME } title="WPContentAI">
				<Wizard />
				<Tools />
			</PluginSidebar>
		</>
	);
}
