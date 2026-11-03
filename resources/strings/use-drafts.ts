/**
 * Unsaved edits, by row: what was typed, and what the row had when typing
 * began, so a row of another page still knows it changed.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

interface Draft {
	value: string;
	base: string;
}

export interface Drafts {
	value: ( id: string, saved: string ) => string;
	set: ( id: string, value: string, saved: string ) => void;
	/** The rows that differ from what they had. */
	dirty: string[];
	changes: () => Record< string, string >;
	/** Forget the drafts of these rows (saved), or all. */
	clear: ( ids?: string[] ) => void;
	/** Forget the drafts that are still what was saved: typing during the save stays. */
	settle: ( sent: Record< string, string > ) => void;
}

export function useDrafts(): Drafts {
	const [ drafts, setDrafts ] = useState< Record< string, Draft > >( {} );

	const dirty = useMemo( () => Object.keys( drafts ).filter( ( id ) => drafts[ id ]?.value !== drafts[ id ]?.base ), [ drafts ] );

	const value = useCallback( ( id: string, saved: string ) => drafts[ id ]?.value ?? saved, [ drafts ] );

	const set = useCallback( ( id: string, next: string, saved: string ) => setDrafts( ( current ) => ( { ...current, [ id ]: { value: next, base: current[ id ]?.base ?? saved } } ) ), [] );

	const changes = useCallback( () => Object.fromEntries( dirty.map( ( id ) => [ id, drafts[ id ]?.value ?? '' ] ) ), [ dirty, drafts ] );

	const clear = useCallback( ( ids?: string[] ) => setDrafts( ( current ) => ( ids ? Object.fromEntries( Object.entries( current ).filter( ( [ id ] ) => ! ids.includes( id ) ) ) : {} ) ), [] );

	const settle = useCallback(
		( sent: Record< string, string > ) => setDrafts( ( current ) => Object.fromEntries( Object.entries( current ).filter( ( [ id, draft ] ) => ! ( id in sent ) || draft.value !== sent[ id ] ) ) ),
		[]
	);

	// Leaving the page with unsaved edits asks first.
	useEffect( () => {
		if ( dirty.length === 0 ) {
			return;
		}

		const warn = ( event: BeforeUnloadEvent ) => event.preventDefault();
		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty.length ] );

	return { value, set, dirty, changes, clear, settle };
}
