/**
 * Image alt texts in one language, when Polylang does not translate media:
 * the media library's alt text (the default language's) and the language's
 * own, which pages in that language show. A page of images at a time from
 * the server; DeepL fills the missing ones, with the glossary.
 */
import { Button, Flex, TextControl } from '@wordpress/components';
import { createContext, useContext, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import { DataViews } from '../dataviews';
import type { Action, Field, View } from '../dataviews';
import { defaultLanguage } from '../settings';
import { ErrorBoundary } from '../ui/error-boundary';
import { notifyError, notifySuccess } from '../ui/notify';
import { fetchAlts, saveAlts, translateAlts } from './api';
import type { AltItem, AltsChanged, AltsResponse } from './types';
import { useDrafts } from './use-drafts';
import type { Drafts } from './use-drafts';

const CellContext = createContext< { drafts: Drafts; language: string } | null >( null );

function ImageCell( { item }: { item: AltItem } ) {
	return (
		<span className="gds-ct-strings__image">
			{ item.thumbnail && <img src={ item.thumbnail } alt="" loading="lazy" width={ 48 } height={ 48 } /> }
			<span>
				<a href={ item.editUrl } target="_blank" rel="noopener noreferrer">
					{ item.title || item.file }
					<span className="screen-reader-text">{ __( '(opens in a new tab)', 'gds-content-translation' ) }</span>
				</a>
				<span className="gds-ct-strings__context">{ item.source }</span>
			</span>
		</span>
	);
}

function AltCell( { item }: { item: AltItem } ) {
	const context = useContext( CellContext );

	if ( ! context ) {
		return null;
	}

	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			/* translators: 1: image title, 2: language name */
			label={ sprintf( __( 'Alt text of %1$s in %2$s', 'gds-content-translation' ), item.title || item.file, context.language ) }
			hideLabelFromVision
			value={ context.drafts.value( String( item.id ), item.translation ) }
			placeholder={ __( 'Not translated: the media library’s is shown', 'gds-content-translation' ) }
			onChange={ ( next ) => context.drafts.set( String( item.id ), next, item.translation ) }
		/>
	);
}

function buildFields( language: string ): Field< AltItem >[] {
	return [
		{
			id: 'image',
			/* translators: %s: language name of the original, e.g. Finnish */
			label: sprintf( __( 'Image and its alt text in %s', 'gds-content-translation' ), defaultLanguage()?.name ?? '' ),
			getValue: ( { item } ) => item.source,
			render: ImageCell,
			enableHiding: false,
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'translation',
			/* translators: %s: language name, e.g. English */
			label: sprintf( __( 'Alt text in %s', 'gds-content-translation' ), language ),
			getValue: ( { item } ) => item.translation,
			render: AltCell,
			enableHiding: false,
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'missing',
			label: __( 'Status', 'gds-content-translation' ),
			getValue: ( { item } ) => ( item.translation ? '' : 'missing' ),
			render: ( { item } ) => <span className={ `gds-ct-strings__status is-${ item.translation ? 'translated' : 'missing' }` }>{ item.translation ? __( 'Translated', 'gds-content-translation' ) : __( 'Missing', 'gds-content-translation' ) }</span>,
			elements: [ { value: 'missing', label: __( 'Missing', 'gds-content-translation' ) } ],
			filterBy: { operators: [ 'is' ] },
			enableSorting: false,
		},
	];
}

const defaultView: View = {
	type: 'table',
	page: 1,
	perPage: 50,
	search: '',
	filters: [],
	titleField: 'image',
	showTitle: true,
	fields: [ 'translation', 'missing' ],
	layout: { density: 'compact', styles: { image: { width: '45%' }, translation: { width: '45%' }, missing: { width: 120 } } },
};

interface Props {
	lang: string;
	language: string;
	missing: number;
	onMissing: ( missing: Record< string, number > ) => void;
	onDirty: ( count: number ) => void;
}

export function AltsSection( { lang, language, missing, onMissing, onDirty }: Props ) {
	const drafts = useDrafts();
	const [ view, setView ] = useState< View >( () => ( { ...defaultView, filters: missing ? [ { field: 'missing', operator: 'is', value: 'missing' } ] : [] } ) );
	const [ data, setData ] = useState< AltsResponse | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const [ reload, setReload ] = useState( 0 );
	const [ busy, setBusy ] = useState( '' );
	const [ selection, setSelection ] = useState< string[] >( [] );
	const dirty = drafts.dirty;
	const onlyMissing = ( view.filters ?? [] ).some( ( filter ) => filter.field === 'missing' && filter.value === 'missing' );

	useEffect( () => onDirty( dirty.length ), [ dirty.length, onDirty ] );

	useEffect( () => {
		const controller = new AbortController();

		fetchAlts( lang, { page: view.page ?? 1, perPage: view.perPage ?? 50, search: view.search ?? '', missing: onlyMissing }, controller.signal )
			.then( ( next ) => {
				setData( next );
				setFailed( '' );
				onMissing( next.missing );
			} )
			.catch( ( caught: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setFailed( errorMessage( caught, __( 'The images could not be loaded.', 'gds-content-translation' ) ) );
				}
			} );

		return () => controller.abort();
	}, [ lang, view.page, view.perPage, view.search, onlyMissing, reload, onMissing ] );

	const fields = useMemo( () => buildFields( language ), [ language ] );
	const cell = useMemo( () => ( { drafts, language } ), [ drafts, language ] );

	const after = ( saved: string[], message: ( result: AltsChanged ) => string ) => ( result: AltsChanged ) => {
		drafts.clear( saved );
		setSelection( [] );
		onMissing( result.missing );
		notifySuccess( message( result ) );
		setReload( ( count ) => count + 1 );
	};

	const fail = ( caught: unknown ) => notifyError( errorMessage( caught, __( 'That did not work. Try again.', 'gds-content-translation' ) ) );

	const translated = ( result: AltsChanged ) =>
		sprintf(
			/* translators: %d: number of alt texts */
			_n( 'DeepL translated %d alt text.', 'DeepL translated %d alt texts.', result.changed, 'gds-content-translation' ),
			result.changed
		);

	const translate = ( ids: number[] = [] ) => {
		setBusy( 'translate' );
		translateAlts( lang, ids )
			.then( after( [], translated ) )
			.catch( fail )
			.finally( () => setBusy( '' ) );
	};

	const save = () => {
		setBusy( 'save' );
		saveAlts( lang, drafts.changes() )
			.then( after( dirty, () => __( 'Alt texts saved.', 'gds-content-translation' ) ) )
			.catch( fail )
			.finally( () => setBusy( '' ) );
	};

	const actions = useMemo< Action< AltItem >[] >(
		() =>
			data?.canTranslate
				? [
						{
							id: 'translate',
							label: __( 'Translate with DeepL', 'gds-content-translation' ),
							supportsBulk: true,
							isPrimary: true,
							isEligible: ( item ) => ! item.translation && !! item.source,
							callback: ( items ) => translate( items.map( ( item ) => item.id ) ),
						},
				  ]
				: [],
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ data?.canTranslate, lang ]
	);

	return (
		<CellContext.Provider value={ cell }>
			<p className="gds-ct-forms__intro">
				{ sprintf(
					/* translators: 1: language name, e.g. English, 2: language name of the original, e.g. Finnish */
					__( 'Polylang does not translate the media here, so an image has one alt text, in %2$s. The alt text in %1$s is shown on %1$s pages instead, where the image’s alt text is the media library’s. An alt text written into the page itself stays.', 'gds-content-translation' ),
					language,
					defaultLanguage()?.name ?? ''
				) }
			</p>
			<Flex className="gds-ct-forms__toolbar" justify="flex-start" gap={ 2 } wrap>
				{ data?.canTranslate && (
					<Button variant={ missing ? 'primary' : 'secondary' } isBusy={ busy === 'translate' } disabled={ !! busy || ! missing || dirty.length > 0 } accessibleWhenDisabled onClick={ () => translate() }>
						{ missing
							? sprintf(
									/* translators: %d: number of images without an alt text in the language */
									_n( 'Translate %d missing alt text with DeepL', 'Translate %d missing alt texts with DeepL', missing, 'gds-content-translation' ),
									missing
							  )
							: __( 'Every alt text is translated', 'gds-content-translation' ) }
					</Button>
				) }
				<Button variant={ dirty.length ? 'primary' : 'secondary' } isBusy={ busy === 'save' } disabled={ !! busy || ! dirty.length } accessibleWhenDisabled onClick={ save }>
					{ dirty.length
						? sprintf(
								/* translators: %d: number of changed alt texts */
								_n( 'Save %d change', 'Save %d changes', dirty.length, 'gds-content-translation' ),
								dirty.length
						  )
						: __( 'Saved', 'gds-content-translation' ) }
				</Button>
			</Flex>
			<ErrorBoundary context="table">
				<div className={ `gds-ct__table gds-ct-strings__table${ data ? '' : ' is-loading' }` }>
					<DataViews< AltItem >
						data={ data?.items ?? [] }
						fields={ fields }
						view={ view }
						onChangeView={ ( next ) => {
							if ( next.page !== view.page || next.search !== view.search || JSON.stringify( next.filters ) !== JSON.stringify( view.filters ) ) {
								setSelection( [] );
							}

							setView( next );
						} }
						actions={ actions }
						isLoading={ ! data && ! failed }
						paginationInfo={ { totalItems: data?.total ?? 0, totalPages: data?.totalPages ?? 1 } }
						defaultLayouts={ { table: { layout: defaultView.layout } } }
						selection={ selection }
						onChangeSelection={ setSelection }
						getItemId={ ( item ) => String( item.id ) }
						searchLabel={ __( 'Search alt texts', 'gds-content-translation' ) }
						empty={
							<div className="gds-ct__empty" role={ failed ? 'alert' : undefined }>
								<p>{ failed || ( onlyMissing || view.search ? __( 'No images match your search and filters.', 'gds-content-translation' ) : __( 'No image in the media library has an alt text.', 'gds-content-translation' ) ) }</p>
							</div>
						}
					/>
				</div>
			</ErrorBoundary>
		</CellContext.Provider>
	);
}
