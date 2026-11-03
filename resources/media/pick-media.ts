/**
 * The media library's own dialog (wp.media, enqueued with wp_enqueue_media()),
 * to choose or upload the file a translation uses instead.
 */
interface MediaFrame {
	on: ( event: string, callback: () => void ) => void;
	open: () => void;
	remove?: () => void;
	state: () => { get: ( name: string ) => { first: () => { toJSON: () => { id: number } } | undefined } };
}

declare global {
	interface Window {
		wp?: { media?: ( options: Record< string, unknown > ) => MediaFrame };
	}
}

/** Resolves with the chosen item's ID, or null when the dialog is closed. */
export function pickMedia( title: string, button: string, type: string ): Promise< number | null > {
	return new Promise( ( resolve ) => {
		const media = window.wp?.media;

		if ( ! media ) {
			resolve( null );

			return;
		}

		const frame = media( { title, button: { text: button }, multiple: false, library: type ? { type } : {} } );
		let chosen: number | null = null;

		frame.on( 'select', () => {
			chosen = frame.state().get( 'selection' ).first()?.toJSON().id ?? null;
		} );
		// Closes after select too: resolve once, with what was chosen.
		frame.on( 'close', () =>
			setTimeout( () => {
				resolve( chosen );
				// Each choice opens a new dialog: not one more hidden in the page.
				frame.remove?.();
			}, 0 )
		);
		frame.open();
	} );
}
