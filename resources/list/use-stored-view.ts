/**
 * The view, remembered per post type: columns, sorting and page size for
 * good (localStorage), search, filters and page for this browser tab
 * (sessionStorage), so a reload or a trip to the editor comes back to the
 * same rows. Storage may be unavailable (private mode); the view then
 * simply starts over.
 */
import { useCallback, useState } from '@wordpress/element';
import type { View } from '../dataviews';
import { createDefaultView, languageFieldIds } from './default-view';

const KEY = 'gds-content-translation:view';

type StorageName = 'localStorage' | 'sessionStorage';

/** The accessor itself throws when site data is blocked. */
function storage( name: StorageName ): Storage | null {
	try {
		return window[ name ];
	} catch {
		return null;
	}
}

function read( name: StorageName, postType: string ): Partial< View > {
	try {
		const stored = JSON.parse( storage( name )?.getItem( `${ KEY }:${ postType }` ) || '{}' );

		return stored && typeof stored === 'object' ? stored : {};
	} catch {
		return {};
	}
}

function write( name: StorageName, postType: string, value: Partial< View > ): void {
	try {
		storage( name )?.setItem( `${ KEY }:${ postType }`, JSON.stringify( value ) );
	} catch {
		// Full or unavailable: the view is not remembered.
	}
}

export function loadView( postType: string ): View {
	const view = createDefaultView();
	const lasting = read( 'localStorage', postType );
	const session = read( 'sessionStorage', postType );
	const known = new Set( [ 'date', ...languageFieldIds() ] );

	return {
		...view,
		...( Array.isArray( lasting.fields ) ? { fields: lasting.fields.filter( ( field ) => known.has( field ) ) } : {} ),
		...( lasting.sort && ( lasting.sort.field === 'title' || lasting.sort.field === 'date' ) ? { sort: lasting.sort } : {} ),
		...( typeof lasting.perPage === 'number' ? { perPage: lasting.perPage } : {} ),
		...( typeof session.search === 'string' ? { search: session.search } : {} ),
		...( Array.isArray( session.filters ) ? { filters: session.filters.filter( ( filter ) => known.has( filter.field ) ) } : {} ),
		...( typeof session.page === 'number' ? { page: session.page } : {} ),
	} as View;
}

export function useStoredView( postType: string ): [ View, ( view: View ) => void, () => void ] {
	const [ views, setViews ] = useState< Record< string, View > >( {} );
	const view = views[ postType ] ?? loadView( postType );

	const setView = useCallback(
		( next: View ) => {
			write( 'localStorage', postType, { fields: next.fields, sort: next.sort, perPage: next.perPage } );
			write( 'sessionStorage', postType, { search: next.search, filters: next.filters, page: next.page } );
			setViews( ( current ) => ( { ...current, [ postType ]: next } ) );
		},
		[ postType ]
	);

	const reset = useCallback( () => setView( createDefaultView() ), [ setView ] );

	return [ view, setView, reset ];
}
