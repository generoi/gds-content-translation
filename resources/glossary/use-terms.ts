/**
 * The terms of the current tab and page, from GET /glossary, with the counts
 * per status and DeepL's sync state. The previous page stays on screen while
 * the next one loads; a quiet refresh (after a change) never shows loading.
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { errorMessage } from '../api';
import type { View } from '../dataviews';
import { termsPath } from './api';
import type { Sync, TermStatus, TermsResponse } from './types';

export interface TermsState {
	data: TermsResponse | null;
	isLoading: boolean;
	error: string;
	refresh: ( quiet?: boolean ) => void;
	/** Takes a newer sync state (POST or GET /glossary/sync) without reloading the terms. */
	setSync: ( sync: Sync ) => void;
}

export function useTerms( status: TermStatus, view: View ): TermsState {
	const path = termsPath( status, view );
	const [ data, setData ] = useState< TermsResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ reload, setReload ] = useState( { count: 0, quiet: false } );
	const lastPathRef = useRef( '' );

	useEffect( () => {
		const controller = new AbortController();
		const quiet = reload.quiet && lastPathRef.current === path;

		lastPathRef.current = path;

		if ( ! quiet ) {
			setIsLoading( true );
		}

		apiFetch< TermsResponse >( { path, signal: controller.signal } )
			.then( ( response ) => {
				setData( response );
				setError( '' );
			} )
			.catch( ( caught: unknown ) => {
				if ( controller.signal.aborted ) {
					return;
				}

				setError( errorMessage( caught, __( 'The glossary could not be loaded.', 'gds-content-translation' ) ) );
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) {
					setIsLoading( false );
				}
			} );

		return () => controller.abort();
	}, [ path, reload ] );

	const refresh = useCallback( ( quiet = true ) => setReload( ( current ) => ( { count: current.count + 1, quiet } ) ), [] );
	const setSync = useCallback( ( sync: Sync ) => setData( ( current ) => current && { ...current, sync } ), [] );

	return { data, isLoading, error, refresh, setSync };
}
