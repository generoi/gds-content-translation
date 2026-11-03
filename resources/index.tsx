/**
 * Content translation status (Polylang → Content translation status): the
 * list screen; on the AI review page, whose other parts are server-rendered,
 * the table of remarks, the list of files still in the original's language
 * and the check of the page; the Translation glossary screen and Form
 * translations.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { App } from './app';
import { FormsApp } from './forms/forms-app';
import { GlossaryApp } from './glossary/glossary-app';
import { MediaPanel } from './media/media-panel';
import { ReviewApp } from './review/review-app';
import { ScanPanel } from './scan/scan-panel';
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

	const media = document.getElementById( 'gds-ct-media-root' );

	if ( media ) {
		createRoot( media ).render( <MediaPanel postId={ Number( media.dataset.postId ) } initialOpen={ Number( media.dataset.open ) } source={ media.dataset.source ?? '' } /> );
	}

	const scan = document.getElementById( 'gds-ct-scan-root' );

	if ( scan ) {
		createRoot( scan ).render( <ScanPanel postId={ Number( scan.dataset.postId ) } source={ scan.dataset.source ?? '' } /> );
	}

	const forms = document.getElementById( 'gds-ct-forms-root' );

	if ( forms ) {
		createRoot( forms ).render( <FormsApp /> );
	}

	const glossary = document.getElementById( 'gds-ct-glossary-root' );

	if ( glossary ) {
		createRoot( glossary ).render( <GlossaryApp /> );
	}
} );
