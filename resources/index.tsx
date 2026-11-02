/**
 * Content translation status (Polylang → Content translation status): the
 * list screen, the table of remarks on the AI review page, whose other
 * parts are server-rendered, and the Translation glossary screen.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { App } from './app';
import { GlossaryApp } from './glossary/glossary-app';
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

	const glossary = document.getElementById( 'gds-ct-glossary-root' );

	if ( glossary ) {
		createRoot( glossary ).render( <GlossaryApp /> );
	}
} );
