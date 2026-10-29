/**
 * Notices from outside React components (action callbacks).
 */
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

export interface NoticeAction {
	label: string;
	onClick: () => void;
}

let serial = 0;

export function notifySuccess( message: string, actions: NoticeAction[] = [], id = '' ): void {
	serial += 1;
	void dispatch( noticesStore ).createSuccessNotice( message, {
		id: id || `gds-ct-${ serial }`,
		type: 'snackbar',
		actions,
		// An Undo stays until it is used or dismissed: a trash is not undone from anywhere else.
		explicitDismiss: actions.length > 0,
	} );
}

/** Takes back a notice whose action no longer applies, such as an Undo. */
export function dismissNotice( id: string ): void {
	void dispatch( noticesStore ).removeNotice( id );
}

/** Errors stay until dismissed: they say what did not happen. */
export function notifyError( message: string ): void {
	serial += 1;
	void dispatch( noticesStore ).createErrorNotice( message, {
		id: `gds-ct-${ serial }`,
		type: 'snackbar',
		explicitDismiss: true,
	} );
}

/** Takes back every snackbar, such as an error a later action made moot. */
export function clearNotices(): void {
	void dispatch( noticesStore ).removeAllNotices( 'snackbar' );
}
