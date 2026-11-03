/**
 * The files, images and forms a translation still shows in the original's
 * language, with what each is and what to do about it. Each can be marked
 * fine as it is (a PDF that only exists in Finnish, a product code in a
 * photo) and counted again. The status screen shows it in a modal, the AI
 * review page above its remarks.
 */
import { Button, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import { notifyError } from '../ui/notify';
import { fetchMedia, markMedia } from './api';
import type { MediaData, MediaItem, MediaKind } from './types';

const order: MediaKind[] = [ 'document', 'form', 'svg', 'image', 'embed', 'link' ];

function kindLabel( kind: MediaKind ): string {
	return {
		document: __( 'File', 'gds-content-translation' ),
		svg: __( 'Image with text', 'gds-content-translation' ),
		image: __( 'Image with text', 'gds-content-translation' ),
		form: __( 'Form', 'gds-content-translation' ),
		embed: __( 'Embed', 'gds-content-translation' ),
		link: __( 'Link', 'gds-content-translation' ),
	}[ kind ];
}

/** What the item is and what to do, in one line. */
function hint( item: MediaItem, source: string ): string {
	switch ( item.kind ) {
		case 'document':
			/* translators: %s: language name of the original, e.g. Finnish */
			return sprintf( __( 'The same file as on the %s page. Link a translated file in the translation, or keep this one.', 'gds-content-translation' ), source );
		case 'svg':
			/* translators: %s: language name of the original, e.g. Finnish */
			return sprintf( __( 'The illustration’s labels are in %s. A translated copy of the file is needed.', 'gds-content-translation' ), source );
		case 'image':
			return __( 'The AI review found words in the image. Use a translated version of the image, or keep this one.', 'gds-content-translation' );
		case 'form':
			/* translators: %s: language name of the original, e.g. Finnish */
			return sprintf( __( 'The form’s fields, buttons and messages are shown as they are written in the form, in %s.', 'gds-content-translation' ), source );
		case 'embed':
			return __( 'The same video or embed as on the original. Check that it suits this language.', 'gds-content-translation' );
		case 'link':
			/* translators: %s: language name of the original, e.g. Finnish */
			return sprintf( __( 'Probably a page in %s on another site. Link its version in this language, if it has one.', 'gds-content-translation' ), source );
	}
}

function Texts( { texts }: { texts: string[] } ) {
	const shown = texts.slice( 0, 6 );

	return (
		<ul className="gds-ct-media__texts">
			{ shown.map( ( text ) => (
				<li key={ text }>{ text }</li>
			) ) }
			{ texts.length > shown.length && (
				<li className="gds-ct-media__more">
					{
						/* translators: %d: number of other texts */
						sprintf( _n( '+%d more', '+%d more', texts.length - shown.length, 'gds-content-translation' ), texts.length - shown.length )
					}
				</li>
			) }
		</ul>
	);
}

function Item( { item, source, busy, onMark }: { item: MediaItem; source: string; busy: boolean; onMark: ( ok: boolean ) => void } ) {
	const ok = item.state === 'ok';

	return (
		<li className={ `gds-ct-media__item gds-ct-media__item--${ item.kind }${ ok ? ' is-ok' : '' }` }>
			{ item.thumbnail && <img className="gds-ct-media__thumb" src={ item.thumbnail } alt="" loading="lazy" /> }
			<div className="gds-ct-media__body">
				<p className="gds-ct-media__title">
					<span className="gds-ct-media__kind">{ kindLabel( item.kind ) }</span>
					{ item.url ? (
						<a href={ item.url } target="_blank" rel="noopener noreferrer">
							{ item.title }
							<span className="screen-reader-text">{ __( '(opens in a new tab)', 'gds-content-translation' ) }</span>
						</a>
					) : (
						<span>{ item.title }</span>
					) }
				</p>
				{ ! ok && <p className="gds-ct-media__hint">{ hint( item, source ) }</p> }
				{ ! ok && !! item.texts?.length && <Texts texts={ item.texts } /> }
			</div>
			<Button className="gds-ct-media__action" variant="secondary" size="compact" onClick={ () => onMark( ! ok ) } disabled={ busy } accessibleWhenDisabled>
				{ ok ? __( 'Count again', 'gds-content-translation' ) : __( 'Fine as it is', 'gds-content-translation' ) }
				<span className="screen-reader-text">{ `: ${ item.title }` }</span>
			</Button>
		</li>
	);
}

interface Props {
	postId: number;
	/** After an item is marked: the counts elsewhere change. */
	onChange?: ( data: MediaData ) => void;
	/** Shown when there is nothing; the review page shows nothing instead. */
	empty?: boolean;
}

export function MediaList( { postId, onChange, empty = true }: Props ) {
	const [ data, setData ] = useState< MediaData | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );

	useEffect( () => {
		let current = true;

		fetchMedia( postId )
			.then( ( loaded ) => current && setData( loaded ) )
			.catch( ( caught: unknown ) => current && setFailed( errorMessage( caught, __( 'Could not load. Reload the page.', 'gds-content-translation' ) ) ) );

		return () => {
			current = false;
		};
	}, [ postId ] );

	if ( failed ) {
		return <p className="gds-ct-media__empty">{ failed }</p>;
	}

	if ( ! data ) {
		return (
			<p className="gds-ct-media__empty">
				<Spinner /> { __( 'Loading…', 'gds-content-translation' ) }
			</p>
		);
	}

	const mark = ( item: MediaItem, ok: boolean ) => {
		setBusy( item.key );
		markMedia( postId, [ item.key ], ok )
			.then( ( changed ) => {
				setData( changed );
				onChange?.( changed );
			} )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'Could not save. Try again.', 'gds-content-translation' ) ) ) )
			.finally( () => setBusy( '' ) );
	};

	const items = data.items.filter( ( item ) => item.state !== 'unchecked' );
	const sorted = [ ...items ].sort( ( a, b ) => Number( a.state === 'ok' ) - Number( b.state === 'ok' ) || order.indexOf( a.kind ) - order.indexOf( b.kind ) );

	if ( ! sorted.length && ! data.unchecked && ! empty ) {
		return null;
	}

	return (
		<div className="gds-ct-media">
			{ sorted.length ? (
				<ul className="gds-ct-media__list">
					{ sorted.map( ( item ) => (
						<Item key={ item.key } item={ item } source={ data.sourceLanguage } busy={ busy === item.key } onMark={ ( ok ) => mark( item, ok ) } />
					) ) }
				</ul>
			) : (
				<p className="gds-ct-media__empty">
					{
						/* translators: %s: language name of the original, e.g. Finnish */
						sprintf( __( 'No files, images or forms in %s found.', 'gds-content-translation' ), data.sourceLanguage )
					}
				</p>
			) }
			{ data.unchecked > 0 && data.canCheckImages && (
				<p className="gds-ct-media__note">
					{ sprintf(
						/* translators: %d: number of images */
						_n( '%d image is still being checked for text.', '%d images are still being checked for text.', data.unchecked, 'gds-content-translation' ),
						data.unchecked
					) }
				</p>
			) }
			{ ! data.canCheckImages && (
				<p className="gds-ct-media__note">{ __( 'Images are not checked for text: that needs the Claude API key of the AI review.', 'gds-content-translation' ) }</p>
			) }
		</div>
	);
}
