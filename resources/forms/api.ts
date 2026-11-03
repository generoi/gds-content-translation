import apiFetch from '@wordpress/api-fetch';
import type { FormRows, FormSummary } from './types';

const NAMESPACE = '/gds-content-translation/v1';

export function fetchForms(): Promise< { forms: FormSummary[] } > {
	return apiFetch< { forms: FormSummary[] } >( { path: `${ NAMESPACE }/forms` } );
}

export function fetchFormRows( formId: number, lang: string ): Promise< FormRows > {
	return apiFetch< FormRows >( { path: `${ NAMESPACE }/forms/${ formId }/${ lang }` } );
}

export function saveForm( formId: number, lang: string, translations: Record< string, string > ): Promise< FormRows > {
	return apiFetch< FormRows >( { path: `${ NAMESPACE }/forms/${ formId }/${ lang }`, method: 'POST', data: { translations } } );
}

export function formOperation( formId: number, lang: string, operation: 'translate' | 'review' | 'remark', data: { source?: string; use?: boolean } = {} ): Promise< FormRows > {
	return apiFetch< FormRows >( { path: `${ NAMESPACE }/forms/${ formId }/${ lang }/${ operation }`, method: 'POST', data } );
}
