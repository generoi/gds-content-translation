/**
 * The check of a translation's page in its cell: a badge while it runs and
 * once it found something ("Page: 3 in Finnish"), opening the list in a
 * modal, which the cell's menu opens too.
 */
import { Modal, VisuallyHidden } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { defaultLanguage } from '../settings';
import type { CellScan } from '../types';
import { ScanList } from './scan-list';
import type { ScanData } from './types';

export function scanMenuLabel(): string {
	/* translators: %s: language name of the original, e.g. Finnish */
	return sprintf( __( 'Check the page for %s…', 'gds-content-translation' ), defaultLanguage()?.name ?? '' );
}

export function ScanModal( { postId, about, onChange, onClose }: { postId: number; about: string; onChange: ( data: ScanData ) => void; onClose: () => void } ) {
	return (
		<Modal
			title={
				/* translators: %s: language name of the original, e.g. Finnish */
				sprintf( __( 'What the page still shows in %s', 'gds-content-translation' ), defaultLanguage()?.name ?? '' )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<p className="gds-ct-media__intro">{ about }</p>
			<ScanList postId={ postId } onChange={ onChange } />
		</Modal>
	);
}

export function ScanBadge( { scan, about, onOpen }: { scan?: CellScan; about: string; onOpen: () => void } ) {
	const source = defaultLanguage()?.name ?? '';

	if ( ! scan ) {
		return null;
	}

	if ( scan.status === 'queued' || scan.status === 'running' ) {
		return (
			<button type="button" className="gds-ct__audit gds-ct__audit--pending" onClick={ onOpen }>
				<span className="gds-ct__spinner" aria-hidden="true" />
				<span aria-hidden="true">{ __( 'Checking page…', 'gds-content-translation' ) }</span>
				<VisuallyHidden>
					{ sprintf(
						/* translators: %s: e.g. the Swedish translation of "Etusivu" */
						__( 'Checking the page of %s', 'gds-content-translation' ),
						about
					) }
				</VisuallyHidden>
			</button>
		);
	}

	if ( scan.status !== 'done' || ! scan.found ) {
		return null;
	}

	const label = sprintf(
		/* translators: 1: number of texts, 2: language name of the original, e.g. Finnish */
		_n( 'Page: %1$d text in %2$s', 'Page: %1$d texts in %2$s', scan.found, 'gds-content-translation' ),
		scan.found,
		source
	);
	const title = sprintf(
		/* translators: 1: number of texts, 2: language name of the original, e.g. Finnish */
		_n( 'The page check found %1$d text in %2$s or another language', 'The page check found %1$d texts in %2$s or another language', scan.found, 'gds-content-translation' ),
		scan.found,
		source
	);

	return (
		<button type="button" className="gds-ct__audit gds-ct__audit--media" title={ title } onClick={ onOpen }>
			<span aria-hidden="true">{ label }</span>
			<VisuallyHidden>{ `${ title }: ${ about }` }</VisuallyHidden>
		</button>
	);
}
