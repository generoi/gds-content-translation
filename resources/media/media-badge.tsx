/**
 * A translation's files, images and forms still in the original's language,
 * in its cell: how many ("2 in Finnish"), opening the list in a modal. The
 * cell's menu opens the same list when nothing is open, to correct what was
 * translated.
 */
import { Modal, VisuallyHidden } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { defaultLanguage } from '../settings';
import { MediaList } from './media-list';

export function MediaModal( { postId, about, onChange, onQueued, onClose }: { postId: number; about: string; onChange: () => void; onQueued?: () => void; onClose: () => void } ) {
	const source = defaultLanguage()?.name ?? '';

	return (
		<Modal
			title={
				/* translators: %s: language name of the original, e.g. Finnish */
				sprintf( __( 'Files, images, links and texts in %s', 'gds-content-translation' ), source )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<p className="gds-ct-media__intro">{ about }</p>
			<MediaList postId={ postId } onChange={ onChange } onQueued={ onQueued } />
		</Modal>
	);
}

/**
 * The modal is the cell's, so it stays open while the last item is fixed
 * and the badge goes.
 */
export function MediaBadge( { count, about, onOpen }: { count?: number; about: string; onOpen: () => void } ) {
	const source = defaultLanguage()?.name ?? '';

	if ( ! count ) {
		return null;
	}

	const label = sprintf(
		/* translators: 1: number of files, images and forms, 2: language name of the original, e.g. Finnish */
		_n( '%1$d in %2$s', '%1$d in %2$s', count, 'gds-content-translation' ),
		count,
		source
	);
	const title = sprintf(
		/* translators: 1: number, 2: language name of the original, e.g. Finnish */
		_n( '%1$d file, image, form, link or text still in %2$s or not written yet', '%1$d files, images, forms, links or texts still in %2$s or not written yet', count, 'gds-content-translation' ),
		count,
		source
	);

	return (
		<button type="button" className="gds-ct__audit gds-ct__audit--media" title={ title } onClick={ onOpen }>
			<span aria-hidden="true">{ label }</span>
			<VisuallyHidden>{ `${ title }: ${ about }` }</VisuallyHidden>
		</button>
	);
}
