import apiFetch from '@wordpress/api-fetch';
import type { PageEstimate, ScanData } from './types';

const NAMESPACE = '/gds-content-translation/v1';

export function fetchScan( postId: number ): Promise< ScanData > {
	return apiFetch< ScanData >( { path: `${ NAMESPACE }/scans/${ postId }` } );
}

/** Reads the page now; sends nothing to Claude. */
export function estimateScan( postId: number ): Promise< PageEstimate > {
	return apiFetch< PageEstimate >( { path: `${ NAMESPACE }/scans/${ postId }/estimate` } );
}

export function queueScan( postId: number ): Promise< ScanData > {
	return apiFetch< ScanData >( { path: `${ NAMESPACE }/scans/${ postId }`, method: 'POST' } );
}
