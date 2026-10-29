/**
 * Content translation status (Polylang → Content translation status), the
 * list screen. The AI review page and the settings stay server-rendered.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { App } from './app';
import './style.scss';

domReady( () => {
	const root = document.getElementById( 'gds-content-translation-root' );

	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
