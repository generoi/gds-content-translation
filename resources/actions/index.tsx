/**
 * The row and bulk actions: AI translation, AI review, the page check,
 * proof read on and off, and Trash all. Each asks first in a modal that says how many
 * translations it touches, and runs through POST /actions/{id}, which checks
 * every post again.
 */
import { Button, CheckboxControl, Flex, Notice, RadioControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { Action, RenderModalProps } from '../dataviews';
import type { ScreenContextValue } from '../list/screen-context';

/** What the actions need from the screen; all stable, so the actions are built once. */
export type ActionOps = Pick< ScreenContextValue, 'run' | 'refresh' | 'followJobs' >;
import { defaultLanguage, getSettings, translationLanguages } from '../settings';
import type { Followup, Settings } from '../settings';
import type { Cell, Row } from '../types';
import { notifySuccess } from '../ui/notify';
import { FollowupRadios, GlossaryNote, InstructionsField } from '../ui/followup';
import { costLines } from '../scan/cost';
import type { CostFigures } from '../scan/cost';

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
	const settings = getSettings();
	const languages = translationLanguages();
	const all = languages.map( ( language ) => language.slug );
	const [ choice, setChoice ] = useState( 'all' );
	const [ confirmed, setConfirmed ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ followup, setFollowup ] = useState< Followup >( settings.followup );
	const [ instructions, setInstructions ] = useState( '' );
	const asksFollowup = mode === 'translate' && settings.review;
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
		// Only the languages the action does something in.
		...languages.filter( ( language ) => count( mode, items, [ language.slug ] ) > 0 ).map( ( language ) => ( {
			value: language.slug,
			/* translators: 1: language name, 2: number of translations */
			label: sprintf( __( '%1$s (%2$d)', 'gds-content-translation' ), language.name, count( mode, items, [ language.slug ] ) ),
		} ) ),
	];

	const descriptions: Record< LanguageMode, string > = {
		translate: settings.publish
			? __( 'Machine-translates the originals into the languages they are missing. A translation is published when its original is, and reviewed by AI. They are created in the background, one at a time: you can keep working.', 'gds-content-translation' )
			: __( 'Machine-translates the originals into the languages they are missing, as drafts, and reviews them by AI. They are created in the background, one at a time: you can keep working.', 'gds-content-translation' ),
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
				...( asksFollowup ? { followup } : {} ),
				...( asksFollowup && instructions.trim() ? { instructions: instructions.trim() } : {} ),
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
			{ options.length > 2 && (
				<RadioControl label={ __( 'Languages', 'gds-content-translation' ) } selected={ choice } options={ options } onChange={ ( value: string ) => setChoice( value ) } />
			) }
			{ asksFollowup && (
				<FollowupRadios selected={ followup } onChange={ setFollowup } />
			) }
			{ asksFollowup && <InstructionsField value={ instructions } onChange={ setInstructions } /> }
			{ mode === 'translate' && <GlossaryNote slugs={ slugs } /> }
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

/** Rows per estimate request: each of their pages is fetched while the request waits. */
const SCAN_ESTIMATE_CHUNK = 5;

function canScan( row: Row, slug: string ): boolean {
	const cell = row.languages[ slug ];

	return cell?.state === 'translated' && !! cell.viewUrl && cell.scan?.status !== 'queued' && cell.scan?.status !== 'running';
}

interface ScanTotals extends CostFigures {
	pages: number;
	failed: number;
	errors: string[];
}

/**
 * Check the pages of the selected translations. Their pages are read first,
 * a few rows at a time, for the total of what the check costs; nothing goes
 * to Claude before the editor says yes. The checks then run in the
 * background, one page at a time.
 */
function PageScanModal( { items, closeModal, onActionPerformed, screen }: RenderModalProps< Row > & { screen: ActionOps } ) {
	const languages = translationLanguages();
	const source = defaultLanguage()?.name ?? '';
	const [ choice, setChoice ] = useState( 'all' );
	const [ totals, setTotals ] = useState< ScanTotals | null >( null );
	const [ progress, setProgress ] = useState( 0 );
	const [ isBusy, setIsBusy ] = useState( false );
	const slugs = choice === 'all' ? languages.map( ( language ) => language.slug ) : [ choice ];
	const rows = items.filter( ( row ) => slugs.some( ( slug ) => canScan( row, slug ) ) );
	const pages = rows.reduce( ( sum, row ) => sum + slugs.filter( ( slug ) => canScan( row, slug ) ).length, 0 );
	const rowKey = rows.map( ( row ) => row.sourceId ).join( ',' );

	// Read the pages for the estimate, one request at a time, again when the languages change.
	useEffect( () => {
		let current = true;
		const sum: ScanTotals = { pages: 0, texts: 0, characters: 0, tokens: 0, outputTokens: 0, price: 0, failed: 0, errors: [] };

		setTotals( null );
		setProgress( 0 );

		void ( async () => {
			for ( let start = 0; start < rows.length && current; start += SCAN_ESTIMATE_CHUNK ) {
				const chunk = rows.slice( start, start + SCAN_ESTIMATE_CHUNK );

				try {
					const response = await screen.run( 'page-scan', chunk.map( ( row ) => row.sourceId ), { languages: slugs, estimate: true } );

					for ( const result of response.results ) {
						if ( result.ok ) {
							for ( const key of [ 'pages', 'texts', 'characters', 'tokens', 'outputTokens', 'price', 'failed' ] as const ) {
								sum[ key ] += Number( result[ key ] ?? 0 );
							}

							sum.modelLabel = typeof result.modelLabel === 'string' ? result.modelLabel : sum.modelLabel;
							sum.errors.push( ...( Array.isArray( result.errors ) ? result.errors.map( String ) : [] ) );
						}
					}
				} catch {
					return;
				}

				if ( current ) {
					setProgress( Math.min( rows.length, start + SCAN_ESTIMATE_CHUNK ) );
				}
			}

			if ( current ) {
				setTotals( { ...sum, errors: [ ...new Set( sum.errors ) ] } );
			}
		} )();

		return () => {
			current = false;
		};
		// The rows and languages, by value: the selection's array is new on each render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ choice, rowKey ] );

	const options = [
		/* translators: %d: number of translations */
		{ value: 'all', label: sprintf( __( 'All languages (%d)', 'gds-content-translation' ), items.reduce( ( sum, row ) => sum + languages.filter( ( language ) => canScan( row, language.slug ) ).length, 0 ) ) },
		...languages.filter( ( language ) => items.some( ( row ) => canScan( row, language.slug ) ) ).map( ( language ) => ( {
			value: language.slug,
			/* translators: 1: language name, 2: number of translations */
			label: sprintf( __( '%1$s (%2$d)', 'gds-content-translation' ), language.name, items.filter( ( row ) => canScan( row, language.slug ) ).length ),
		} ) ),
	];

	const submit = () => {
		setIsBusy( true );
		screen
			.run( 'page-scan', rows.map( ( row ) => row.sourceId ), { languages: slugs } )
			.then( ( response ) => {
				if ( response.results.some( ( result ) => result.ok ) ) {
					notifySuccess( response.message );
				}

				screen.refresh( true );
				onActionPerformed?.( items );
				closeModal?.();
			} )
			.catch( () => setIsBusy( false ) );
	};

	return (
		<div className="gds-ct__modal">
			<p>
				{ sprintf(
					/* translators: %s: language name of the original, e.g. Finnish */
					__( 'Reads each translation’s page as a visitor gets it, the theme’s header and footer, menus and image alt texts included, and has AI list what is still in %s or another language. The pages are checked in the background, one at a time.', 'gds-content-translation' ),
					source
				) }
			</p>
			{ options.length > 2 && (
				<RadioControl label={ __( 'Languages', 'gds-content-translation' ) } selected={ choice } options={ options } onChange={ ( value: string ) => setChoice( value ) } />
			) }
			{ pages > 0 && ! totals && (
				<p className="gds-ct-media__job">
					<Spinner />
					{ sprintf(
						/* translators: 1: rows read, 2: all rows */
						__( 'Reading the pages for the estimate: %1$d of %2$d…', 'gds-content-translation' ),
						progress,
						rows.length
					) }
				</p>
			) }
			{ totals && totals.pages > 0 && (
				<Notice status="info" isDismissible={ false }>
					{ costLines( totals, totals.pages ).map( ( line ) => (
						<p key={ line }>{ line }</p>
					) ) }
				</Notice>
			) }
			{ totals && totals.failed > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: 1: number of pages, 2: error message */
							_n( '%1$d page could not be read: %2$s', '%1$d pages could not be read: %2$s', totals.failed, 'gds-content-translation' ),
							totals.failed,
							totals.errors.join( ' ' )
						) }
					</p>
				</Notice>
			) }
			{ pages === 0 && <p className="gds-ct__modal-empty">{ __( 'Nothing to check for the selected rows in these languages.', 'gds-content-translation' ) }</p> }
			<Flex justify="flex-end" className="gds-ct__modal-buttons">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isBusy }>
					{ __( 'Cancel', 'gds-content-translation' ) }
				</Button>
				<Button variant="primary" onClick={ submit } isBusy={ isBusy } disabled={ isBusy || ! totals || totals.pages === 0 } accessibleWhenDisabled>
					{ sprintf(
						/* translators: %d: number of pages */
						_n( 'Check %d page', 'Check %d pages', totals?.pages ?? pages, 'gds-content-translation' ),
						totals?.pages ?? pages
					) }
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

	if ( settings.review ) {
		actions.push( {
			id: 'page-scan',
			/* translators: %s: language name of the original, e.g. Finnish */
			label: sprintf( __( 'Check the pages for %s…', 'gds-content-translation' ), defaultLanguage( settings )?.name ?? '' ),
			/* translators: %s: language name of the original, e.g. Finnish */
			modalHeader: sprintf( __( 'Check the pages for %s', 'gds-content-translation' ), defaultLanguage( settings )?.name ?? '' ),
			supportsBulk: true,
			isEligible: ( row ) => slugs.some( ( slug ) => canScan( row, slug ) ),
			RenderModal: ( props ) => <PageScanModal { ...props } screen={ screen } />,
		} );
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
