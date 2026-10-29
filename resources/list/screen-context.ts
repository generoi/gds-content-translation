/**
 * What the cells, which DataViews renders, need from the screen: the post
 * type, which cells are busy, and how to run an action and refresh.
 */
import { createContext, useContext } from '@wordpress/element';
import type { ActionResponse, Row } from '../types';

export interface ScreenContextValue {
	postType: string;
	/** Cells with something going on locally, by `sourceId:language`: "creating", "saving". */
	busy: Record< string, string >;
	setBusy: ( key: string, value: string | null ) => void;
	/** Refetch the rows; quiet: without the loading state. */
	refresh: ( quiet?: boolean ) => void;
	/** Replace one row in place, before the refetch confirms it. */
	patchRow: ( sourceId: number, update: ( row: Row ) => Row ) => void;
	/** Run an action on rows; reports errors itself and rejects with nothing to add. */
	run: ( action: string, ids: number[], args?: Record< string, unknown > ) => Promise< ActionResponse >;
	/** Put focus back on a cell once the rows have been reloaded. */
	focusAfterRefresh: ( key: string ) => void;
	/** Start following the bulk translation queue. */
	followJobs: () => void;
}

export const ScreenContext = createContext< ScreenContextValue | null >( null );

export function useScreen(): ScreenContextValue {
	const value = useContext( ScreenContext );

	if ( ! value ) {
		throw new Error( 'useScreen() outside the status screen.' );
	}

	return value;
}

export function cellKey( sourceId: number, language: string ): string {
	return `${ sourceId }:${ language }`;
}
