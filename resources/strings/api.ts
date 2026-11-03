import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import type { AltsChanged, AltsResponse, StringsResponse } from './types';

const NAMESPACE = '/gds-content-translation/v1';

export function fetchStrings( lang: string ): Promise< StringsResponse > {
	return apiFetch< StringsResponse >( { path: `${ NAMESPACE }/strings/${ lang }` } );
}

export function saveStrings( lang: string, data: { translations?: Record< string, string >; slugs?: Record< string, string > } ): Promise< StringsResponse > {
	return apiFetch< StringsResponse >( { path: `${ NAMESPACE }/strings/${ lang }`, method: 'POST', data } );
}

export function translateStrings( lang: string, ids: string[] = [] ): Promise< StringsResponse > {
	return apiFetch< StringsResponse >( { path: `${ NAMESPACE }/strings/${ lang }/translate`, method: 'POST', data: { ids } } );
}

export function suggestSlugs( lang: string, keys: string[] = [] ): Promise< { suggestions: Record< string, string > } > {
	return apiFetch< { suggestions: Record< string, string > } >( { path: `${ NAMESPACE }/strings/${ lang }/slugs`, method: 'POST', data: { keys } } );
}

export function reviewStrings( lang: string ): Promise< StringsResponse > {
	return apiFetch< StringsResponse >( { path: `${ NAMESPACE }/strings/${ lang }/review`, method: 'POST' } );
}

export function settleRemark( lang: string, id: string, use: boolean ): Promise< StringsResponse > {
	return apiFetch< StringsResponse >( { path: `${ NAMESPACE }/strings/${ lang }/remark`, method: 'POST', data: { id, use } } );
}

export function fetchAlts( lang: string, query: { page: number; perPage: number; search: string; missing: boolean }, signal?: AbortSignal ): Promise< AltsResponse > {
	return apiFetch< AltsResponse >( {
		path: addQueryArgs( `${ NAMESPACE }/alts/${ lang }`, {
			page: query.page,
			per_page: query.perPage,
			...( query.search ? { search: query.search } : {} ),
			...( query.missing ? { missing: 1 } : {} ),
		} ),
		signal,
	} );
}

export function saveAlts( lang: string, alts: Record< string, string > ): Promise< AltsChanged > {
	return apiFetch< AltsChanged >( { path: `${ NAMESPACE }/alts/${ lang }`, method: 'POST', data: { alts } } );
}

export function translateAlts( lang: string, ids: number[] = [] ): Promise< AltsChanged > {
	return apiFetch< AltsChanged >( { path: `${ NAMESPACE }/alts/${ lang }/translate`, method: 'POST', data: { ids } } );
}
