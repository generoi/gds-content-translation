import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function EmptyState( { hasQuery, error, onClear }: { hasQuery: boolean; error: string; onClear: () => void } ) {
	if ( error ) {
		return (
			<div className="gds-ct__empty" role="alert">
				<p>{ __( 'The translation status could not be loaded.', 'gds-content-translation' ) }</p>
				<p className="description">{ error }</p>
			</div>
		);
	}

	if ( hasQuery ) {
		return (
			<div className="gds-ct__empty">
				<p>{ __( 'No titles match your search and filters.', 'gds-content-translation' ) }</p>
				<Button variant="secondary" onClick={ onClear }>
					{ __( 'Clear search and filters', 'gds-content-translation' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="gds-ct__empty">
			<p>{ __( 'No posts found for this post type.', 'gds-content-translation' ) }</p>
		</div>
	);
}
