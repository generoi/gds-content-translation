/**
 * A translation's files, images and forms still in the original's language,
 * in its cell: how many ("2 in Finnish"), opening the list in a modal.
 * Nothing when there are none, or the translation is not checked yet.
 */
import { Modal, VisuallyHidden } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { defaultLanguage } from '../settings';
import { MediaList } from './media-list';

export function MediaBadge( { postId, count, about, onChange }: { postId: number; count?: number; about: string; onChange: () => void } ) {
	const [ isOpen, setIsOpen ] = useState( false );
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
		_n( '%1$d file, image or form still in %2$s', '%1$d files, images or forms still in %2$s', count, 'gds-content-translation' ),
		count,
		source
	);

	return (
		<>
			<button type="button" className="gds-ct__audit gds-ct__audit--media" title={ title } onClick={ () => setIsOpen( true ) }>
				<span aria-hidden="true">{ label }</span>
				<VisuallyHidden>{ `${ title }: ${ about }` }</VisuallyHidden>
			</button>
			{ isOpen && (
				<Modal
					title={
						/* translators: %s: language name of the original, e.g. Finnish */
						sprintf( __( 'Still in %s', 'gds-content-translation' ), source )
					}
					onRequestClose={ () => setIsOpen( false ) }
					size="medium"
				>
					<p className="gds-ct-media__intro">{ about }</p>
					<MediaList postId={ postId } onChange={ onChange } />
				</Modal>
			) }
		</>
	);
}
