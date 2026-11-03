/**
 * The check of a translation's page for text still in the original's
 * language: the last result, where each text is on the page and where it
 * likely comes from, with a link to fix it there, and the button that checks
 * the page again after saying what it costs.
 *
 * The check runs in the background; the list asks how far it is until it is
 * done. The status screen shows it in a modal, the AI review page as a
 * section above the remarks.
 */
import { Button, ExternalLink, Spinner, __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import { notifyError, notifySuccess } from '../ui/notify';
import { estimateScan, fetchScan, queueScan } from './api';
import { costLines } from './cost';
import type { PageEstimate, ScanData, ScanFinding, ScanRegion } from './types';

/** Around the content first, as an editor reads the page. */
const REGIONS: ScanRegion[] = [ 'header', 'nav', 'main', 'aside', 'footer', 'head', 'body' ];

export function regionLabel( region: ScanRegion ): string {
	return {
		head: __( 'In the page’s title, description or sharing tags', 'gds-content-translation' ),
		header: __( 'In the header', 'gds-content-translation' ),
		nav: __( 'In a menu', 'gds-content-translation' ),
		main: __( 'In the content', 'gds-content-translation' ),
		aside: __( 'In a sidebar', 'gds-content-translation' ),
		footer: __( 'In the footer', 'gds-content-translation' ),
		body: __( 'Elsewhere on the page', 'gds-content-translation' ),
	}[ region ];
}

/** What the text is, when it is not plain text on the page. */
function kindLabel( kind: string ): string {
	const labels: Record< string, string > = {
		alt: __( 'image alt text', 'gds-content-translation' ),
		title: __( 'tooltip', 'gds-content-translation' ),
		'aria-label': __( 'read out by screen readers', 'gds-content-translation' ),
		'aria-description': __( 'read out by screen readers', 'gds-content-translation' ),
		placeholder: __( 'placeholder of a field', 'gds-content-translation' ),
		value: __( 'button', 'gds-content-translation' ),
		meta: __( 'search results and shared links', 'gds-content-translation' ),
		jsonld: __( 'structured data for search engines', 'gds-content-translation' ),
		svg: __( 'text in an illustration', 'gds-content-translation' ),
	};

	return labels[ kind ] ?? '';
}

function fixLabel( type: ScanFinding[ 'source' ][ 'type' ] ): string {
	switch ( type ) {
		case 'content':
			return __( 'Edit the translation', 'gds-content-translation' );
		case 'media':
			return __( 'Edit in the media library', 'gds-content-translation' );
		case 'string':
			return __( 'Translate in Polylang strings', 'gds-content-translation' );
		case 'menu':
			return __( 'Edit the menu', 'gds-content-translation' );
		case 'pattern':
			return __( 'Edit the pattern', 'gds-content-translation' );
		default:
			return __( 'Edit', 'gds-content-translation' );
	}
}

function Finding( { finding }: { finding: ScanFinding } ) {
	const kinds = finding.kinds.map( kindLabel ).filter( Boolean );

	return (
		<li className="gds-ct-media__item gds-ct-scan__item">
			<div className="gds-ct-media__body">
				<p className="gds-ct-media__title">
					<span className="gds-ct-media__kind">{ finding.language }</span>
					<span>“{ finding.text }”</span>
				</p>
				{ !! kinds.length && <p className="gds-ct-media__hint">{ [ ...new Set( kinds ) ].join( ', ' ) }</p> }
				<p className="gds-ct-media__hint">
					{ finding.source.label }
					{ finding.source.url && (
						<>
							{ ' ' }
							<ExternalLink href={ finding.source.url }>{ fixLabel( finding.source.type ) }</ExternalLink>
						</>
					) }
				</p>
				{ finding.suggestion && (
					<p className="gds-ct-media__hint">
						{
							/* translators: %s: suggested translation */
							sprintf( __( 'Suggestion: %s', 'gds-content-translation' ), finding.suggestion )
						}
					</p>
				) }
			</div>
		</li>
	);
}

function Findings( { data }: { data: ScanData } ) {
	const result = data.result;

	if ( ! result ) {
		return null;
	}

	if ( result.status === 'failed' ) {
		return (
			<p className="gds-ct-media__job is-error">
				{
					/* translators: %s: error message */
					sprintf( __( 'The last check failed: %s', 'gds-content-translation' ), result.error )
				}
			</p>
		);
	}

	const when = new Date( result.createdAt * 1000 ).toLocaleString();

	return (
		<>
			<p className="gds-ct-media__note">
				{ sprintf(
					/* translators: 1: date and time, 2: number of texts, 3: model name */
					_n( 'Checked %1$s: %2$d text on the page, by %3$s.', 'Checked %1$s: %2$d texts on the page, by %3$s.', result.texts, 'gds-content-translation' ),
					when,
					result.texts,
					result.model
				) }{ ' ' }
				{ result.preview && __( 'The translation was not published, so its preview was checked.', 'gds-content-translation' ) }
			</p>
			{ result.changed && <p className="gds-ct-media__job is-error">{ __( 'The page has changed since this check. Check it again to see what is left.', 'gds-content-translation' ) }</p> }
			{ result.findings.length ? (
				REGIONS.filter( ( region ) => result.findings.some( ( finding ) => finding.where[ 0 ] === region ) ).map( ( region ) => (
					<div key={ region } className="gds-ct-scan__region">
						<h3 className="gds-ct-scan__heading">{ regionLabel( region ) }</h3>
						<ul className="gds-ct-media__list">
							{ result.findings
								.filter( ( finding ) => finding.where[ 0 ] === region )
								.map( ( finding ) => (
									<Finding key={ finding.id } finding={ finding } />
								) ) }
						</ul>
					</div>
				) )
			) : (
				<p className="gds-ct-media__empty">
					{
						/* translators: %s: language name of the original, e.g. Finnish */
						sprintf( __( 'Nothing in %s or another language was found on the page.', 'gds-content-translation' ), data.sourceLanguage )
					}
				</p>
			) }
		</>
	);
}

interface Props {
	postId: number;
	/** After a check is queued or done: the cell's badge changes. */
	onChange?: ( data: ScanData ) => void;
	/** Once the last result is loaded. */
	onLoad?: ( data: ScanData ) => void;
}

export function ScanList( { postId, onChange, onLoad }: Props ) {
	const [ data, setData ] = useState< ScanData | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const [ estimating, setEstimating ] = useState( false );
	const [ estimate, setEstimate ] = useState< PageEstimate | null >( null );
	const [ retry, setRetry ] = useState( 0 );

	useEffect( () => {
		let current = true;

		fetchScan( postId )
			.then( ( loaded ) => {
				if ( current ) {
					setData( loaded );
					onLoad?.( loaded );
				}
			} )
			.catch( ( caught: unknown ) => current && setFailed( errorMessage( caught, __( 'Could not load. Reload the page.', 'gds-content-translation' ) ) ) );

		return () => {
			current = false;
		};
		// Loaded once per post; a new onLoad does not load it again.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ postId ] );

	const working = data?.status === 'queued' || data?.status === 'running';

	// The parent's handler of the moment: a new one each render must not
	// restart the timer below (the status screen renders every few seconds).
	const onChangeRef = useRef( onChange );
	useEffect( () => {
		onChangeRef.current = onChange;
	}, [ onChange ] );

	// While the check runs in the background, ask how far it is; after a
	// failed ask, again a little later, and after a few, say so and stop.
	useEffect( () => {
		if ( ! working || retry >= 5 ) {
			return;
		}

		const timer = setTimeout( () => {
			fetchScan( postId )
				.then( ( next ) => {
					setRetry( 0 );
					setData( next );

					if ( next.status !== 'queued' && next.status !== 'running' ) {
						onChangeRef.current?.( next );
					}
				} )
				.catch( ( caught: unknown ) => {
					if ( retry + 1 >= 5 ) {
						notifyError( errorMessage( caught, __( 'Could not ask how far the check is. Reload the page.', 'gds-content-translation' ) ) );
					}

					setRetry( retry + 1 );
				} );
		}, 4000 * ( retry + 1 ) );

		return () => clearTimeout( timer );
	}, [ working, data, postId, retry ] );

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

	const askEstimate = () => {
		setEstimating( true );
		estimateScan( postId )
			.then( setEstimate )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'The page could not be read. Try again.', 'gds-content-translation' ) ) ) )
			.finally( () => setEstimating( false ) );
	};

	const run = () => {
		setEstimate( null );
		queueScan( postId )
			.then( ( next ) => {
				setData( next );
				onChange?.( next );
				notifySuccess( __( 'The page is being checked. It takes up to a minute; you can leave this.', 'gds-content-translation' ) );
			} )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'The check could not be started. Try again.', 'gds-content-translation' ) ) ) );
	};

	const label = data.result ? __( 'Check again…', 'gds-content-translation' ) : sprintf(
		/* translators: %s: language name of the original, e.g. Finnish */
		__( 'Check the page for %s…', 'gds-content-translation' ),
		data.sourceLanguage
	);

	return (
		<div className="gds-ct-media gds-ct-scan">
			{ ! data.result && ! working && (
				<p className="gds-ct-media__intro">
					{ sprintf(
						/* translators: %s: language name of the original, e.g. Finnish */
						__( 'Reads the page as a visitor gets it, the theme’s header and footer, menus, image alt texts and the title included, and has AI list what is still in %s or another language. Run it when you think the translation is done.', 'gds-content-translation' ),
						data.sourceLanguage
					) }
				</p>
			) }
			{ working && (
				<p className="gds-ct-media__job">
					<Spinner />
					{ data.status === 'queued' ? __( 'Waiting to check the page…', 'gds-content-translation' ) : __( 'Checking the page…', 'gds-content-translation' ) }
				</p>
			) }
			{ data.status === 'stuck' && <p className="gds-ct-media__job is-error">{ __( 'The check did not finish. Try again.', 'gds-content-translation' ) }</p> }
			<Findings data={ data } />
			<p className="gds-ct-media__note">{ __( 'Texts that a script adds after the page loads are not seen.', 'gds-content-translation' ) }</p>
			{ data.available && (
				<div className="gds-ct-media__actions">
					<Button variant={ data.result ? 'secondary' : 'primary' } size="compact" isBusy={ estimating } disabled={ estimating || working } accessibleWhenDisabled onClick={ askEstimate }>
						{ estimating ? __( 'Reading the page…', 'gds-content-translation' ) : label }
					</Button>
				</div>
			) }
			{ estimate && (
				<ConfirmDialog confirmButtonText={ __( 'Check the page', 'gds-content-translation' ) } onConfirm={ run } onCancel={ () => setEstimate( null ) }>
					<p>
						<strong>
							{
								/* translators: %s: language name of the original, e.g. Finnish */
								sprintf( __( 'Check the page for %s?', 'gds-content-translation' ), data.sourceLanguage )
							}
						</strong>
					</p>
					{ costLines( estimate ).map( ( line ) => (
						<p key={ line }>{ line }</p>
					) ) }
					{ estimate.preview && <p>{ __( 'The translation is not published: its preview is checked, as you see it.', 'gds-content-translation' ) }</p> }
					{ estimate.unchanged && <p>{ __( 'The page has the same texts as at the last check, so this check would most likely find the same.', 'gds-content-translation' ) }</p> }
				</ConfirmDialog>
			) }
		</div>
	);
}
