import apiFetch from '@wordpress/api-fetch';
import type { MediaData } from './types';

const NAMESPACE = '/gds-content-translation/v1';

export function fetchMedia( postId: number ): Promise< MediaData > {
	return apiFetch< MediaData >( { path: `${ NAMESPACE }/media/${ postId }` } );
}

export function markMedia( postId: number, keys: string[], ok: boolean ): Promise< MediaData > {
	return apiFetch< MediaData >( {
		path: `${ NAMESPACE }/media/${ postId }`,
		method: 'POST',
		data: { keys, ok },
	} );
}

export function runMediaAction( postId: number, key: string, action: string, data: { value?: string | number; labels?: Record< string, string > } = {} ): Promise< MediaData > {
	return apiFetch< MediaData >( {
		path: `${ NAMESPACE }/media/${ postId }/action`,
		method: 'POST',
		data: { key, action, ...data },
	} );
}
