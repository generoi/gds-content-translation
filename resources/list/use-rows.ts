/**
 * The rows of the current page, from GET /rows. The previous page stays on
 * screen while the next one loads; a quiet refresh (after an action, or
 * while a review or a bulk translation runs) never shows the loading state.
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
}

export function useRows( postType: string, view: View ): RowsState {
	const path = rowsPath( postType, view );
	const [ data, setData ] = useState< RowsResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ reload, setReload ] = useState( { count: 0, quiet: false } );
	const [ loadedAt, setLoadedAt ] = useState( 0 );
	const lastPathRef = useRef( '' );

	useEffect( () => {
		const controller = new AbortController();
		const quiet = reload.quiet && lastPathRef.current === path;

		lastPathRef.current = path;

		if ( ! quiet ) {
			setIsLoading( true );
		}

		apiFetch< RowsResponse >( { path, signal: controller.signal } )
			.then( ( response ) => {
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
				}
			} );

		return () => controller.abort();
	}, [ path, reload ] );

	const refresh = useCallback( ( quiet = true ) => setReload( ( current ) => ( { count: current.count + 1, quiet } ) ), [] );

	const patchRow = useCallback( ( sourceId: number, update: ( row: Row ) => Row ) => {
		setData( ( current ) => current && { ...current, rows: current.rows.map( ( row ) => ( row.sourceId === sourceId ? update( row ) : row ) ) } );
	}, [] );

	return { data, isLoading, error, refresh, patchRow, loadedAt };
}
