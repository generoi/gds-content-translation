/**
 * The view a first visit gets, and the one Reset view goes back to: the
 * table, by title, every language column, 50 rows a page.
 */
import { LANGUAGE_FIELD_PREFIX } from '../api';
import type { SupportedLayouts, View } from '../dataviews';
import { translationLanguages } from '../settings';

export const PER_PAGE_SIZES = [ 20, 50, 100, 200 ];

export function languageFieldIds(): string[] {
	return translationLanguages().map( ( language ) => `${ LANGUAGE_FIELD_PREFIX }${ language.slug }` );
}

function columnStyles(): Record< string, { minWidth?: number; width?: number | string } > {
	const styles: Record< string, { minWidth?: number; width?: number | string } > = { date: { width: 120 } };

	// A language column fits its chips and menu; the title takes the rest.
	for ( const id of languageFieldIds() ) {
		styles[ id ] = { width: 260, minWidth: 220 };
	}

	return styles;
}

export const DEFAULT_LAYOUTS: SupportedLayouts = {
	table: {
		layout: { density: 'compact', styles: columnStyles() },
	},
};

export function createDefaultView(): View {
	return {
		type: 'table',
		page: 1,
		perPage: 50,
		search: '',
		filters: [],
		sort: { field: 'title', direction: 'asc' },
		titleField: 'title',
		showTitle: true,
		fields: languageFieldIds(),
		layout: { density: 'compact', styles: columnStyles() },
	};
}
