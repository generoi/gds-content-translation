/**
 * The texts Polylang translates (the site title, Yoast's titles, widgets,
 * ACF's labels, the theme's texts) in one language: a DataViews table of the
 * original and its translation to edit, missing ones first, with DeepL for
 * the missing ones and the AI review to check them.
 */
import { Button, Flex, TextareaControl, VisuallyHidden } from '@wordpress/components';
import { createContext, useContext, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import { DataViews, filterSortAndPaginate } from '../dataviews';
import type { Action, Field, View } from '../dataviews';
import { defaultLanguage } from '../settings';
import { ErrorBoundary } from '../ui/error-boundary';
import { notifyError, notifySuccess } from '../ui/notify';
import { reviewStrings, saveStrings, settleRemark, translateStrings } from './api';
import type { StringRow, StringsResponse } from './types';
import { useDrafts } from './use-drafts';
import type { Drafts } from './use-drafts';

type Status = 'missing' | 'remark' | 'translated' | 'kept';

function status( row: StringRow ): Status {
	if ( row.fixed && row.translation === '' ) {
		return 'kept';
	}

	if ( row.translation === '' ) {
		return 'missing';
	}

	return row.remark ? 'remark' : 'translated';
}

function statusLabels(): Record< Status, string > {
	return {
		missing: __( 'Missing', 'gds-content-translation' ),
		remark: __( 'AI remark', 'gds-content-translation' ),
		translated: __( 'Translated', 'gds-content-translation' ),
		kept: __( 'Same in every language', 'gds-content-translation' ),
	};
}

const ORDER: Status[] = [ 'missing', 'remark', 'translated', 'kept' ];

interface CellContextValue {
	drafts: Drafts;
	busy: string;
	language: string;
	onRemark: ( row: StringRow, use: boolean ) => void;
}

/** What the cells need: DataViews renders them, and their fields stay the same so a typed text keeps its focus. */
const CellContext = createContext< CellContextValue | null >( null );

function useCell(): CellContextValue {
	const value = useContext( CellContext );

	if ( ! value ) {
		throw new Error( 'A string cell outside its section.' );
	}

	return value;
}

function SourceCell( { item }: { item: StringRow } ) {
	return (
		<div className="gds-ct-strings__source">
			<span className="gds-ct-strings__context">{ [ item.group, item.name !== item.source ? item.name : '' ].filter( Boolean ).join( ' · ' ) }</span>
			<span className="gds-ct-strings__text">{ item.source }</span>
			{ item.original && (
				<span className="gds-ct-strings__original">
					{
						/* translators: 1: language name, e.g. Finnish, 2: the text in that language */
						sprintf( __( 'In %1$s: %2$s', 'gds-content-translation' ), defaultLanguage()?.name ?? '', item.original )
					}
				</span>
			) }
		</div>
	);
}

function TranslationCell( { item }: { item: StringRow } ) {
	const { drafts, busy, language, onRemark } = useCell();

	return (
		<div className="gds-ct-strings__translation">
			<TextareaControl
				__nextHasNoMarginBottom
				/* translators: 1: the original text, 2: language name */
				label={ sprintf( __( '“%1$s” in %2$s', 'gds-content-translation' ), item.source, language ) }
				hideLabelFromVision
				value={ drafts.value( item.id, item.translation ) }
				rows={ item.multiline || item.html || item.source.length > 80 ? 3 : 1 }
				placeholder={ item.fixed ? __( 'The same in every language', 'gds-content-translation' ) : __( 'Not translated: the original is shown', 'gds-content-translation' ) }
				onChange={ ( next ) => drafts.set( item.id, next, item.translation ) }
			/>
			{ item.remark && (
				<div className="gds-ct-forms__remark">
					<p>{ item.remark.issue }</p>
					<p className="gds-ct-forms__suggestion">{ item.remark.suggestion }</p>
					<Button variant="primary" size="compact" disabled={ !! busy } accessibleWhenDisabled onClick={ () => onRemark( item, true ) }>
						{ __( 'Use suggestion', 'gds-content-translation' ) }
						<VisuallyHidden>{ `: “${ item.source }”` }</VisuallyHidden>
					</Button>{ ' ' }
					<Button variant="tertiary" size="compact" disabled={ !! busy } accessibleWhenDisabled onClick={ () => onRemark( item, false ) }>
						{ __( 'Not an issue', 'gds-content-translation' ) }
						<VisuallyHidden>{ `: “${ item.source }”` }</VisuallyHidden>
					</Button>
				</div>
			) }
		</div>
	);
}

function StatusCell( { item }: { item: StringRow } ) {
	const value = status( item );

	return <span className={ `gds-ct-strings__status is-${ value }` }>{ statusLabels()[ value ] }</span>;
}

function buildFields( groups: string[] ): Field< StringRow >[] {
	const labels = statusLabels();

	return [
		{
			id: 'source',
			/* translators: %s: language name of the original, e.g. Finnish */
			label: sprintf( __( 'Original (%s)', 'gds-content-translation' ), defaultLanguage()?.name ?? '' ),
			getValue: ( { item } ) => `${ item.source } ${ item.name }`,
			render: SourceCell,
			enableHiding: false,
			enableGlobalSearch: true,
			filterBy: false,
		},
		{
			id: 'translation',
			label: __( 'Translation', 'gds-content-translation' ),
			getValue: ( { item } ) => item.translation,
			render: TranslationCell,
			enableHiding: false,
			enableSorting: false,
			enableGlobalSearch: true,
			filterBy: false,
		},
		{
			id: 'status',
			label: __( 'Status', 'gds-content-translation' ),
			getValue: ( { item } ) => status( item ),
			render: StatusCell,
			elements: ORDER.map( ( value ) => ( { value, label: labels[ value ] } ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
		},
		{
			id: 'group',
			label: __( 'Group', 'gds-content-translation' ),
			getValue: ( { item } ) => item.group,
			elements: groups.map( ( group ) => ( { value: group, label: group } ) ),
			filterBy: { operators: [ 'isAny' ] },
		},
	];
}

const defaultView: View = {
	type: 'table',
	page: 1,
	perPage: 50,
	search: '',
	filters: [],
	titleField: 'source',
	showTitle: true,
	fields: [ 'translation', 'status' ],
	layout: { density: 'compact', styles: { source: { width: '40%' }, translation: { width: '45%' }, status: { width: 140 } } },
};

interface Props {
	data: StringsResponse;
	onData: ( data: StringsResponse ) => void;
	onDirty: ( count: number ) => void;
}

export function TextsSection( { data, onData, onDirty }: Props ) {
	const lang = data.language.slug;
	const drafts = useDrafts();
	const [ view, setView ] = useState< View >( () => ( { ...defaultView, filters: data.missing[ lang ]?.texts ? [ { field: 'status', operator: 'isAny', value: [ 'missing' ] } ] : [] } ) );
	const [ busy, setBusy ] = useState( '' );
	const busyRef = useRef( '' );
	const [ selection, setSelection ] = useState< string[] >( [] );
	const dirty = drafts.dirty;

	useEffect( () => onDirty( dirty.length ), [ dirty.length, onDirty ] );

	// Missing first, then the remarks to go through; Polylang's order within.
	const rows = useMemo( () => [ ...data.rows ].sort( ( a, b ) => ORDER.indexOf( status( a ) ) - ORDER.indexOf( status( b ) ) ), [ data.rows ] );
	const groups = useMemo( () => [ ...new Set( data.rows.map( ( row ) => row.group ) ) ].sort(), [ data.rows ] );
	const fields = useMemo( () => buildFields( groups ), [ groups ] );
	const shown = useMemo( () => filterSortAndPaginate( rows, view, fields ), [ rows, view, fields ] );
	const missing = rows.filter( ( row ) => status( row ) === 'missing' ).length;
	const translated = rows.filter( ( row ) => row.translation !== '' ).length;

	/** Runs a request; the drafts of the texts it saved are cleared, the others kept. */
	const run = ( what: string, request: () => Promise< StringsResponse >, done: ( next: StringsResponse ) => string, saved: string[] | Record< string, string > = [] ) => {
		// One at a time: the table's own actions are not disabled meanwhile.
		if ( busyRef.current ) {
			return Promise.resolve();
		}

		busyRef.current = what;
		setBusy( what );

		return request()
			.then( ( next ) => {
				onData( next );
				if ( Array.isArray( saved ) ) {
					drafts.clear( saved );
				} else {
					drafts.settle( saved );
				}
				setSelection( [] );
				notifySuccess( done( next ) );
			} )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'That did not work. Try again.', 'gds-content-translation' ) ) ) )
			.finally( () => {
				busyRef.current = '';
				setBusy( '' );
			} );
	};

	const translatedMessage = ( next: StringsResponse ) =>
		sprintf(
			/* translators: %d: number of texts */
			_n( 'DeepL translated %d text.', 'DeepL translated %d texts.', next.changed ?? 0, 'gds-content-translation' ),
			next.changed ?? 0
		);

	const translate = ( ids: string[] = [] ) => run( 'translate', () => translateStrings( lang, ids ), translatedMessage );

	const save = () => {
		const sent = drafts.changes();

		return run( 'save', () => saveStrings( lang, { translations: sent } ), () => __( 'Translations saved.', 'gds-content-translation' ), sent );
	};

	const onRemark = ( row: StringRow, use: boolean ) =>
		void run( `remark-${ row.id }`, () => settleRemark( lang, row.id, use ), () => ( use ? __( 'Suggestion used.', 'gds-content-translation' ) : __( 'Marked as not an issue.', 'gds-content-translation' ) ), use ? [ row.id ] : [] );

	const actions = useMemo< Action< StringRow >[] >(
		() =>
			data.canTranslate
				? [
						{
							id: 'translate',
							label: __( 'Translate with DeepL', 'gds-content-translation' ),
							supportsBulk: true,
							isPrimary: true,
							isEligible: ( row ) => status( row ) === 'missing',
							callback: ( items ) => void translate( items.map( ( row ) => row.id ) ),
						},
				  ]
				: [],
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ data.canTranslate, lang ]
	);

	const cell = useMemo( () => ( { drafts, busy, language: data.language.name, onRemark } ), [ drafts, busy, data.language.name ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const hasQuery = !! view.search || ( view.filters ?? [] ).length > 0;

	return (
		<CellContext.Provider value={ cell }>
			<Flex className="gds-ct-forms__toolbar" justify="flex-start" gap={ 2 } wrap>
				{ data.canTranslate && (
					<Button variant={ missing ? 'primary' : 'secondary' } isBusy={ busy === 'translate' } disabled={ !! busy || ! missing || dirty.length > 0 } accessibleWhenDisabled onClick={ () => void translate() }>
						{ missing
							? sprintf(
									/* translators: %d: number of texts without a translation */
									_n( 'Translate %d missing text with DeepL', 'Translate %d missing texts with DeepL', missing, 'gds-content-translation' ),
									missing
							  )
							: __( 'Everything is translated', 'gds-content-translation' ) }
					</Button>
				) }
				{ data.canReview && (
					<Button
						variant="secondary"
						isBusy={ busy === 'review' }
						disabled={ !! busy || ! translated || dirty.length > 0 }
						accessibleWhenDisabled
						onClick={ () =>
							void run( 'review', () => reviewStrings( lang ), ( next ) =>
								next.changed
									? sprintf(
											/* translators: %d: number of remarks */
											_n( 'The AI review has %d remark.', 'The AI review has %d remarks.', next.changed, 'gds-content-translation' ),
											next.changed
									  )
									: __( 'The AI review found nothing to change.', 'gds-content-translation' )
							)
						}
					>
						{ __( 'Check with the AI review', 'gds-content-translation' ) }
					</Button>
				) }
				<Button variant={ dirty.length ? 'primary' : 'secondary' } isBusy={ busy === 'save' } disabled={ !! busy || ! dirty.length } accessibleWhenDisabled onClick={ () => void save() }>
					{ dirty.length
						? sprintf(
								/* translators: %d: number of changed texts */
								_n( 'Save %d change', 'Save %d changes', dirty.length, 'gds-content-translation' ),
								dirty.length
						  )
						: __( 'Saved', 'gds-content-translation' ) }
				</Button>
			</Flex>
			<ErrorBoundary context="table">
				<div className="gds-ct__table gds-ct-strings__table">
					<DataViews< StringRow >
						data={ shown.data }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						paginationInfo={ shown.paginationInfo }
						defaultLayouts={ { table: { layout: defaultView.layout } } }
						selection={ selection }
						onChangeSelection={ setSelection }
						getItemId={ ( row ) => row.id }
						searchLabel={ __( 'Search texts', 'gds-content-translation' ) }
						empty={
							<div className="gds-ct__empty">
								<p>{ hasQuery ? __( 'No texts match your search and filters.', 'gds-content-translation' ) : __( 'There are no texts to translate.', 'gds-content-translation' ) }</p>
								{ hasQuery && (
									<Button variant="secondary" onClick={ () => setView( { ...view, page: 1, search: '', filters: [] } ) }>
										{ __( 'Clear search and filters', 'gds-content-translation' ) }
									</Button>
								) }
							</div>
						}
					/>
				</div>
			</ErrorBoundary>
		</CellContext.Provider>
	);
}
