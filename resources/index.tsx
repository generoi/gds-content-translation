/**
 * Content translation status (Polylang → Content translation status): the
 * list screen, and the table of remarks on the AI review page, whose other
 * parts are server-rendered.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { App } from './app';
import { ReviewApp } from './review/review-app';
import './style.scss';

domReady( () => {
	const root = document.getElementById( 'gds-content-translation-root' );

	if ( root ) {
		createRoot( root ).render( <App /> );
	}

	const remarks = document.getElementById( 'gds-ct-remarks-root' );

	if ( remarks ) {
		createRoot( remarks ).render( <ReviewApp postId={ Number( remarks.dataset.postId ) } /> );
	}
} );
