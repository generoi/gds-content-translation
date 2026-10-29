/**
 * The original, on one line: its title opens it in the editor, followed by
 * its status when it is not published and its open notes. View is in the
 * row's menu.
 */
import { VisuallyHidden } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { defaultLanguage } from '../settings';
import type { Row } from '../types';
import { statusLabel } from './language-cell';
import { NotesIndicator } from './notes';

export function TitleCell( { item }: { item: Row } ) {
	const newTab = __( '(opens in a new tab)', 'gds-content-translation' );
	const status = item.status !== 'publish' ? statusLabel( item.status ) : '';

	return (
		<div className="gds-ct__title" id={ `gds-ct-row-${ item.sourceId }` }>
			{ item.editUrl ? (
				<a className="gds-ct__title-link" href={ item.editUrl } target="_blank" rel="noopener noreferrer" title={ item.title }>
					{ item.title }
					<VisuallyHidden>
						{ ' (' }
						{ sprintf(
							/* translators: %s: source language name */
							__( 'edit the %s original', 'gds-content-translation' ),
							defaultLanguage()?.name ?? ''
						) }
						{ ') ' }
						{ newTab }
					</VisuallyHidden>
				</a>
			) : (
				<span className="gds-ct__title-link" title={ item.title }>
					{ item.title }
				</span>
			) }
			{ status && <span className="gds-ct__post-status">{ status }</span> }
			<NotesIndicator count={ item.openNotes } editUrl={ item.editUrl } about={ `“${ item.title }”` } />
		</div>
	);
}
