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

export function notifySuccess( message: string, actions: NoticeAction[] = [] ): void {
	serial += 1;
	void dispatch( noticesStore ).createSuccessNotice( message, {
		id: `gds-ct-${ serial }`,
		type: 'snackbar',
		actions,
		// An Undo stays until it is used or dismissed: a trash is not undone from anywhere else.
		explicitDismiss: actions.length > 0,
	} );
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
