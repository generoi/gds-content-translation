/**
 * Content translation status: a tab per post type, the summary counts, the
 * bulk translation queue, and the DataViews table of default-language posts
 * with a column per translation language. Rows come a page at a time from
 * the server, which searches, filters and sorts all of them.
 */
import { speak } from '@wordpress/a11y';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { buildActions } from '../actions';
import { errorMessage, runAction } from '../api';
import { DataViews } from '../dataviews';
import type { View } from '../dataviews';
import { getSettings } from '../settings';
import type { ActionResponse, Row } from '../types';
import { ErrorBoundary } from '../ui/error-boundary';
import { Notices } from '../ui/notices';
import { notifyError } from '../ui/notify';
import { BulkProgress } from './bulk-progress';
import { DEFAULT_LAYOUTS, PER_PAGE_SIZES, createDefaultView } from './default-view';
import { EmptyState } from './empty-state';
import { buildFields } from './fields';
import { PostTypeTabs } from './post-type-tabs';
import { ScreenContext } from './screen-context';
import type { ScreenContextValue } from './screen-context';
import { SummaryBar } from './summary-bar';
import { useJobs } from './use-jobs';
import { useRows } from './use-rows';
import { useStoredView } from './use-stored-view';

/** How often rows with a review running are reloaded, and for how long. */
const REVIEW_POLL = 5000;
const REVIEW_POLL_FOR = 15 * 60 * 1000;

function getItemId( row: Row ): string {
	return row.id;
}

/** Reports the failures of an action; the successes are the caller's to tell. */
function reportFailures( response: ActionResponse ): void {
	const failed = response.results.filter( ( result ) => ! result.ok );

	if ( ! failed.length ) {
		return;
	}

	if ( failed.length === 1 ) {
		notifyError( failed[ 0 ]?.message || response.message );

		return;
	}

	notifyError(
		sprintf(
			/* translators: 1: number of rows, 2: the first error message */
			__( '%1$d rows could not be changed. %2$s', 'gds-content-translation' ),
			failed.length,
			failed[ 0 ]?.message ?? ''
		)
	);
}

export function StatusScreen() {
	const settings = getSettings();
	const [ postType, setPostType ] = useState( settings.postType );
	const [ view, setView, resetView ] = useStoredView( postType );
	const rows = useRows( postType, view );
	const { refresh } = rows;
	const jobs = useJobs( useCallback( () => refresh( true ), [ refresh ] ) );
	const followJobs = jobs.follow;
	const [ busy, setBusyState ] = useState< Record< string, string > >( {} );
	const [ selection, setSelection ] = useState< string[] >( [] );
	const pendingFocusRef = useRef( '' );
	const reviewPollStartedRef = useRef( 0 );

	const setBusy = useCallback( ( key: string, value: string | null ) => {
		setBusyState( ( current ) => {
			const next = { ...current };

			if ( value ) {
				next[ key ] = value;
			} else {
				delete next[ key ];
			}

			return next;
		} );
	}, [] );

	const run = useCallback( async ( action: string, ids: number[], args: Record< string, unknown > = {} ) => {
		try {
			const response = await runAction( action, ids, args );
			reportFailures( response );

			return response;
		} catch ( caught ) {
			notifyError( errorMessage( caught, __( 'The change could not be saved. Check your connection and try again.', 'gds-content-translation' ) ) );
			throw caught;
		}
	}, [] );

	const focusAfterRefresh = useCallback( ( key: string ) => {
		pendingFocusRef.current = key;
	}, [] );

	const screen: ScreenContextValue = useMemo(
		() => ( { postType, busy, setBusy, refresh, patchRow: rows.patchRow, run, focusAfterRefresh, followJobs } ),
		[ postType, busy, setBusy, refresh, rows.patchRow, run, focusAfterRefresh, followJobs ]
	);

	// The fields and actions are built once, so DataViews does not rebuild
	// its columns or close an open modal when a cell gets busy.
	const fields = useMemo( () => buildFields( settings ), [ settings ] );
	const actions = useMemo( () => buildActions( settings, { run, refresh, followJobs } ), [ settings, run, refresh, followJobs ] );

	const changeView = useCallback(
		( next: View ) => {
			// A new page, search or filter shows other rows: start a new selection.
			if ( next.page !== view.page || next.search !== view.search || JSON.stringify( next.filters ) !== JSON.stringify( view.filters ) || next.perPage !== view.perPage ) {
				setSelection( [] );
			}

			setView( next );
		},
		[ view, setView ]
	);

	const changePostType = useCallback( ( next: string ) => {
		setPostType( next );
		setSelection( [] );

		try {
			window.history.replaceState( null, '', addQueryArgs( window.location.href, { gds_ct_post_type: next } ) );
		} catch {
			// The URL just does not follow.
		}
	}, [] );

	// Back from the editor (or a new translation) in another tab: reload the
	// rows, and let go of the cells marked "Creating…".
	useEffect( () => {
		let left = false;
		const onVisibility = () => {
			if ( document.visibilityState === 'hidden' ) {
				left = true;

				return;
			}

			if ( left ) {
				left = false;
				setBusyState( {} );
				refresh( true );
			}
		};

		document.addEventListener( 'visibilitychange', onVisibility );

		return () => document.removeEventListener( 'visibilitychange', onVisibility );
	}, [ refresh ] );

	// A single translation that went fine needs no "finished" panel.
	const dismissFinished = jobs.dismissFinished;
	const singleDone = jobs.jobs.length === 1 && ( jobs.jobs[ 0 ]?.status === 'done' || jobs.jobs[ 0 ]?.status === 'skipped' );

	useEffect( () => {
		if ( singleDone ) {
			dismissFinished();
		}
	}, [ singleDone, dismissFinished ] );

	// Rows with an AI review running are reloaded until it is done.
	const data = rows.data?.rows;
	const reviewing = useMemo( () => ( data ?? [] ).some( ( row ) => Object.values( row.languages ).some( ( cell ) => cell.audit?.status === 'pending' ) ), [ data ] );

	useEffect( () => {
		if ( ! reviewing ) {
			reviewPollStartedRef.current = 0;

			return;
		}

		reviewPollStartedRef.current ||= Date.now();

		if ( Date.now() - reviewPollStartedRef.current > REVIEW_POLL_FOR ) {
			return;
		}

		const timer = setTimeout( () => refresh( true ), REVIEW_POLL );

		return () => clearTimeout( timer );
	}, [ reviewing, rows.loadedAt, refresh ] );

	// Focus back on the cell an action was taken in, or on the row named in
	// the URL (the review page's "Back" link), once the rows are there.
	useEffect( () => {
		const key = pendingFocusRef.current;
		let target: HTMLElement | null = null;

		if ( key ) {
			pendingFocusRef.current = '';
			target = document.querySelector< HTMLElement >( `[data-cell="${ key }"]` );
		} else if ( rows.loadedAt && window.location.hash.startsWith( '#gds-ct-row-' ) ) {
			target = document.getElementById( window.location.hash.slice( 1 ) );

			if ( target ) {
				window.history.replaceState( null, '', window.location.pathname + window.location.search );
				target.scrollIntoView( { block: 'center' } );
				target = target.querySelector( 'a' ) ?? target;
			}
		}

		target?.focus();
	}, [ rows.loadedAt ] );

	// A failed reload keeps the rows on screen and says so in a snackbar,
	// rather than a notice that pushes the table down.
	useEffect( () => {
		if ( rows.error && rows.data ) {
			notifyError( rows.error );
		} else if ( rows.error ) {
			speak( rows.error, 'assertive' );
		}
	}, [ rows.error ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const isModified = useMemo( () => {
		const initial = createDefaultView();

		return JSON.stringify( [ view.fields, view.sort, view.perPage ] ) !== JSON.stringify( [ initial.fields, initial.sort, initial.perPage ] );
	}, [ view ] );

	if ( ! settings.postTypes.length ) {
		return <p>{ __( 'No translatable post types are configured in Polylang.', 'gds-content-translation' ) }</p>;
	}

	const hasQuery = !! view.search || ( view.filters ?? [] ).length > 0;

	return (
		<ScreenContext.Provider value={ screen }>
			<div className="gds-ct">
				<PostTypeTabs settings={ settings } current={ postType } onChange={ changePostType } />
				{ /* Always there, and of a fixed height, so the table never moves. */ }
				<SummaryBar summary={ rows.data?.summary ?? null } view={ view } onChangeView={ changeView } />
				<ErrorBoundary context="table">
					<div className={ `gds-ct__table${ rows.data ? '' : ' is-loading' }` }>
						<DataViews< Row >
							data={ data ?? [] }
							fields={ fields }
							view={ view }
							onChangeView={ changeView }
							actions={ actions }
							isLoading={ rows.isLoading && ! rows.data }
							paginationInfo={ { totalItems: rows.data?.total ?? 0, totalPages: rows.data?.totalPages ?? 1 } }
							defaultLayouts={ DEFAULT_LAYOUTS }
							selection={ selection }
							onChangeSelection={ setSelection }
							getItemId={ getItemId }
							config={ { perPageSizes: PER_PAGE_SIZES } }
							searchLabel={ __( 'Search titles', 'gds-content-translation' ) }
							empty={ <EmptyState hasQuery={ hasQuery } error={ rows.data ? '' : rows.error } onClear={ () => changeView( { ...view, page: 1, search: '', filters: [] } ) } /> }
							onReset={ isModified ? resetView : false }
						/>
					</div>
				</ErrorBoundary>
				{ /* Docked at the bottom of the screen, over the page rather than in it. */ }
				{ /* One translation from a cell shows in its cell; the panel is for batches, and for failures. */ }
				{ ( jobs.jobs.length > 1 || jobs.jobs.some( ( job ) => job.status === 'failed' ) ) && (
					<ErrorBoundary context="progress" fallback={ null }>
						<BulkProgress jobs={ jobs.jobs } onCancel={ jobs.cancelQueued } onDismiss={ jobs.dismissFinished } />
					</ErrorBoundary>
				) }
				<Notices />
			</div>
		</ScreenContext.Provider>
	);
}
