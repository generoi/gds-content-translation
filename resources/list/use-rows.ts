/**
 * The rows of the current page, from GET /rows. The previous page stays on
 * screen while the next one loads; a quiet refresh (after an action, or
 * while a review or a bulk translation runs) never shows the loading state.
 * A quiet refresh that started before a row was patched in place answers
 * with the row as it was, so its answer is dropped: the refresh after the
 * change brings the rows.
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { errorMessage, rowsPath } from '../api';
import type { View } from '../dataviews';
import type { Row, RowsResponse } from '../types';

export interface RowsState {
	data: RowsResponse | null;
	isLoading: boolean;
	error: string;
	refresh: ( quiet?: boolean ) => void;
	patchRow: ( sourceId: number, update: ( row: Row ) => Row ) => void;
	/** Bumped each time rows arrive, for effects that wait for them. */
	loadedAt: number;
	/** When the last load finished, also a failed one: polling goes on after a blip. */
	settledAt: number;
}

export function useRows( postType: string, view: View ): RowsState {
	const path = rowsPath( postType, view );
	const [ data, setData ] = useState< RowsResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ reload, setReload ] = useState( { count: 0, quiet: false } );
	const [ loadedAt, setLoadedAt ] = useState( 0 );
	const [ settledAt, setSettledAt ] = useState( 0 );
	const lastPathRef = useRef( '' );
	/** Bumped by each patch: a quiet answer to an older question is stale. */
	const patchesRef = useRef( 0 );

	useEffect( () => {
		const controller = new AbortController();
		const quiet = reload.quiet && lastPathRef.current === path;
		const patches = patchesRef.current;

		lastPathRef.current = path;

		if ( ! quiet ) {
			setIsLoading( true );
		}

		apiFetch< RowsResponse >( { path, signal: controller.signal } )
			.then( ( response ) => {
				// Other rows (a new page) always; the same ones not over a newer patch.
				if ( quiet && patches !== patchesRef.current ) {
					return;
				}

				setData( response );
				setError( '' );
				setLoadedAt( Date.now() );
			} )
			.catch( ( caught: unknown ) => {
				if ( controller.signal.aborted ) {
					return;
				}

				setError( errorMessage( caught, __( 'The translation status could not be loaded.', 'gds-content-translation' ) ) );
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) {
					setIsLoading( false );
					setSettledAt( Date.now() );
				}
			} );

		return () => controller.abort();
	}, [ path, reload ] );

	const refresh = useCallback( ( quiet = true ) => setReload( ( current ) => ( { count: current.count + 1, quiet } ) ), [] );

	const patchRow = useCallback( ( sourceId: number, update: ( row: Row ) => Row ) => {
		patchesRef.current++;
		setData( ( current ) => current && { ...current, rows: current.rows.map( ( row ) => ( row.sourceId === sourceId ? update( row ) : row ) ) } );
	}, [] );

	return { data, isLoading, error, refresh, patchRow, loadedAt, settledAt };
}
