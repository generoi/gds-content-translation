/**
 * The AI review page's section for the check of the page: the review sees
 * the post's texts, the check the page around them (header, footer, menus,
 * image alts, the title).
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ErrorBoundary } from '../ui/error-boundary';
import { Notices } from '../ui/notices';
import { ScanList } from './scan-list';
import type { ScanData } from './types';

export function ScanPanel( { postId, source }: { postId: number; source: string } ) {
	const [ found, setFound ] = useState< number | null >( null );
	const update = ( data: ScanData ) => setFound( data.result?.status === 'done' ? data.result.findings.length : null );

	return (
		<ErrorBoundary context="scan">
			<details className="gds-content-translation-review__media gds-content-translation-review__scan">
				<summary>
					{ found
						? sprintf(
								/* translators: 1: number of texts, 2: language name of the original, e.g. Finnish */
								_n( 'Page check: %1$d text in %2$s', 'Page check: %1$d texts in %2$s', found, 'gds-content-translation' ),
								found,
								source
						  )
						: sprintf(
								/* translators: %s: language name of the original, e.g. Finnish */
								__( 'Check the page for %s', 'gds-content-translation' ),
								source
						  ) }
				</summary>
				<div className="gds-content-translation-review__details">
					<ScanList postId={ postId } onChange={ update } onLoad={ update } />
				</div>
			</details>
			{ ! document.getElementById( 'gds-ct-remarks-root' ) && ! document.getElementById( 'gds-ct-media-root' ) && <Notices /> }
		</ErrorBoundary>
	);
}
