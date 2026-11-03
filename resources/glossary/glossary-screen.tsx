/**
 * Translation glossary (Polylang → Translation glossary): the terms DeepL
 * and the AI review must translate a certain way. A tab per status, the
 * suggestions from the AI review and proofreading being the inbox, and the
 * DataViews table of terms, a page at a time from the server, which
 * searches, filters and sorts them all. Approved terms are sent to DeepL.
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { download, plus, upload } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import { errorMessage } from '../api';
import { DataViews } from '../dataviews';
import type { View } from '../dataviews';
import { getSettings } from '../settings';
import type { GlossarySettings } from '../settings';
import { ErrorBoundary } from '../ui/error-boundary';
import { Notices } from '../ui/notices';
import { notifyError, notifySuccess } from '../ui/notify';
import { buildActions } from './actions';
import type { TermOps } from './actions';
import { bulkTerms, fetchSync, filterValues, sendSync } from './api';
import { buildFields, statusLabels } from './fields';
import { ImportModal } from './import-modal';
import { SyncStatus } from './sync-status';
import { TermModal } from './term-modal';
import type { BulkOperation, Term, TermStatus, TermsResponse } from './types';
import { useTerms } from './use-terms';

const TABS: TermStatus[] = [ 'approved', 'suggested', 'draft', 'rejected' ];

/** While changes wait to be sent, DeepL's line is checked this often, for so long. */
const SYNC_POLL = 20 * 1000;
const SYNC_POLL_FOR = 10 * 60 * 1000;

const fallback: GlossarySettings = { sourceLang: '', targetLangs: [], exportUrl: '', deepl: false, review: false, url: '', languages: {} };

/** A deep link may name the tab (`&status=suggested`), the language (`&target_lang=SV`) and open the import (`&import=1`). */
const query = new URLSearchParams( window.location.search );

function defaultView( status: TermStatus ): View {
	const suggested = status === 'suggested';
	const language = query.get( 'target_lang' )?.toUpperCase() ?? '';

	return {
		type: 'table',
		page: 1,
		perPage: 50,
		search: '',
		filters: language ? [ { field: 'targetLang', operator: 'is', value: language } ] : [],
		// The suggestions seen most often first: the likeliest to be right.
		sort: suggested ? { field: 'hits', direction: 'desc' } : { field: 'source', direction: 'asc' },
		titleField: 'source',
		showTitle: true,
		fields: [ 'target', 'targetLang', suggested ? 'origin' : 'note', 'updatedAt' ],
		layout: {
			density: 'compact',
			styles: { source: { width: '28%' }, target: { width: '28%' }, targetLang: { width: 110 }, updatedAt: { width: 130 }, origin: { width: '30%' } },
		},
	};
}

function initialStatus(): TermStatus {
	const status = query.get( 'status' ) as TermStatus | null;

	return status && TABS.includes( status ) ? status : 'approved';
}

/**
 * The site's languages, and any other the terms have (an imported file may
 * hold languages this site does not translate to), by their code.
 */
function useWithPairs( settings: GlossarySettings, pairs: TermsResponse[ 'pairs' ] | undefined ): GlossarySettings {
	const extra = ( pairs ?? [] )
		.map( ( pair ) => pair.targetLang )
		.filter( ( code ) => ! settings.targetLangs.some( ( language ) => language.code === code ) )
		.join( ',' );

	return useMemo( () => ( extra ? { ...settings, targetLangs: [ ...settings.targetLangs, ...extra.split( ',' ).map( ( code ) => ( { code, name: code } ) ) ] } : settings ), [ settings, extra ] );
}

function getItemId( term: Term ): string {
	return String( term.id );
}

export function GlossaryScreen() {
	const settings = getSettings().glossary ?? fallback;
	const [ status, setStatus ] = useState< TermStatus >( initialStatus );
	const [ views, setViews ] = useState< Partial< Record< TermStatus, View > > >( {} );
	const view = views[ status ] ?? defaultView( status );
	const terms = useTerms( status, view );
	const { refresh, setSync } = terms;
	const [ selection, setSelection ] = useState< string[] >( [] );
	const glossary = useWithPairs( settings, terms.data?.pairs );
	const [ editing, setEditing ] = useState< Term | 'new' | null >( null );
	const [ importing, setImporting ] = useState( query.get( 'import' ) === '1' );
	const [ isSending, setIsSending ] = useState( false );
	const syncPollStartedRef = useRef( 0 );

	const setView = useCallback(
		( next: View ) => {
			// A new page, search or filter shows other terms: start a new selection.
			if ( next.page !== view.page || next.search !== view.search || JSON.stringify( next.filters ) !== JSON.stringify( view.filters ) || next.perPage !== view.perPage ) {
				setSelection( [] );
			}

			setViews( ( current ) => ( { ...current, [ status ]: next } ) );
		},
		[ status, view ]
	);

	const changeStatus = ( next: TermStatus ) => {
		// The language and search go along to the other tab.
		const target = views[ next ] ?? defaultView( next );
		setViews( ( current ) => ( { ...current, [ next ]: { ...target, page: 1, search: view.search, filters: view.filters } } ) );
		setStatus( next );
		setSelection( [] );
	};

	const bulk = useCallback(
		async ( items: Term[], operation: BulkOperation ): Promise< boolean > => {
			try {
				const response = await bulkTerms(
					items.map( ( term ) => term.id ),
					operation
				);
				notifySuccess( response.message );
				setSelection( [] );
				refresh( true );

				return true;
			} catch ( caught ) {
				notifyError( errorMessage( caught, __( 'The change could not be saved. Check your connection and try again.', 'gds-content-translation' ) ) );

				return false;
			}
		},
		[ refresh ]
	);

	const ops = useMemo< TermOps >( () => ( { bulk, edit: setEditing } ), [ bulk ] );
	const fields = useMemo( () => buildFields( glossary ), [ glossary ] );
	const actions = useMemo( () => buildActions( ops ), [ ops ] );

	const send = useCallback( () => {
		setIsSending( true );
		sendSync()
			.then( setSync )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'The glossary could not be sent to DeepL.', 'gds-content-translation' ) ) ) )
			.finally( () => setIsSending( false ) );
	}, [ setSync ] );

	// Changes on their way to DeepL: the line follows until they are there.
	const sync = terms.data?.sync ?? null;
	const [ syncRetry, setSyncRetry ] = useState( 0 );

	useEffect( () => {
		if ( ! sync?.pending || ! sync.configured ) {
			syncPollStartedRef.current = 0;

			return;
		}

		syncPollStartedRef.current ||= Date.now();

		if ( Date.now() - syncPollStartedRef.current > SYNC_POLL_FOR ) {
			return;
		}

		const timer = setTimeout( () => {
			fetchSync()
				.then( setSync )
				// Asks again after a blip.
				.catch( () => setSyncRetry( ( count ) => count + 1 ) );
		}, SYNC_POLL );

		return () => clearTimeout( timer );
	}, [ sync, setSync, syncRetry ] );

	// A failed reload keeps the terms on screen and says so in a snackbar.
	useEffect( () => {
		if ( terms.error && terms.data ) {
			notifyError( terms.error );
		}
	}, [ terms.error ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const language = filterValues( view, 'targetLang' )[ 0 ] ?? '';
	const exportUrl = glossary.exportUrl && language ? addQueryArgs( glossary.exportUrl, { target_lang: language } ) : glossary.exportUrl;
	const counts = terms.data?.counts;
	const total = counts ? TABS.reduce( ( sum, tab ) => sum + counts[ tab ], 0 ) : 0;
	const hasQuery = !! view.search || ( view.filters ?? [] ).length > 0;
	const labels = statusLabels();

	const empty = ( () => {
		if ( terms.error && ! terms.data ) {
			return (
				<div className="gds-ct__empty" role="alert">
					<p>{ terms.error }</p>
				</div>
			);
		}

		if ( hasQuery ) {
			return (
				<div className="gds-ct__empty">
					<p>{ __( 'No terms match your search and filters.', 'gds-content-translation' ) }</p>
					<Button variant="secondary" onClick={ () => setView( { ...view, page: 1, search: '', filters: [] } ) }>
						{ __( 'Clear search and filters', 'gds-content-translation' ) }
					</Button>
				</div>
			);
		}

		if ( counts && total === 0 ) {
			return (
				<div className="gds-ct__empty">
					<p>{ __( 'No terms yet. Import a CSV from DeepL or add a term.', 'gds-content-translation' ) }</p>
					<div className="gds-ct-glossary__buttons is-centered">
						<Button variant="primary" icon={ upload } onClick={ () => setImporting( true ) }>
							{ __( 'Import CSV', 'gds-content-translation' ) }
						</Button>
						<Button variant="secondary" icon={ plus } onClick={ () => setEditing( 'new' ) }>
							{ __( 'Add term', 'gds-content-translation' ) }
						</Button>
					</div>
				</div>
			);
		}

		const messages: Record< TermStatus, string > = {
			approved: __( 'No terms in use.', 'gds-content-translation' ),
			suggested: __( 'No suggestions to look at. The AI review and proofreading add them here.', 'gds-content-translation' ),
			draft: __( 'No drafts.', 'gds-content-translation' ),
			rejected: __( 'No rejected terms.', 'gds-content-translation' ),
		};

		return (
			<div className="gds-ct__empty">
				<p>{ messages[ status ] }</p>
			</div>
		);
	} )();

	return (
		<div className="gds-ct gds-ct-glossary">
			<div className="gds-ct-glossary__header">
				<div className="gds-ct-glossary__intro">
					<p>{ glossary.review ? __( 'Terms DeepL and the AI review must translate this way.', 'gds-content-translation' ) : __( 'Terms DeepL must translate this way.', 'gds-content-translation' ) }</p>
					<SyncStatus sync={ sync } isSending={ isSending } onSend={ send } />
				</div>
				<div className="gds-ct-glossary__buttons">
					<Button variant="primary" icon={ plus } onClick={ () => setEditing( 'new' ) }>
						{ __( 'Add term', 'gds-content-translation' ) }
					</Button>
					<Button variant="secondary" icon={ upload } onClick={ () => setImporting( true ) }>
						{ __( 'Import CSV', 'gds-content-translation' ) }
					</Button>
					{ exportUrl && (
						<Button
							variant="secondary"
							icon={ download }
							href={ exportUrl }
							description={
								language
									? /* translators: %s: language name */
									  sprintf( __( 'Export the terms in use in %s as a CSV for DeepL', 'gds-content-translation' ), fields.find( ( field ) => field.id === 'targetLang' )?.elements?.find( ( element ) => element.value === language )?.label ?? language )
									: __( 'Export the terms in use as a CSV for DeepL', 'gds-content-translation' )
							}
						>
							{ __( 'Export CSV', 'gds-content-translation' ) }
						</Button>
					) }
				</div>
			</div>
			<nav className="gds-ct__tabs gds-ct-glossary__tabs nav-tab-wrapper" aria-label={ __( 'Status', 'gds-content-translation' ) }>
				{ TABS.map( ( tab ) => {
					const count = counts?.[ tab ] ?? 0;

					return (
						<button key={ tab } type="button" className={ `nav-tab${ tab === status ? ' nav-tab-active' : '' }` } aria-current={ tab === status ? 'page' : undefined } onClick={ () => changeStatus( tab ) }>
							<span>{ labels[ tab ] }</span>
							{ counts && (
								<span className={ `gds-ct-glossary__count${ tab === 'suggested' && count > 0 ? ' is-new' : '' }` }>
									<span aria-hidden="true">{ count.toLocaleString() }</span>
									<span className="screen-reader-text">
										{
											/* translators: %d: number of terms */
											sprintf( _n( '%d term', '%d terms', count, 'gds-content-translation' ), count )
										}
									</span>
								</span>
							) }
						</button>
					);
				} ) }
			</nav>
			<ErrorBoundary context="table">
				<div className={ `gds-ct__table gds-ct-glossary__table${ terms.data ? '' : ' is-loading' }` }>
					<DataViews< Term >
						data={ terms.data?.items ?? [] }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						isLoading={ terms.isLoading && ! terms.data }
						paginationInfo={ { totalItems: terms.data?.total ?? 0, totalPages: terms.data?.totalPages ?? 1 } }
						defaultLayouts={ { table: { layout: defaultView( status ).layout } } }
						selection={ selection }
						onChangeSelection={ setSelection }
						getItemId={ getItemId }
						isItemClickable={ () => true }
						onClickItem={ ( term ) => setEditing( term ) }
						searchLabel={ __( 'Search terms', 'gds-content-translation' ) }
						empty={ empty }
					/>
				</div>
			</ErrorBoundary>
			{ editing && (
				<TermModal
					glossary={ glossary }
					term={ editing === 'new' ? null : editing }
					targetLang={ language }
					onSaved={ () => {
						notifySuccess( editing === 'new' ? __( 'Term added.', 'gds-content-translation' ) : __( 'Term saved.', 'gds-content-translation' ) );
						refresh( true );
					} }
					onClose={ () => setEditing( null ) }
				/>
			) }
			{ importing && <ImportModal glossary={ glossary } targetLang={ language } onImported={ () => refresh( true ) } onClose={ () => setImporting( false ) } /> }
			<Notices />
		</div>
	);
}
