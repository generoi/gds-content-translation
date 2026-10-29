/**
 * One language of one row, on one line of a fixed height.
 *
 * - Translated: a status chip (Draft, Published…, with a tick once proofread)
 *   that opens the editor, the AI review's open errors or remarks, open notes,
 *   and a ⋯ menu with everything else: View, the review, proofread, trash.
 * - Missing: AI translate, with Copy in the menu.
 * - Queued, translating or failed in the background: a chip of the same size,
 *   so nothing moves while it changes.
 * - In trash: says so, with Restore; translating anew and Delete permanently
 *   in the menu.
 *
 * AI translate and Copy are links to the same admin-post handler as before:
 * a copy opens the new draft in a new tab, AI translate (with the AI review
 * on) the review page in this one.
 */
import { DropdownMenu, MenuGroup, MenuItem, VisuallyHidden, __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { getSettings, languageCode } from '../settings';
import type { Cell, Row } from '../types';
import { celebrate } from '../ui/celebration';
import { dismissNotice, notifyError, notifySuccess } from '../ui/notify';
import { cellKey, useScreen } from '../list/screen-context';
import { AuditBadge } from './audit-indicator';

const MISSING: Cell = { state: 'missing', postId: 0 };

/** The Undo of a cell's trash, taken back once the cell changes another way. */
const undoNoticeId = ( key: string ): string => `gds-ct-undo-${ key }`;

/** Opens a menu item's page: menu items are buttons, not links. */
function go( url: string, newTab: boolean ): void {
	if ( newTab ) {
		window.open( url, '_blank', 'noopener' );
	} else {
		window.location.assign( url );
	}
}

export function aboutCell( title: string, languageName: string ): string {
	/* translators: 1: language name, 2: post title. */
	return sprintf( __( 'the %1$s translation of “%2$s”', 'gds-content-translation' ), languageName, title );
}

export function statusLabel( status: string | undefined ): string {
	switch ( status ) {
		case 'publish':
			return __( 'Published', 'gds-content-translation' );
		case 'future':
			return __( 'Scheduled', 'gds-content-translation' );
		case 'pending':
			return __( 'Pending', 'gds-content-translation' );
		case 'private':
			return __( 'Private', 'gds-content-translation' );
		default:
			return __( 'Draft', 'gds-content-translation' );
	}
}

export function LanguageCell( { item, slug, name }: { item: Row; slug: string; name: string } ) {
	const screen = useScreen();
	const cell = item.languages[ slug ] ?? MISSING;
	const key = cellKey( item.sourceId, slug );
	const about = aboutCell( item.title, name );

	return (
		<div className={ `gds-ct__cell gds-ct__cell--${ cell.state }${ screen.busy[ key ] === 'saving' ? ' is-saving' : '' }` } data-cell={ key } tabIndex={ -1 }>
			{ /* The column heading, for the stacked phone layout. */ }
			<span className="gds-ct__cell-language" aria-hidden="true">
				{ languageCode( slug ) }
			</span>
			{ cell.state === 'translated' ? (
				<TranslatedCell item={ item } slug={ slug } name={ name } cell={ cell } about={ about } />
			) : (
				<MissingCell item={ item } slug={ slug } name={ name } cell={ cell } about={ about } />
			) }
		</div>
	);
}

interface CellProps {
	item: Row;
	slug: string;
	name: string;
	cell: Cell;
	about: string;
}

function CellMenu( { about, children }: { about: string; children: ( close: () => void ) => React.ReactNode } ) {
	return (
		<DropdownMenu
			className="gds-ct__menu"
			icon="ellipsis"
			/* translators: %s: e.g. the Swedish translation of "Etusivu" */
			label={ sprintf( __( 'More actions for %s', 'gds-content-translation' ), about ) }
			toggleProps={ { size: 'small' } }
			popoverProps={ { placement: 'bottom-end' } }
		>
			{ ( { onClose } ) => <MenuGroup>{ children( onClose ) }</MenuGroup> }
		</DropdownMenu>
	);
}

function TranslatedCell( { item, slug, cell, about }: CellProps ) {
	const screen = useScreen();
	const settings = getSettings();
	const key = cellKey( item.sourceId, slug );
	const saving = screen.busy[ key ] === 'saving';
	const newTab = __( '(opens in a new tab)', 'gds-content-translation' );
	const status = statusLabel( cell.status );
	const audit = cell.audit;

	const setProofread = ( proofread: boolean ) => {
		const anchor = document.querySelector< HTMLElement >( `[data-cell="${ key }"] .gds-ct__status` );
		const patch = ( value: boolean ) =>
			screen.patchRow( item.sourceId, ( row ) => ( {
				...row,
				languages: { ...row.languages, [ slug ]: { ...cell, proofread: value } },
			} ) );

		screen.setBusy( key, 'saving' );
		patch( proofread );

		screen
			.run( 'proofread', [ item.sourceId ], { languages: [ slug ], proofread } )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( response.results[ 0 ]?.message || response.message );
				}

				speak( proofread ? __( 'Marked as proofread.', 'gds-content-translation' ) : __( 'Marked as not proofread.', 'gds-content-translation' ) );

				if ( proofread && anchor ) {
					celebrate( anchor );
				}

				// The summary counts change.
				screen.refresh( true );
			} )
			.catch( ( caught: unknown ) => {
				patch( ! proofread );
				notifyError( caught instanceof Error && caught.message ? caught.message : __( 'Could not save. Try again.', 'gds-content-translation' ) );
			} )
			.finally( () => screen.setBusy( key, null ) );
	};

	// No question first: the notice has an Undo.
	const trash = () => {
		screen.setBusy( key, 'saving' );
		screen
			.run( 'trash-translation', [ item.sourceId ], { languages: [ slug ] } )
			.then( ( response ) => {
				if ( response.ok ) {
					notifySuccess( response.message, [
						{
							label: __( 'Undo', 'gds-content-translation' ),
							onClick: () => {
								screen.run( 'restore-translation', [ item.sourceId ], { languages: [ slug ] } ).then( ( undone ) => {
									if ( undone.ok ) {
										notifySuccess( undone.message );
									}

									screen.focusAfterRefresh( key );
									screen.refresh( true );
								} );
							},
						},
					], undoNoticeId( key ) );
				}

				screen.focusAfterRefresh( key );
				screen.refresh( true );
			} )
			.catch( () => undefined )
			.finally( () => screen.setBusy( key, null ) );
	};

	// The review runs in the background; the cell says Reviewing… until it is done.
	const runReview = () => {
		screen.setBusy( key, 'saving' );
		screen
			.run( 'review', [ item.sourceId ], { languages: [ slug ] } )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( response.results[ 0 ]?.message || response.message );
				}

				screen.patchRow( item.sourceId, ( row ) => ( {
					...row,
					languages: { ...row.languages, [ slug ]: { ...cell, audit: { status: 'pending', errors: 0, other: 0, edited: false, error: '', url: cell.audit?.url ?? '' } } },
				} ) );
				screen.refresh( true );
			} )
			.catch( ( caught: unknown ) => notifyError( caught instanceof Error && caught.message ? caught.message : __( 'The review could not be started.', 'gds-content-translation' ) ) )
			.finally( () => screen.setBusy( key, null ) );
	};

	const statusName = cell.proofread
		? /* translators: %s: post status, e.g. Draft */
		  sprintf( __( '%s, proofread', 'gds-content-translation' ), status )
		: status;

	return (
		<>
			{ cell.editUrl ? (
				<a
					className={ `gds-ct__status gds-ct__status--${ cell.status ?? 'draft' }${ cell.proofread ? ' is-proofread' : '' }` }
					href={ cell.editUrl }
					target="_blank"
					rel="noopener noreferrer"
					title={ __( 'Edit', 'gds-content-translation' ) }
				>
					{ cell.proofread && <span className="gds-ct__tick" aria-hidden="true" /> }
					<span aria-hidden="true">{ status }</span>
					<VisuallyHidden>
						{ sprintf(
							/* translators: 1: status, e.g. "Draft, proofread", 2: e.g. the Swedish translation of "Etusivu", 3: (opens in a new tab) */
							__( '%1$s – edit %2$s %3$s', 'gds-content-translation' ),
							statusName,
							about,
							newTab
						) }
					</VisuallyHidden>
				</a>
			) : (
				<span className="gds-ct__status">{ status }</span>
			) }
			<AuditBadge audit={ audit } about={ about } />
			{ !! cell.openNotes && cell.editUrl && (
				<a className="gds-ct__notes" href={ cell.editUrl } target="_blank" rel="noopener noreferrer" title={ __( 'Open block editor notes', 'gds-content-translation' ) }>
					<span className="dashicons dashicons-admin-comments" aria-hidden="true" />
					{ cell.openNotes }
					<VisuallyHidden>
						{ ' ' }
						{ sprintf(
							/* translators: %s: e.g. the Swedish translation of "Etusivu" */
							__( 'open block editor notes on %s', 'gds-content-translation' ),
							about
						) }
					</VisuallyHidden>
				</a>
			) }
			<CellMenu about={ about }>
				{ ( close ) => (
					<>
						{ cell.editUrl && (
							<MenuItem onClick={ () => { close(); go( cell.editUrl ?? '', true ); } }>
								{ __( 'Edit', 'gds-content-translation' ) }
							</MenuItem>
						) }
						{ cell.viewUrl && (
							<MenuItem onClick={ () => { close(); go( cell.viewUrl ?? '', true ); } }>
								{ cell.status === 'publish' ? __( 'View', 'gds-content-translation' ) : __( 'Preview', 'gds-content-translation' ) }
							</MenuItem>
						) }
						{ audit && audit.status !== 'none' && audit.url && (
							<MenuItem onClick={ () => { close(); go( audit.url, false ); } }>
								{ __( 'Open AI review', 'gds-content-translation' ) }
							</MenuItem>
						) }
						{ settings.review && audit && ( audit.status === 'none' || audit.status === 'failed' || audit.status === 'stuck' ) && (
							<MenuItem onClick={ () => { close(); runReview(); } }>
								{ audit.status === 'none' ? __( 'Run AI review', 'gds-content-translation' ) : __( 'Run AI review again', 'gds-content-translation' ) }
							</MenuItem>
						) }
						<MenuItem
							disabled={ saving }
							onClick={ () => {
								close();
								setProofread( ! cell.proofread );
							} }
						>
							{ cell.proofread ? __( 'Mark as not proofread', 'gds-content-translation' ) : __( 'Mark as proofread', 'gds-content-translation' ) }
						</MenuItem>
						{ cell.canDelete && (
							<MenuItem
								isDestructive
								disabled={ saving }
								onClick={ () => {
									close();
									trash();
								} }
							>
								{ __( 'Move to trash', 'gds-content-translation' ) }
							</MenuItem>
						) }
					</>
				) }
			</CellMenu>
		</>
	);
}

function MissingCell( { item, slug, name, cell, about }: CellProps ) {
	const screen = useScreen();
	const settings = getSettings();
	const key = cellKey( item.sourceId, slug );
	const busy = screen.busy[ key ];
	const trashed = cell.state === 'trashed';
	const job = cell.job && ( cell.job.status === 'queued' || cell.job.status === 'running' || cell.job.status === 'failed' ) ? cell.job : null;
	const waiting = !! job && job.status !== 'failed';
	const blocked = !! busy || waiting;
	const canChangeTrashed = trashed && cell.canDelete;
	const [ confirmDelete, setConfirmDelete ] = useState( false );

	const change = ( action: 'restore-translation' | 'delete-translation' ) => {
		// Restored or deleted here: an Undo of its trash has nothing left to undo.
		dismissNotice( undoNoticeId( key ) );
		screen.setBusy( key, 'saving' );
		screen
			.run( action, [ item.sourceId ], { languages: [ slug ] } )
			.then( ( response ) => {
				if ( response.ok ) {
					notifySuccess( response.message );
				}

				screen.focusAfterRefresh( key );
				screen.refresh( true );
			} )
			.catch( () => undefined )
			.finally( () => screen.setBusy( key, null ) );
	};

	/** Marks the cell busy for a translation being created; false when it already is. */
	const start = ( sameTab: boolean ): boolean => {
		if ( blocked ) {
			return false;
		}

		screen.setBusy( key, sameTab ? 'translating' : 'creating' );
		speak(
			sprintf(
				/* translators: 1: language name, 2: post title. */
				__( 'Creating the %1$s translation of “%2$s”.', 'gds-content-translation' ),
				name,
				item.title
			)
		);

		return true;
	};

	const create = ( sameTab: boolean ) => ( event: React.MouseEvent< HTMLAnchorElement > ) => {
		if ( ! start( sameTab ) ) {
			event.preventDefault();
		}
	};

	const createUrl = ( mode: 'ai' | 'copy' ) =>
		addQueryArgs( settings.createUrl, { gds_ct_post_type: screen.postType, source_id: item.sourceId, lang: slug, mode } );

	/**
	 * AI translate runs on the server in the background, the way the bulk
	 * action does, and the cell follows it: Queued, Translating…, then the
	 * translation with its AI review. The link stays a plain link for
	 * opening in another tab.
	 */
	const translateHere = () => {
		if ( blocked ) {
			return;
		}

		screen.setBusy( key, 'translating' );
		speak(
			sprintf(
				/* translators: 1: language name, 2: post title. */
				__( 'Translating “%2$s” into %1$s.', 'gds-content-translation' ),
				name,
				item.title
			)
		);

		screen
			.run( 'translate', [ item.sourceId ], { languages: [ slug ] } )
			.then( ( response ) => {
				if ( ! response.ok ) {
					notifyError( response.results[ 0 ]?.message || response.message );

					return;
				}

				// Queued until the rows say more, so the cell never flashes back.
				screen.patchRow( item.sourceId, ( row ) => ( {
					...row,
					languages: { ...row.languages, [ slug ]: { ...cell, job: { status: 'queued', error: '', queuedAt: Date.now() / 1000 } } },
				} ) );
				screen.followJobs();
				screen.refresh( true );
			} )
			.catch( () => undefined )
			.finally( () => screen.setBusy( key, null ) );
	};

	const aiProps = {
		href: createUrl( 'ai' ),
		onClick: ( event: React.MouseEvent< HTMLAnchorElement > ) => {
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0 ) {
				return;
			}

			event.preventDefault();
			translateHere();
		},
	};
	const copyProps = { href: createUrl( 'copy' ), target: '_blank', rel: 'noopener noreferrer', onClick: create( false ) };
	const copyTitle = __( 'Create a draft with the untranslated original text', 'gds-content-translation' );

	// Something is happening: one chip of the same size, whatever it says.
	if ( busy === 'creating' || busy === 'translating' || job?.status === 'running' || job?.status === 'queued' ) {
		let label: string = __( 'Translating…', 'gds-content-translation' );

		if ( busy === 'creating' ) {
			// A copy: nothing is translated.
			label = __( 'Creating…', 'gds-content-translation' );
		} else if ( job?.status === 'queued' ) {
			label = __( 'Queued', 'gds-content-translation' );
		}

		return (
			<span className="gds-ct__state gds-ct__state--working" role="status">
				<span className="gds-ct__spinner" aria-hidden="true" />
				{ label }
				<VisuallyHidden> { about }</VisuallyHidden>
			</span>
		);
	}

	return (
		<>
			{ trashed && (
				<span className="gds-ct__state gds-ct__state--trashed">
					{ __( 'In trash', 'gds-content-translation' ) }
				</span>
			) }
			{ job?.status === 'failed' && (
				<span className="gds-ct__state gds-ct__state--failed" title={ job.error }>
					{ __( 'Failed', 'gds-content-translation' ) }
					<VisuallyHidden>
						{ ' ' }
						{ sprintf(
							/* translators: %s: error message */
							__( 'AI translation failed: %s', 'gds-content-translation' ),
							job.error
						) }
					</VisuallyHidden>
				</span>
			) }
			{ ! trashed && ! job && <VisuallyHidden>{ __( 'Not translated', 'gds-content-translation' ) }</VisuallyHidden> }
			{ canChangeTrashed && (
				<button type="button" className="gds-ct__inline-action" onClick={ () => change( 'restore-translation' ) } disabled={ blocked }>
					{ __( 'Restore', 'gds-content-translation' ) }
					<VisuallyHidden> { about }</VisuallyHidden>
				</button>
			) }
			{ ! trashed && settings.machineTranslation && (
				<a className="gds-ct__create" { ...aiProps }>
					{ job?.status === 'failed' ? __( 'Retry', 'gds-content-translation' ) : __( 'AI translate', 'gds-content-translation' ) }
					<VisuallyHidden>
						{ ' ' }
						{ sprintf(
							/* translators: %s: e.g. the Swedish translation of "Etusivu" */
							__( 'Create %s with AI translation', 'gds-content-translation' ),
							about
						) }
					</VisuallyHidden>
				</a>
			) }
			{ ! trashed && ! settings.machineTranslation && settings.copyTranslation && (
				<a className="gds-ct__create" { ...copyProps } title={ copyTitle }>
					{ __( 'Copy', 'gds-content-translation' ) }
					<VisuallyHidden>
						{ ' ' }
						{ sprintf(
							/* translators: %s: e.g. the Swedish translation of "Etusivu" */
							__( 'Create %s as a copy of the original', 'gds-content-translation' ),
							about
						) }
					</VisuallyHidden>
				</a>
			) }
			{ ( trashed || job?.status === 'failed' || ( settings.machineTranslation && settings.copyTranslation ) ) && (
				<CellMenu about={ about }>
					{ ( close ) => (
						<>
							{ job?.status === 'failed' && (
								<MenuItem disabled info={ job.error || __( 'No reason was given.', 'gds-content-translation' ) }>
									{ __( 'Why AI translation failed', 'gds-content-translation' ) }
								</MenuItem>
							) }
							{ trashed && settings.machineTranslation && (
								<MenuItem onClick={ () => { close(); translateHere(); } }>
									{ __( 'AI translate again', 'gds-content-translation' ) }
								</MenuItem>
							) }
							{ settings.copyTranslation && (
								<MenuItem info={ copyTitle } onClick={ () => { close(); if ( start( false ) ) { go( copyProps.href, true ); } } }>
									{ __( 'Copy the original', 'gds-content-translation' ) }
								</MenuItem>
							) }
							{ canChangeTrashed && (
								<MenuItem
									isDestructive
									onClick={ () => {
										close();
										setConfirmDelete( true );
									} }
								>
									{ __( 'Delete permanently…', 'gds-content-translation' ) }
								</MenuItem>
							) }
						</>
					) }
				</CellMenu>
			) }
			{ confirmDelete && (
				<ConfirmDialog
					confirmButtonText={ __( 'Delete permanently', 'gds-content-translation' ) }
					onConfirm={ () => {
						setConfirmDelete( false );
						change( 'delete-translation' );
					} }
					onCancel={ () => setConfirmDelete( false ) }
				>
					{ sprintf(
						/* translators: 1: post title, 2: language name. */
						__( 'Permanently delete the %2$s translation of “%1$s”? This cannot be undone.', 'gds-content-translation' ),
						item.title,
						name
					) }
				</ConfirmDialog>
			) }
		</>
	);
}
