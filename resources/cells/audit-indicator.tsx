/**
 * The AI review of a translation in its cell, as short as it can be: its open
 * errors ("5 errors"), else its other open remarks ("3 to check"), or that it
 * is running or failed. Nothing when it has nothing open, or no review: the
 * cell's menu runs one. Each badge leads to the review page; its accessible
 * name says it in full.
 */
import { VisuallyHidden } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { Audit } from '../types';

/** The review in full words, for screen readers and tooltips. */
export function auditLabel( audit: Audit ): string {
	switch ( audit.status ) {
		case 'pending':
			return __( 'AI review running', 'gds-content-translation' );
		case 'stuck':
			return __( 'AI review did not finish', 'gds-content-translation' );
		case 'failed':
			return __( 'AI review failed', 'gds-content-translation' );
	}

	const { errors, other } = audit;

	if ( errors === 0 && other === 0 ) {
		return __( 'AI review: nothing open', 'gds-content-translation' );
	}

	return sprintf(
		/* translators: 1: number of open errors, 2: number of other open remarks */
		__( 'AI review: %1$d errors, %2$d to check', 'gds-content-translation' ),
		errors,
		other
	);
}

function badge( audit: Audit ): { label: string; modifier: string } | null {
	switch ( audit.status ) {
		case 'pending':
			return { label: __( 'Reviewing…', 'gds-content-translation' ), modifier: 'pending' };
		case 'stuck':
		case 'failed':
			return { label: __( 'Review failed', 'gds-content-translation' ), modifier: 'failed' };
		case 'none':
			return null;
	}

	if ( audit.errors > 0 ) {
		/* translators: %d: number of open errors found by the AI review */
		return { label: sprintf( _n( '%d error', '%d errors', audit.errors, 'gds-content-translation' ), audit.errors ), modifier: 'errors' };
	}

	if ( audit.other > 0 ) {
		/* translators: %d: number of open remarks (not errors) from the AI review; same in singular and plural in English */
		return { label: sprintf( _n( '%d to check', '%d to check', audit.other, 'gds-content-translation' ), audit.other ), modifier: 'remarks' };
	}

	return null;
}

export function AuditBadge( { audit, about }: { audit?: Audit | null; about: string } ) {
	const shown = audit ? badge( audit ) : null;

	if ( ! audit || ! shown ) {
		return null;
	}

	const title = audit.error || auditLabel( audit ) + ( audit.edited ? ` · ${ __( 'edited since the review', 'gds-content-translation' ) }` : '' );

	return (
		<a className={ `gds-ct__audit gds-ct__audit--${ shown.modifier }` } href={ audit.url } title={ title }>
			{ shown.modifier === 'pending' && <span className="gds-ct__spinner" aria-hidden="true" /> }
			<span aria-hidden="true">{ shown.label }</span>
			<VisuallyHidden>{ `${ title }: ${ about }` }</VisuallyHidden>
		</a>
	);
}
