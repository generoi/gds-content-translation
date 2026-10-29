/**
 * The REST calls, through apiFetch (nonce, `_locale=user`, JSON errors).
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import type { View } from './dataviews';
import type { ActionResponse, FilterKind, Job, RowsResponse } from './types';

const NAMESPACE = '/gds-content-translation/v1';

/** A language column's field id: `lang-en`. */
export const LANGUAGE_FIELD_PREFIX = 'lang-';

/** The per-language filters of a view, as the server reads them: slug => kinds. */
export function viewFilters( view: View ): Record< string, FilterKind[] > {
	const filters: Record< string, FilterKind[] > = {};

	for ( const filter of view.filters ?? [] ) {
		if ( ! filter.field.startsWith( LANGUAGE_FIELD_PREFIX ) ) {
			continue;
		}

		const value = Array.isArray( filter.value ) ? filter.value : [ filter.value ];
		const kinds = value.filter( ( kind ): kind is FilterKind => typeof kind === 'string' && kind !== '' );

		if ( kinds.length ) {
			filters[ filter.field.slice( LANGUAGE_FIELD_PREFIX.length ) ] = kinds;
		}
	}

	return filters;
}

export function rowsPath( postType: string, view: View ): string {
	return addQueryArgs( `${ NAMESPACE }/rows`, {
		post_type: postType,
		page: view.page ?? 1,
		per_page: view.perPage ?? 50,
		search: view.search ?? '',
		orderby: view.sort?.field === 'date' ? 'date' : 'title',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		filters: viewFilters( view ),
	} );
}

export function fetchRows( postType: string, view: View, signal?: AbortSignal ): Promise< RowsResponse > {
	return apiFetch< RowsResponse >( { path: rowsPath( postType, view ), signal } );
}

export function runAction( action: string, ids: number[], args: Record< string, unknown > = {} ): Promise< ActionResponse > {
	return apiFetch< ActionResponse >( {
		path: `${ NAMESPACE }/actions/${ action }`,
		method: 'POST',
		data: { ids, args },
	} );
}

export function fetchJobs(): Promise< { jobs: Job[] } > {
	return apiFetch< { jobs: Job[] } >( { path: `${ NAMESPACE }/jobs` } );
}

export function clearJobs( which: 'queued' | 'finished' ): Promise< { cleared: number } > {
	return apiFetch< { cleared: number } >( {
		path: addQueryArgs( `${ NAMESPACE }/jobs`, { which } ),
		method: 'DELETE',
	} );
}

/** The message of an apiFetch rejection: a REST error, or a network failure. */
export function errorMessage( error: unknown, fallback: string ): string {
	if ( error && typeof error === 'object' && 'message' in error && typeof ( error as { message: unknown } ).message === 'string' ) {
		return ( error as { message: string } ).message || fallback;
	}

	return fallback;
}
