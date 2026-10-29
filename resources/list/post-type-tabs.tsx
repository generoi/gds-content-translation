/**
 * One tab per post type shown on the screen (Content translation settings
 * chooses them), in the style of core's nav tabs. Links, so a tab opens in a
 * new browser tab too; a plain click switches without a reload.
 */
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import type { PostTypeTab, Settings } from '../settings';

function TabIcon( { icon }: { icon: PostTypeTab[ 'icon' ] } ) {
	if ( icon.type === 'image' ) {
		return <img src={ icon.value } alt="" className="gds-ct__tab-icon" aria-hidden="true" />;
	}

	return <span className={ `dashicons ${ icon.value }` } aria-hidden="true" />;
}

export function PostTypeTabs( { settings, current, onChange }: { settings: Settings; current: string; onChange: ( postType: string ) => void } ) {
	return (
		<nav className="gds-ct__tabs nav-tab-wrapper" aria-label={ __( 'Post type', 'gds-content-translation' ) }>
			{ settings.postTypes.map( ( postType ) => {
				const active = postType.slug === current;

				return (
					<a
						key={ postType.slug }
						href={ addQueryArgs( settings.pageUrl, { gds_ct_post_type: postType.slug } ) }
						className={ `nav-tab${ active ? ' nav-tab-active' : '' }` }
						aria-current={ active ? 'page' : undefined }
						onClick={ ( event ) => {
							if ( event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0 ) {
								return;
							}

							event.preventDefault();
							onChange( postType.slug );
						} }
					>
						<TabIcon icon={ postType.icon } />
						<span>{ postType.label }</span>
					</a>
				);
			} ) }
		</nav>
	);
}
