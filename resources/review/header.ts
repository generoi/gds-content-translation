/**
 * The server-rendered parts of the page that count open remarks: the editor
 * button, the hint by the proofread checkbox and the re-run confirmation.
 * Kept in step as remarks are handled here.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { OpenCounts } from './types';

export function syncHeader( counts: OpenCounts ): void {
	const open = counts.error + counts.warning + counts.suggestion;
	const editor = document.querySelector( '[data-gds-ct-editor-link]' );
	const hint = document.querySelector( '[data-gds-ct-proofread-hint]' );
	const rerun = document.querySelector( '[data-gds-ct-rerun]' );

	if ( editor ) {
		editor.textContent = open
			? /* translators: %d: number of open remarks */
			  sprintf( _n( 'Fix in editor (%d note)', 'Fix in editor (%d notes)', open, 'gds-content-translation' ), open )
			: __( 'Open in editor', 'gds-content-translation' );
	}

	if ( hint ) {
		hint.textContent = counts.error
			? /* translators: %d: number of open errors */
			  sprintf( _n( 'Fix the AI error first, or mark it as not an issue.', 'Fix the %d AI errors first, or mark them as not an issue.', counts.error, 'gds-content-translation' ), counts.error )
			: __( 'Tick this once someone who knows the language has read the translation.', 'gds-content-translation' );
	}

	if ( rerun ) {
		if ( open ) {
			rerun.setAttribute(
				'data-confirm',
				/* translators: %d: number of open remarks */
				sprintf( _n( 'Run the review again? Its %d open remark is replaced by the new review’s remarks.', 'Run the review again? Its %d open remarks are replaced by the new review’s remarks.', open, 'gds-content-translation' ), open )
			);
		} else {
			rerun.removeAttribute( 'data-confirm' );
		}
	}
}
