/**
 * The AI review page's list of files, images and forms still in the
 * original's language, above the remarks: those are not text, so the
 * review's table never shows them. Nothing when there are none.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ErrorBoundary } from '../ui/error-boundary';
import { Notices } from '../ui/notices';
import { MediaList } from './media-list';
import type { MediaData } from './types';

export function MediaPanel( { postId, initialOpen, source }: { postId: number; initialOpen: number; source: string } ) {
	const [ open, setOpen ] = useState( initialOpen );
	const update = ( data: MediaData ) => setOpen( data.open );

	return (
		<ErrorBoundary context="media">
			<details className="gds-content-translation-review__media" open={ initialOpen > 0 }>
				<summary>
					{ open > 0
						? sprintf(
								/* translators: 1: number, 2: language name of the original, e.g. Finnish */
								_n( '%1$d file, image or form is still in %2$s', '%1$d files, images or forms are still in %2$s', open, 'gds-content-translation' ),
								open,
								source
						  )
						: __( 'Files, images and forms', 'gds-content-translation' ) }
				</summary>
				<div className="gds-content-translation-review__details">
					<MediaList postId={ postId } onChange={ update } />
				</div>
			</details>
			<Notices />
		</ErrorBoundary>
	);
}
