import { VisuallyHidden } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/** Open block editor notes of a post, as a count, linking to the editor. */
export function NotesIndicator( { count, editUrl, about }: { count?: number; editUrl?: string; about: string } ) {
	if ( ! count || ! editUrl ) {
		return null;
	}

	return (
		<a className="gds-ct__notes" href={ editUrl } target="_blank" rel="noopener noreferrer" title={ __( 'Open block editor notes', 'gds-content-translation' ) }>
			<span className="dashicons dashicons-admin-comments" aria-hidden="true" />
			<span aria-hidden="true">{ count }</span>
			<VisuallyHidden>
				{ sprintf(
					/* translators: 1: number of open block editor notes, 2: what they are on */
					_n( '%1$d open note on %2$s', '%1$d open notes on %2$s', count, 'gds-content-translation' ),
					count,
					about
				) }{ ' ' }
				{ __( '(opens in a new tab)', 'gds-content-translation' ) }
			</VisuallyHidden>
		</a>
	);
}
