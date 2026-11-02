/**
 * The glossary's REST calls.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import type { View } from '../dataviews';
import type { BulkOperation, BulkResponse, ImportMode, ImportResponse, Sync, Term, TermInput, TermStatus } from './types';

const PATH = '/gds-content-translation/v1/glossary';

/** The server sorts by these; anything else falls back to the source term. */
const ORDERBY: Record< string, string > = { source: 'source', target: 'target', hits: 'hits', updatedAt: 'updated' };

/** A filter's value(s) of the view, as strings. */
export function filterValues( view: View, field: string ): string[] {
	const filter = ( view.filters ?? [] ).find( ( candidate ) => candidate.field === field );
	const value: unknown = filter?.value;
	const values = Array.isArray( value ) ? value : [ value ];

	return values.filter( ( item ): item is string => typeof item === 'string' && item !== '' );
}

export function termsPath( status: TermStatus, view: View ): string {
	const targetLang = filterValues( view, 'targetLang' )[ 0 ];
	const origins = filterValues( view, 'origin' );

	// Empty arguments are left out: the server's schema refuses an empty boolean.
	return addQueryArgs( PATH, {
		page: view.page ?? 1,
		per_page: view.perPage ?? 50,
		status: [ status ],
		orderby: ORDERBY[ view.sort?.field ?? '' ] ?? 'source',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		...( view.search ? { search: view.search } : {} ),
		...( targetLang ? { target_lang: targetLang } : {} ),
		...( origins.length ? { origin: origins } : {} ),
		...( filterValues( view, 'conflict' ).length ? { conflicts: 1 } : {} ),
	} );
}

export function saveTerm( id: number | null, data: TermInput ): Promise< Term > {
	return apiFetch< Term >( { path: id ? `${ PATH }/${ id }` : PATH, method: 'POST', data } );
}

export function bulkTerms( ids: number[], operation: BulkOperation ): Promise< BulkResponse > {
	return apiFetch< BulkResponse >( { path: `${ PATH }/bulk`, method: 'POST', data: { ids, operation } } );
}

export function importTerms( file: File, mode: ImportMode, targetLang: string ): Promise< ImportResponse > {
	const body = new FormData();
	body.append( 'file', file );
	body.append( 'mode', mode );

	if ( targetLang ) {
		body.append( 'target_lang', targetLang );
	}

	return apiFetch< ImportResponse >( { path: `${ PATH }/import`, method: 'POST', body } );
}

export function fetchSync(): Promise< Sync > {
	return apiFetch< Sync >( { path: `${ PATH }/sync` } );
}

export function sendSync(): Promise< Sync > {
	return apiFetch< Sync >( { path: `${ PATH }/sync`, method: 'POST' } );
}
