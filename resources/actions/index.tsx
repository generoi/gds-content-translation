/**
 * The row and bulk actions: AI translation, AI review, proof read on and
 * off, and Trash all. Each asks first in a modal that says how many
 * translations it touches, and runs through POST /actions/{id}, which checks
 * every post again.
 */
import { Button, CheckboxControl, Flex, Notice, RadioControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { Action, RenderModalProps } from '../dataviews';
import type { ScreenContextValue } from '../list/screen-context';

/** What the actions need from the screen; all stable, so the actions are built once. */
export type ActionOps = Pick< ScreenContextValue, 'run' | 'refresh' | 'followJobs' >;
import { translationLanguages } from '../settings';
import type { Settings } from '../settings';
import type { Cell, Row } from '../types';
import { notifySuccess } from '../ui/notify';

/** Above this many new translations, the modal asks for an explicit yes. */
export const CONFIRM_TRANSLATE_OVER = 10;

type LanguageMode = 'translate' | 'review' | 'proofread' | 'unproofread';

function isActiveJob( cell: Cell | undefined ): boolean {
	return !! cell?.job && ( cell.job.status === 'queued' || cell.job.status === 'running' );
}

/** Whether a row's cell in a language is something the action can do. */
export function applies( mode: LanguageMode, row: Row, slug: string ): boolean {
	const cell = row.languages[ slug ];

	switch ( mode ) {
		case 'translate':
			return ( ! cell || cell.state !== 'translated' ) && ! isActiveJob( cell );
		case 'review':
			return cell?.state === 'translated' && cell.audit?.status !== 'pending';
		case 'proofread':
			return cell?.state === 'translated' && ! cell.proofread;
		case 'unproofread':
			return cell?.state === 'translated' && !! cell.proofread;
	}
}

function count( mode: LanguageMode, rows: Row[], slugs: string[] ): number {
	return rows.reduce( ( sum, row ) => sum + slugs.filter( ( slug ) => applies( mode, row, slug ) ).length, 0 );
}

const ACTION_IDS: Record< LanguageMode, string > = {
	translate: 'translate',
	review: 'review',
	proofread: 'proofread',
	unproofread: 'proofread',
};

function LanguageModal( { items, closeModal, onActionPerformed, mode, screen }: RenderModalProps< Row > & { mode: LanguageMode; screen: ActionOps } ) {
	const languages = translationLanguages();
	const all = languages.map( ( language ) => language.slug );
	const [ choice, setChoice ] = useState( 'all' );
	const [ confirmed, setConfirmed ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const slugs = choice === 'all' ? all : [ choice ];
	const total = count( mode, items, slugs );
	const rows = items.filter( ( row ) => slugs.some( ( slug ) => applies( mode, row, slug ) ) );
	const needsConfirm = mode === 'translate' && total > CONFIRM_TRANSLATE_OVER;

	const options = [
		{
			value: 'all',
			label: sprintf(
				/* translators: %d: number of translations */
				mode === 'translate' ? __( 'All missing languages (%d)', 'gds-content-translation' ) : __( 'All languages (%d)', 'gds-content-translation' ),
				count( mode, items, all )
			),
		},
		...languages.map( ( language ) => ( {
			value: language.slug,
			/* translators: 1: language name, 2: number of translations */
			label: sprintf( __( '%1$s (%2$d)', 'gds-content-translation' ), language.name, count( mode, items, [ language.slug ] ) ),
		} ) ),
	];

	const descriptions: Record< LanguageMode, string > = {
		translate: __( 'Machine-translates the originals into the languages they are missing, as drafts. They are created in the background, one at a time: you can keep working, and the progress shows at the bottom of the screen.', 'gds-content-translation' ),
		review: __( 'AI compares each translation’s content with the original, in the background. Open remarks show in the table and on each review page.', 'gds-content-translation' ),
		proofread: __( 'Marks the translations as proofread.', 'gds-content-translation' ),
		unproofread: __( 'Marks the translations as not proofread.', 'gds-content-translation' ),
	};

	const submitLabels: Record< LanguageMode, ( n: number ) => string > = {
		/* translators: %d: number of translations */
		translate: ( n ) => sprintf( _n( 'AI translate %d', 'AI translate %d', n, 'gds-content-translation' ), n ),
		/* translators: %d: number of translations */
		review: ( n ) => sprintf( _n( 'Review %d translation', 'Review %d translations', n, 'gds-content-translation' ), n ),
		/* translators: %d: number of translations */
		proofread: ( n ) => sprintf( _n( 'Mark %d proofread', 'Mark %d proofread', n, 'gds-content-translation' ), n ),
		/* translators: %d: number of translations */
		unproofread: ( n ) => sprintf( _n( 'Mark %d not proofread', 'Mark %d not proofread', n, 'gds-content-translation' ), n ),
	};

	const submit = () => {
		setIsBusy( true );
		screen
			.run( ACTION_IDS[ mode ], rows.map( ( row ) => row.sourceId ), {
				languages: slugs,
				...( mode === 'proofread' || mode === 'unproofread' ? { proofread: mode === 'proofread' } : {} ),
			} )
			.then( ( response ) => {
				if ( response.results.some( ( result ) => result.ok ) ) {
					notifySuccess( response.message );
				}

				if ( mode === 'translate' ) {
					screen.followJobs();
				}

				screen.refresh( true );
				onActionPerformed?.( items );
				closeModal?.();
			} )
			.catch( () => setIsBusy( false ) );
	};

	return (
		<div className="gds-ct__modal">
			<p>{ descriptions[ mode ] }</p>
			{ languages.length > 1 && (
				<RadioControl label={ __( 'Languages', 'gds-content-translation' ) } selected={ choice } options={ options } onChange={ ( value: string ) => setChoice( value ) } />
			) }
			{ needsConfirm && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: %d: number of translations */
							__( 'This creates %d translations with the machine translation service, which counts against its quota, and an AI review of each when the review is on.', 'gds-content-translation' ),
							total
						) }
					</p>
					<CheckboxControl
						label={ sprintf(
							/* translators: %d: number of translations */
							__( 'Yes, create %d translations', 'gds-content-translation' ),
							total
						) }
						checked={ confirmed }
						onChange={ setConfirmed }
					/>
				</Notice>
			) }
			{ total === 0 && <p className="gds-ct__modal-empty">{ __( 'Nothing to do for the selected rows in these languages.', 'gds-content-translation' ) }</p> }
			<Flex justify="flex-end" className="gds-ct__modal-buttons">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isBusy }>
					{ __( 'Cancel', 'gds-content-translation' ) }
				</Button>
				<Button variant="primary" onClick={ submit } isBusy={ isBusy } disabled={ isBusy || total === 0 || ( needsConfirm && ! confirmed ) } accessibleWhenDisabled>
					{ submitLabels[ mode ]( total ) }
				</Button>
			</Flex>
		</div>
	);
}

function TrashModal( { items, closeModal, onActionPerformed, screen }: RenderModalProps< Row > & { screen: ActionOps } ) {
	const [ isBusy, setIsBusy ] = useState( false );
	const translations = items.reduce( ( sum, row ) => sum + row.translationCount, 0 );
	const first = items[ 0 ];

	let question: string;

	if ( items.length === 1 && first ) {
		question =
			translations > 0
				? /* translators: 1: post title, 2: number of translations. */
				  sprintf( _n( 'Move “%1$s” and its %2$d translation to the trash?', 'Move “%1$s” and its %2$d translations to the trash?', translations, 'gds-content-translation' ), first.title, translations )
				: /* translators: %s: post title. */
				  sprintf( __( 'Move “%s” to the trash?', 'gds-content-translation' ), first.title );
	} else {
		question =
			translations > 0
				? /* translators: 1: number of posts, 2: number of translations. */
				  sprintf( __( 'Move %1$d posts and their %2$d translations to the trash?', 'gds-content-translation' ), items.length, translations )
				: /* translators: %d: number of posts. */
				  sprintf( __( 'Move %d posts to the trash?', 'gds-content-translation' ), items.length );
	}

	const submit = () => {
		setIsBusy( true );
		screen
			.run( 'trash', items.map( ( row ) => row.sourceId ) )
			.then( ( response ) => {
				// Undo restores exactly what was trashed, a partly trashed row included.
				const posts: Record< number, number[] > = {};

				for ( const result of response.results ) {
					if ( Array.isArray( result.changed ) && result.changed.length ) {
						posts[ result.id ] = result.changed;
					}
				}

				if ( Object.keys( posts ).length ) {
					notifySuccess( response.message, [
						{
							label: __( 'Undo', 'gds-content-translation' ),
							onClick: () => {
								screen.run( 'restore', Object.keys( posts ).map( Number ), { posts } ).then( ( undone ) => {
									if ( undone.results.some( ( result ) => result.ok ) ) {
										notifySuccess( undone.message );
									}

									screen.refresh( true );
								} );
							},
						},
					] );
				}

				screen.refresh( true );
				onActionPerformed?.( items );
				closeModal?.();
			} )
			.catch( () => setIsBusy( false ) );
	};

	return (
		<div className="gds-ct__modal">
			<p>{ question }</p>
			<p>{ __( 'Nothing is deleted permanently. Posts already in the trash are left as they are.', 'gds-content-translation' ) }</p>
			<Flex justify="flex-end" className="gds-ct__modal-buttons">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isBusy }>
					{ __( 'Cancel', 'gds-content-translation' ) }
				</Button>
				<Button variant="primary" isDestructive onClick={ submit } isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled>
					{ __( 'Move to trash', 'gds-content-translation' ) }
				</Button>
			</Flex>
		</div>
	);
}

export function buildActions( settings: Settings, screen: ActionOps ): Action< Row >[] {
	const slugs = translationLanguages( settings ).map( ( language ) => language.slug );
	const any = ( mode: LanguageMode ) => ( row: Row ) => slugs.some( ( slug ) => applies( mode, row, slug ) );
	const languageAction = ( mode: LanguageMode, id: string, label: string, header: string ): Action< Row > => ( {
		id,
		label,
		modalHeader: header,
		supportsBulk: true,
		isEligible: any( mode ),
		RenderModal: ( props ) => <LanguageModal { ...props } mode={ mode } screen={ screen } />,
	} );

	// View is here rather than under every title.
	const actions: Action< Row >[] = [
		{
			id: 'view-original',
			label: __( 'View original', 'gds-content-translation' ),
			isEligible: ( row ) => !! row.viewUrl,
			callback: ( items ) => {
				const url = items[ 0 ]?.viewUrl;

				if ( url ) {
					window.open( url, '_blank', 'noopener' );
				}
			},
		},
	];

	if ( settings.machineTranslation ) {
		actions.push( languageAction( 'translate', 'ai-translate', __( 'AI translate…', 'gds-content-translation' ), __( 'AI translate', 'gds-content-translation' ) ) );
	}

	if ( settings.review ) {
		actions.push( languageAction( 'review', 'ai-review', __( 'Run AI review…', 'gds-content-translation' ), __( 'Run AI review', 'gds-content-translation' ) ) );
	}

	actions.push(
		languageAction( 'proofread', 'proofread', __( 'Mark proofread…', 'gds-content-translation' ), __( 'Mark proofread', 'gds-content-translation' ) ),
		languageAction( 'unproofread', 'unproofread', __( 'Mark not proofread…', 'gds-content-translation' ), __( 'Mark not proofread', 'gds-content-translation' ) ),
		{
			id: 'trash-all',
			label: __( 'Move to trash…', 'gds-content-translation' ),
			modalHeader: __( 'Move to trash', 'gds-content-translation' ),
			supportsBulk: true,
			isEligible: ( row ) => row.canTrashAll,
			RenderModal: ( props ) => <TrashModal { ...props } screen={ screen } />,
		}
	);

	return actions;
}
