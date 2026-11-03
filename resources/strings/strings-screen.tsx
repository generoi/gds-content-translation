/**
 * Polylang → String translations: what Polylang translates besides posts,
 * one language at a time, the missing ones first. Texts (Polylang's string
 * translations), URL slugs (Polylang Pro's translated slugs) and, when
 * Polylang does not translate media, the images' alt texts. The language
 * and the part are in the address (`&strings_lang=en&section=slugs`, not
 * `lang`, which is Polylang's filter of the admin).
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs, getQueryArg } from '@wordpress/url';
import { errorMessage } from '../api';
import { getSettings, translationLanguages } from '../settings';
import { Notices } from '../ui/notices';
import { AltsSection } from './alts-section';
import { fetchAlts, fetchStrings } from './api';
import { SlugsSection } from './slugs-section';
import { TextsSection } from './texts-section';
import type { StringsResponse, StringsSection } from './types';

function Count( { count, label }: { count: number; label: string } ) {
	if ( ! count ) {
		return null;
	}

	return (
		<span className="gds-ct-glossary__count is-new">
			<span aria-hidden="true">{ count.toLocaleString() }</span>
			<span className="screen-reader-text">{ label }</span>
		</span>
	);
}

/* translators: %d: number of missing translations */
const missingLabel = ( count: number ): string => sprintf( _n( '%d missing', '%d missing', count, 'gds-content-translation' ), count );

export function StringsScreen() {
	const settings = getSettings();
	const languages = [ ...translationLanguages( settings ), ...settings.languages.filter( ( language ) => language.isDefault ) ];
	const altsOn = !! settings.strings?.alts;
	const asked = String( getQueryArg( window.location.href, 'strings_lang' ) ?? '' );
	const askedSection = String( getQueryArg( window.location.href, 'section' ) ?? '' ) as StringsSection;
	const [ lang, setLang ] = useState( languages.some( ( language ) => language.slug === asked ) ? asked : languages[ 0 ]?.slug ?? '' );
	const [ section, setSection ] = useState< StringsSection >( [ 'texts', 'slugs', 'alts' ].includes( askedSection ) ? askedSection : 'texts' );
	const [ data, setData ] = useState< StringsResponse | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const [ altsMissing, setAltsMissing ] = useState< Record< string, number > >( {} );
	const dirtyRef = useRef( 0 );
	const language = languages.find( ( candidate ) => candidate.slug === lang );
	const isDefault = !! language?.isDefault;
	const shown: StringsSection = section === 'alts' && ( ! altsOn || isDefault ) ? 'texts' : section;

	useEffect( () => {
		let current = true;
		setData( null );
		setFailed( '' );

		fetchStrings( lang )
			.then( ( loaded ) => current && setData( loaded ) )
			.catch( ( caught: unknown ) => current && setFailed( errorMessage( caught, __( 'Could not load the translations.', 'gds-content-translation' ) ) ) );

		return () => {
			current = false;
		};
	}, [ lang ] );

	// The alt texts' counts for the tabs, before their part is opened.
	useEffect( () => {
		const first = translationLanguages( settings )[ 0 ]?.slug;

		if ( altsOn && first ) {
			fetchAlts( first, { page: 1, perPage: 1, search: '', missing: true } )
				.then( ( loaded ) => setAltsMissing( loaded.missing ) )
				.catch( () => undefined );
		}
	}, [ altsOn, settings ] );

	useEffect( () => {
		try {
			window.history.replaceState( null, '', addQueryArgs( window.location.href, { strings_lang: lang, section: shown } ) );
		} catch {
			// The address just does not follow.
		}
	}, [ lang, shown ] );

	const onDirty = useCallback( ( count: number ) => {
		dirtyRef.current = count;
	}, [] );

	const go = ( next: { lang?: string; section?: StringsSection } ) => {
		if ( dirtyRef.current > 0 && ! window.confirm( __( 'Leave without saving your changes?', 'gds-content-translation' ) ) ) {
			return;
		}

		dirtyRef.current = 0;

		if ( next.lang ) {
			setLang( next.lang );
		}

		if ( next.section ) {
			setSection( next.section );
		}
	};

	if ( ! languages.length ) {
		return <p>{ __( 'Polylang has no languages yet.', 'gds-content-translation' ) }</p>;
	}

	const missing = ( slug: string ) => ( data?.missing[ slug ]?.texts ?? 0 ) + ( data?.missing[ slug ]?.slugs ?? 0 ) + ( altsMissing[ slug ] ?? 0 );
	const sections: Array< { id: StringsSection; label: string; count: number } > = [
		{ id: 'texts', label: __( 'Texts', 'gds-content-translation' ), count: data?.missing[ lang ]?.texts ?? 0 },
		...( data?.slugsAvailable ? [ { id: 'slugs' as const, label: __( 'URL slugs', 'gds-content-translation' ), count: data.missing[ lang ]?.slugs ?? 0 } ] : [] ),
		...( altsOn && ! isDefault ? [ { id: 'alts' as const, label: __( 'Image alt texts', 'gds-content-translation' ), count: altsMissing[ lang ] ?? 0 } ] : [] ),
	];

	return (
		<div className="gds-ct gds-ct-strings">
			<p className="gds-ct-forms__intro">
				{ __( 'What the site shows besides its pages: its title, the search engine titles, menus’ and buttons’ labels, the theme’s texts and the slugs of addresses. Missing ones show in the original language.', 'gds-content-translation' ) }{ ' ' }
				{ settings.strings?.polylangUrl && <a href={ settings.strings.polylangUrl }>{ __( 'All of them in Polylang’s Translations', 'gds-content-translation' ) }</a> }
			</p>
			<div className="gds-ct-strings__languages" role="group" aria-label={ __( 'Language', 'gds-content-translation' ) }>
				{ languages.map( ( candidate ) => (
					<Button key={ candidate.slug } variant={ candidate.slug === lang ? 'primary' : 'secondary' } isPressed={ candidate.slug === lang } onClick={ () => candidate.slug !== lang && go( { lang: candidate.slug } ) }>
						{ candidate.name }
						{ data && <Count count={ missing( candidate.slug ) } label={ missingLabel( missing( candidate.slug ) ) } /> }
					</Button>
				) ) }
			</div>
			<nav className="gds-ct__tabs gds-ct-glossary__tabs nav-tab-wrapper" aria-label={ __( 'What to translate', 'gds-content-translation' ) }>
				{ sections.map( ( tab ) => (
					<button key={ tab.id } type="button" className={ `nav-tab${ tab.id === shown ? ' nav-tab-active' : '' }` } aria-current={ tab.id === shown ? 'page' : undefined } onClick={ () => tab.id !== shown && go( { section: tab.id } ) }>
						<span>{ tab.label }</span>
						<Count count={ tab.count } label={ missingLabel( tab.count ) } />
					</button>
				) ) }
			</nav>
			{ failed && (
				<Notice status="error" isDismissible={ false }>
					{ failed }
				</Notice>
			) }
			{ ! failed && ! data && <Spinner /> }
			{ data && shown === 'texts' && (
				<>
					{ isDefault && (
						<p className="gds-ct-forms__intro">
							{ sprintf(
								/* translators: %s: language name, e.g. Finnish */
								__( 'The site’s other texts are written in %s. These are the theme’s and plugins’ texts, written in their own language.', 'gds-content-translation' ),
								language?.name ?? ''
							) }
						</p>
					) }
					<TextsSection key={ lang } data={ data } onData={ setData } onDirty={ onDirty } />
				</>
			) }
			{ data && shown === 'slugs' && <SlugsSection key={ lang } data={ data } onData={ setData } onDirty={ onDirty } /> }
			{ shown === 'alts' && language && <AltsSection key={ lang } lang={ lang } language={ language.name } missing={ altsMissing[ lang ] ?? 0 } onMissing={ setAltsMissing } onDirty={ onDirty } /> }
			<Notices />
		</div>
	);
}
