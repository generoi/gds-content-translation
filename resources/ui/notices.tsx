/**
 * The snackbars at the bottom of the screen: what an action did, with Undo
 * after Trash. Read from the core/notices store; the store announces each
 * one to screen readers as it is created.
 */
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/** Snackbars shown at once; older ones make room for newer ones. */
const MAX_SNACKBARS = 3;

export function Notices() {
	const notices = useSelect( ( select ) => select( noticesStore ).getNotices(), [] );
	const { removeNotice } = useDispatch( noticesStore );
	const snackbars = notices.filter( ( notice ) => notice.type === 'snackbar' ).slice( -MAX_SNACKBARS );

	return (
		<SnackbarList
			className="gds-ct__snackbars"
			notices={ snackbars }
			onRemove={ ( id: string ) => removeNotice( id ) }
		/>
	);
}
