import apiFetch from '@wordpress/api-fetch';
import type { RemarkOperation, RemarksResponse, ReviewData } from './types';

const NAMESPACE = '/gds-content-translation/v1';

export function fetchReview( postId: number ): Promise< ReviewData > {
	return apiFetch< ReviewData >( { path: `${ NAMESPACE }/reviews/${ postId }` } );
}

export function changeRemarks( postId: number, operation: RemarkOperation, remarks: number[], text = '' ): Promise< RemarksResponse > {
	return apiFetch< RemarksResponse >( {
		path: `${ NAMESPACE }/reviews/${ postId }/remarks`,
		method: 'POST',
		data: { operation, remarks, text },
	} );
}
