/**
 * The bulk AI translation queue above the table: how far it is, which post
 * is being translated now, what failed and why, and Cancel for the jobs not
 * started yet. Once nothing is left to do it stays until dismissed, so the
 * failures can be read.
 */
import { Button, ProgressBar } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { languageName } from '../settings';
import type { Job } from '../types';
import { isActive } from './use-jobs';

export function BulkProgress( { jobs, onCancel, onDismiss }: { jobs: Job[]; onCancel: () => Promise< void >; onDismiss: () => Promise< void > } ) {
	const [ isBusy, setIsBusy ] = useState( false );

	if ( ! jobs.length ) {
		return null;
	}

	const done = jobs.filter( ( job ) => job.status === 'done' || job.status === 'skipped' ).length;
	const failed = jobs.filter( ( job ) => job.status === 'failed' );
	const queued = jobs.filter( ( job ) => job.status === 'queued' ).length;
	const running = jobs.find( ( job ) => job.status === 'running' );
	const active = jobs.some( isActive );
	const finished = done + failed.length;

	const act = ( callback: () => Promise< void > ) => () => {
		setIsBusy( true );
		callback().finally( () => setIsBusy( false ) );
	};

	return (
		<section className={ `gds-ct__progress${ active ? ' is-active' : '' }` } aria-label={ __( 'AI translation queue', 'gds-content-translation' ) }>
			<p className="gds-ct__progress-status" role="status">
				<strong>
					{ active
						? sprintf(
								/* translators: 1: translations finished, 2: translations queued in all */
								__( 'AI translation: %1$d of %2$d done', 'gds-content-translation' ),
								finished,
								jobs.length
						  )
						: sprintf(
								/* translators: %d: number of translations created */
								_n( 'AI translation finished: %d created', 'AI translation finished: %d created', done, 'gds-content-translation' ),
								done
						  ) }
				</strong>
				{ failed.length > 0 && (
					<span className="gds-ct__progress-failed">
						{ ' · ' }
						{ sprintf(
							/* translators: %d: number of failed translations */
							_n( '%d failed', '%d failed', failed.length, 'gds-content-translation' ),
							failed.length
						) }
					</span>
				) }
				{ running && (
					<span className="gds-ct__progress-current">
						{ ' · ' }
						{ sprintf(
							/* translators: 1: post title, 2: language name */
							__( 'Translating “%1$s” into %2$s…', 'gds-content-translation' ),
							running.title,
							languageName( running.language )
						) }
					</span>
				) }
			</p>
			<ProgressBar className="gds-ct__progress-bar" value={ jobs.length ? Math.round( ( finished / jobs.length ) * 100 ) : 0 } />
			{ failed.length > 0 && (
				<details className="gds-ct__progress-errors" open={ ! active }>
					<summary>{ __( 'What failed', 'gds-content-translation' ) }</summary>
					<ul>
						{ failed.map( ( job ) => (
							<li key={ `${ job.sourceId }:${ job.language }` }>
								{ sprintf(
									/* translators: 1: post title, 2: language name, 3: error message */
									__( '“%1$s” (%2$s): %3$s', 'gds-content-translation' ),
									job.title,
									languageName( job.language ),
									job.error
								) }
							</li>
						) ) }
					</ul>
				</details>
			) }
			<p className="gds-ct__progress-buttons">
				{ queued > 0 && (
					<Button variant="secondary" size="compact" onClick={ act( onCancel ) } disabled={ isBusy } accessibleWhenDisabled>
						{ sprintf(
							/* translators: %d: number of queued translations */
							_n( 'Cancel %d queued', 'Cancel %d queued', queued, 'gds-content-translation' ),
							queued
						) }
					</Button>
				) }
				{ ! active && (
					<Button variant="tertiary" size="compact" onClick={ act( onDismiss ) } disabled={ isBusy } accessibleWhenDisabled>
						{ __( 'Dismiss', 'gds-content-translation' ) }
					</Button>
				) }
				{ active && <span className="description">{ __( 'Runs in the background: you can leave this screen.', 'gds-content-translation' ) }</span> }
			</p>
		</section>
	);
}
