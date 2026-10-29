/**
 * The counts above the table on one line of a fixed height, so the table
 * never moves: per language, how many are still to translate, to proofread,
 * with open AI errors, being reviewed. Each count is a filter toggle. Only
 * what is not zero is shown; before the counts arrive, the line keeps its
 * place.
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { LANGUAGE_FIELD_PREFIX } from '../api';
import type { View } from '../dataviews';
import { languageCode } from '../settings';
import type { FilterKind, Summary, SummaryItem } from '../types';
import { filterLabels } from './fields';

function isOnly( view: View, slug: string, kind: FilterKind ): boolean {
	const filters = view.filters ?? [];
	const value = filters[ 0 ]?.value;

	return filters.length === 1 && filters[ 0 ]?.field === `${ LANGUAGE_FIELD_PREFIX }${ slug }` && Array.isArray( value ) && value.length === 1 && value[ 0 ] === kind;
}

export function SummaryBar( { summary, view, onChangeView }: { summary: Summary | null; view: View; onChangeView: ( view: View ) => void } ) {
	const labels = filterLabels();

	const toggle = ( slug: string, kind: FilterKind ) => {
		onChangeView( {
			...view,
			page: 1,
			filters: isOnly( view, slug, kind ) ? [] : [ { field: `${ LANGUAGE_FIELD_PREFIX }${ slug }`, operator: 'isAny', value: [ kind ] } ],
		} );
	};

	const groups: Array< { kind: FilterKind; items: SummaryItem[] } > = summary
		? ( [
				{ kind: 'missing', items: summary.missing },
				{ kind: 'unproofread', items: summary.notProofread },
				{ kind: 'errors', items: summary.aiErrors },
				{ kind: 'running', items: summary.reviewRunning },
		  ] as Array< { kind: FilterKind; items: SummaryItem[] } > )
				.map( ( group ) => ( { ...group, items: group.items.filter( ( item ) => item.count > 0 ) } ) )
				.filter( ( group ) => group.items.length > 0 )
		: [];

	const narrowed = !! view.search || ( view.filters ?? [] ).length > 0;

	return (
		<div className="gds-ct__summary" aria-busy={ ! summary }>
			{ summary && ! groups.length && <span className="gds-ct__summary-done">{ __( 'Everything is translated and proofread.', 'gds-content-translation' ) }</span> }
			{ groups.map( ( group ) => (
				<span key={ group.kind } className={ `gds-ct__summary-group gds-ct__summary-group--${ group.kind }` } role="group" aria-label={ labels[ group.kind ] }>
					<span className="gds-ct__summary-label" aria-hidden="true">
						{ labels[ group.kind ] }
					</span>
					{ group.items.map( ( item ) => (
						<button
							key={ item.slug }
							type="button"
							className="gds-ct__chip"
							aria-pressed={ isOnly( view, item.slug, group.kind ) }
							title={ item.name }
							onClick={ () => toggle( item.slug, group.kind ) }
						>
							<span aria-hidden="true">
								<span className="gds-ct__chip-code">{ languageCode( item.slug ) }</span> { item.count }
							</span>
							<span className="screen-reader-text">
								{ sprintf(
									/* translators: 1: what is counted, e.g. To translate, 2: language name, 3: count */
									__( '%1$s in %2$s: %3$d. Shows only these rows.', 'gds-content-translation' ),
									labels[ group.kind ],
									item.name,
									item.count
								) }
							</span>
						</button>
					) ) }
				</span>
			) ) }
			{ narrowed && (
				<Button variant="link" className="gds-ct__summary-clear" onClick={ () => onChangeView( { ...view, page: 1, search: '', filters: [] } ) }>
					{ __( 'Show all', 'gds-content-translation' ) }
				</Button>
			) }
		</div>
	);
}
