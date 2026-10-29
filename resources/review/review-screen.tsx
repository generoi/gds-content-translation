/**
 * The AI review page's remarks: a count per severity to filter by, and the
 * DataViews table of texts with their remarks. Each remark is handled from
 * its row, or several at once from the selection (use the suggestions, not
 * an issue, fixed). The page around it is server-rendered.
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import { DataViews, filterSortAndPaginate } from '../dataviews';
import type { Action, Field, View } from '../dataviews';
import { clearNotices, notifyError, notifySuccess } from '../ui/notify';
import { changeRemarks, fetchReview } from './api';
import { ReviewContext } from './context';
import type { ReviewContextValue } from './context';
import { EditModal } from './edit-modal';
import { syncHeader } from './header';
import { RemarkCell, SegmentCell, SourceCell, TranslationCell, canUse, isOpen, severityLabel } from './remark-cell';
import type { RemarkOperation, RemarkRow, RemarkState, ReviewData, Severity } from './types';

const SEVERITIES: Severity[] = [ 'error', 'warning', 'suggestion' ];
const CLOSED: RemarkState[] = [ 'resolved', 'dismissed' ];

function stateFilter( states: RemarkState[] ): View[ 'filters' ] {
	return [ { field: 'state', operator: 'isAny', value: states } ];
}

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 50,
	search: '',
	filters: stateFilter( [ 'open' ] ),
	sort: { field: 'segment', direction: 'asc' },
	fields: [ 'segment', 'source', 'translation', 'remark' ],
	layout: {
		density: 'compact',
		styles: {
			segment: { width: 48 },
			source: { width: '28%' },
			translation: { width: '32%' },
			remark: { width: '40%' },
		},
	},
};

function stripTags( html: string ): string {
	return html.replace( /<[^>]*>/g, '' );
}

function buildFields( data: ReviewData | null ): Field< RemarkRow >[] {
	const categories = new Map< string, string >();

	for ( const row of data?.rows ?? [] ) {
		if ( row.category ) {
			categories.set( row.category, row.categoryLabel );
		}
	}

	return [
		{
			id: 'segment',
			label: '#',
			type: 'integer',
			getValue: ( { item } ) => item.segment,
			render: SegmentCell,
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'source',
			/* translators: %s: language name, e.g. Finnish */
			label: data ? sprintf( __( 'Original (%s)', 'gds-content-translation' ), data.sourceLanguage ) : __( 'Original', 'gds-content-translation' ),
			getValue: ( { item } ) => stripTags( item.source ),
			render: SourceCell,
			enableSorting: false,
			enableGlobalSearch: true,
			filterBy: false,
		},
		{
			id: 'translation',
			/* translators: %s: language name, e.g. English */
			label: data ? sprintf( __( 'Translation (%s)', 'gds-content-translation' ), data.targetLanguage ) : __( 'Translation', 'gds-content-translation' ),
			getValue: ( { item } ) => stripTags( item.translation ),
			render: TranslationCell,
			enableSorting: false,
			enableGlobalSearch: true,
			filterBy: false,
		},
		{
			id: 'remark',
			label: __( 'AI remark', 'gds-content-translation' ),
			getValue: ( { item } ) => item.issue,
			render: RemarkCell,
			enableSorting: false,
			enableGlobalSearch: true,
			filterBy: false,
		},
		{
			id: 'severity',
			label: __( 'Severity', 'gds-content-translation' ),
			getValue: ( { item } ) => item.severity,
			elements: SEVERITIES.map( ( severity ) => ( { value: severity, label: severityLabel( severity ) } ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
		},
		{
			id: 'category',
			label: __( 'Kind', 'gds-content-translation' ),
			getValue: ( { item } ) => item.category,
			elements: [ ...categories ].map( ( [ value, label ] ) => ( { value, label } ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
		},
		{
			id: 'state',
			label: __( 'Status', 'gds-content-translation' ),
			getValue: ( { item } ) => item.state,
			elements: [
				{ value: 'open', label: __( 'Open', 'gds-content-translation' ) },
				{ value: 'resolved', label: __( 'Fixed', 'gds-content-translation' ) },
				{ value: 'dismissed', label: __( 'Not an issue', 'gds-content-translation' ) },
				{ value: 'none', label: __( 'No remarks', 'gds-content-translation' ) },
			],
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
		},
	];
}

function doneMessage( operation: RemarkOperation, count: number ): string {
	switch ( operation ) {
		case 'use':
			/* translators: %d: number of suggestions */
			return sprintf( _n( 'The suggestion is saved to the translation.', '%d suggestions are saved to the translation.', count, 'gds-content-translation' ), count );
		case 'edit':
			return __( 'Your text is saved to the translation.', 'gds-content-translation' );
		case 'fixed':
			/* translators: %d: number of remarks */
			return sprintf( _n( 'Marked as fixed.', '%d remarks marked as fixed.', count, 'gds-content-translation' ), count );
		case 'dismiss':
			/* translators: %d: number of remarks */
			return sprintf( _n( 'Marked as not an issue.', '%d remarks marked as not an issue.', count, 'gds-content-translation' ), count );
		case 'reopen':
			/* translators: %d: number of remarks */
			return sprintf( _n( 'The remark is open again.', '%d remarks are open again.', count, 'gds-content-translation' ), count );
	}
}

/** Which state filter the view has: open, handled, or none (every text). */
function stateMode( view: View ): 'open' | 'closed' | 'all' | 'other' {
	const filter = ( view.filters ?? [] ).find( ( candidate ) => candidate.field === 'state' );

	if ( ! filter ) {
		return 'all';
	}

	const value = Array.isArray( filter.value ) ? [ ...filter.value ].sort().join( ',' ) : '';

	if ( value === 'open' ) {
		return 'open';
	}

	return value === [ ...CLOSED ].sort().join( ',' ) ? 'closed' : 'other';
}

export function ReviewScreen( { postId }: { postId: number } ) {
	const [ data, setData ] = useState< ReviewData | null >( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ selection, setSelection ] = useState< string[] >( [] );
	const [ busy, setBusy ] = useState< Set< number > >( new Set() );
	const [ editing, setEditing ] = useState< RemarkRow | null >( null );

	useEffect( () => {
		fetchReview( postId )
			.then( setData )
			.catch( ( error: unknown ) => setLoadError( errorMessage( error, __( 'The remarks could not be loaded. Reload the page.', 'gds-content-translation' ) ) ) );
	}, [ postId ] );

	const run = useCallback(
		async ( operation: RemarkOperation, rows: RemarkRow[], text = '' ): Promise< boolean > => {
			const ids = rows.map( ( row ) => row.remarkId ).filter( Boolean );

			if ( ! ids.length ) {
				return false;
			}

			setBusy( ( current ) => new Set( [ ...current, ...ids ] ) );

			try {
				const response = await changeRemarks( postId, operation, ids, text );
				const failed = response.results.filter( ( result ) => ! result.ok );
				const done = response.results.length - failed.length;

				setData( response );
				syncHeader( response.counts );
				clearNotices();

				if ( done > 0 ) {
					notifySuccess( doneMessage( operation, done ) );
				}

				if ( failed.length === 1 ) {
					notifyError( failed[ 0 ]?.message ?? '' );
				} else if ( failed.length > 1 ) {
					notifyError(
						/* translators: 1: number of remarks, 2: the first error message */
						sprintf( __( '%1$d remarks could not be changed. %2$s', 'gds-content-translation' ), failed.length, failed[ 0 ]?.message ?? '' )
					);
				}

				return failed.length === 0;
			} catch ( error ) {
				notifyError( errorMessage( error, __( 'That did not work. Reload the page and try again.', 'gds-content-translation' ) ) );

				return false;
			} finally {
				setBusy( ( current ) => new Set( [ ...current ].filter( ( id ) => ! ids.includes( id ) ) ) );
			}
		},
		[ postId ]
	);

	const context = useMemo< ReviewContextValue >( () => ( { run, edit: setEditing, busy } ), [ run, busy ] );
	const fields = useMemo( () => buildFields( data ), [ data ] );

	const actions = useMemo< Action< RemarkRow >[] >(
		() => [
			{
				id: 'use',
				label: __( 'Use suggestion', 'gds-content-translation' ),
				supportsBulk: true,
				isEligible: canUse,
				callback: ( items ) => void run( 'use', items ),
			},
			{
				id: 'edit',
				label: __( 'Edit…', 'gds-content-translation' ),
				isEligible: ( item ) => isOpen( item ) && ! item.changed,
				callback: ( items ) => items[ 0 ] && setEditing( items[ 0 ] ),
			},
			{
				id: 'fixed',
				label: __( 'Mark as fixed', 'gds-content-translation' ),
				supportsBulk: true,
				isEligible: isOpen,
				callback: ( items ) => void run( 'fixed', items ),
			},
			{
				id: 'dismiss',
				label: __( 'Not an issue', 'gds-content-translation' ),
				supportsBulk: true,
				isEligible: isOpen,
				callback: ( items ) => void run( 'dismiss', items ),
			},
			{
				id: 'reopen',
				label: __( 'Reopen', 'gds-content-translation' ),
				supportsBulk: true,
				isEligible: ( item ) => CLOSED.includes( item.state ),
				callback: ( items ) => void run( 'reopen', items ),
			},
		],
		[ run ]
	);

	const rows = useMemo( () => data?.rows ?? [], [ data ] );
	const { data: shown, paginationInfo } = useMemo( () => filterSortAndPaginate( rows, view, fields ), [ rows, view, fields ] );

	// A handled remark leaves the "open" view: so does its selection.
	useEffect( () => {
		const ids = new Set( shown.map( ( row ) => row.id ) );
		setSelection( ( current ) => ( current.every( ( id ) => ids.has( id ) ) ? current : current.filter( ( id ) => ids.has( id ) ) ) );
	}, [ shown ] );

	// A view emptied by handling its last remark goes back to what is left:
	// no severity filter once that severity has no open remarks, the open
	// remarks once nothing is handled.
	useEffect( () => {
		if ( ! data ) {
			return;
		}

		setView( ( current ) => {
			const filters = current.filters ?? [];
			const severity = filters.find( ( filter ) => filter.field === 'severity' );
			const severities = Array.isArray( severity?.value ) ? ( severity.value as Severity[] ) : [];
			const emptySeverity = severities.length > 0 && severities.every( ( value ) => ! data.counts[ value ] );
			const emptyHandled = stateMode( current ) === 'closed' && ! data.rows.some( ( row ) => CLOSED.includes( row.state ) );

			if ( emptyHandled ) {
				return { ...current, page: 1, filters: stateFilter( [ 'open' ] ) };
			}

			return emptySeverity ? { ...current, page: 1, filters: filters.filter( ( filter ) => filter.field !== 'severity' ) } : current;
		} );
	}, [ data ] );

	if ( loadError ) {
		return <p className="gds-ct-review__error">{ loadError }</p>;
	}

	const counts = data?.counts ?? { error: 0, warning: 0, suggestion: 0 };
	const openTotal = counts.error + counts.warning + counts.suggestion;
	const closedTotal = rows.filter( ( row ) => CLOSED.includes( row.state ) ).length;
	const mode = stateMode( view );
	const severityFilter = ( view.filters ?? [] ).find( ( filter ) => filter.field === 'severity' );
	const onlySeverity = mode === 'open' && ( view.filters ?? [] ).length === 2 && Array.isArray( severityFilter?.value ) && severityFilter.value.length === 1 ? String( severityFilter.value[ 0 ] ) : '';

	const showStates = ( states: RemarkState[] | null, severity = '' ) =>
		setView( {
			...view,
			page: 1,
			filters: [ ...( states ? stateFilter( states ) ?? [] : [] ), ...( severity ? [ { field: 'severity', operator: 'isAny' as const, value: [ severity ] } ] : [] ) ],
		} );

	return (
		<ReviewContext.Provider value={ context }>
			<div className="gds-ct-review">
				<div className="gds-ct__summary gds-ct-review__summary" aria-busy={ ! data }>
					{ data && openTotal === 0 && <span className="gds-ct__summary-done">{ __( 'All remarks are handled.', 'gds-content-translation' ) }</span> }
					{ openTotal > 0 && (
						<span className="gds-ct__summary-group" role="group" aria-label={ __( 'Open remarks', 'gds-content-translation' ) }>
							<span className="gds-ct__summary-label">{ __( 'To fix', 'gds-content-translation' ) }</span>
							{ SEVERITIES.filter( ( severity ) => counts[ severity ] > 0 ).map( ( severity ) => (
								<button
									key={ severity }
									type="button"
									className={ `gds-ct__chip gds-ct-review__chip--${ severity }` }
									aria-pressed={ onlySeverity === severity }
									onClick={ () => showStates( [ 'open' ], onlySeverity === severity ? '' : severity ) }
								>
									{ severityLabel( severity ) } <strong>{ counts[ severity ] }</strong>
								</button>
							) ) }
						</span>
					) }
					{ data && <span className="gds-ct-review__modes" role="group" aria-label={ __( 'Show', 'gds-content-translation' ) }>
						<Button size="small" variant={ mode === 'open' ? 'secondary' : 'tertiary' } aria-pressed={ mode === 'open' } onClick={ () => showStates( [ 'open' ] ) }>
							{
								/* translators: %d: number of open remarks */
								sprintf( __( 'Open (%d)', 'gds-content-translation' ), openTotal )
							}
						</Button>
						<Button size="small" variant={ mode === 'closed' ? 'secondary' : 'tertiary' } aria-pressed={ mode === 'closed' } disabled={ ! closedTotal } accessibleWhenDisabled onClick={ () => showStates( CLOSED ) }>
							{
								/* translators: %d: number of handled remarks */
								sprintf( __( 'Handled (%d)', 'gds-content-translation' ), closedTotal )
							}
						</Button>
						<Button size="small" variant={ mode === 'all' ? 'secondary' : 'tertiary' } aria-pressed={ mode === 'all' } onClick={ () => showStates( null ) }>
							{ __( 'Whole page', 'gds-content-translation' ) }
						</Button>
					</span> }
				</div>
				<DataViews< RemarkRow >
					data={ shown }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					getItemId={ ( row ) => row.id }
					isItemClickable={ () => false }
					selection={ selection }
					onChangeSelection={ setSelection }
					isLoading={ ! data }
					defaultLayouts={ { table: { layout: DEFAULT_VIEW.layout } } }
					empty={
						<p className="gds-ct-review__empty">
							{ mode === 'open' && ! view.search ? __( 'Nothing left to fix here.', 'gds-content-translation' ) : __( 'No texts match.', 'gds-content-translation' ) }
						</p>
					}
				/>
			</div>
			{ editing && data && (
				<EditModal
					row={ editing }
					sourceLanguage={ data.sourceLanguage }
					onSave={ ( text ) => run( 'edit', [ editing ], text ) }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</ReviewContext.Provider>
	);
}
