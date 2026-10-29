/**
 * The columns: the original's title (the primary column), its date, and one
 * column per translation language. A language column filters by what its
 * cells are: missing, not proof read, with open AI errors, being reviewed.
 */
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';
import { LANGUAGE_FIELD_PREFIX } from '../api';
import { LanguageCell } from '../cells/language-cell';
import { TitleCell } from '../cells/title-cell';
import type { Field } from '../dataviews';
import { defaultLanguage, translationLanguages } from '../settings';
import type { Settings } from '../settings';
import type { FilterKind, Row } from '../types';

export function filterLabels(): Record< FilterKind, string > {
	return {
		missing: __( 'To translate', 'gds-content-translation' ),
		unproofread: __( 'To proofread', 'gds-content-translation' ),
		errors: __( 'With AI errors', 'gds-content-translation' ),
		running: __( 'AI review running', 'gds-content-translation' ),
	};
}

function DateCell( { item }: { item: Row } ) {
	return <time dateTime={ item.date }>{ dateI18n( getDateSettings().formats.date, item.date, undefined ) }</time>;
}

export function buildFields( settings: Settings ): Field< Row >[] {
	const source = defaultLanguage( settings )?.name;
	const labels = filterLabels();
	const kinds: FilterKind[] = settings.review ? [ 'missing', 'unproofread', 'errors', 'running' ] : [ 'missing', 'unproofread' ];

	const fields: Field< Row >[] = [
		{
			id: 'title',
			/* translators: %s: source language name, e.g. Finnish */
			label: source ? sprintf( __( 'Original (%s)', 'gds-content-translation' ), source ) : __( 'Original', 'gds-content-translation' ),
			getValue: ( { item } ) => item.title,
			render: TitleCell,
			enableSorting: true,
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'date',
			label: __( 'Date', 'gds-content-translation' ),
			getValue: ( { item } ) => item.date,
			render: DateCell,
			enableSorting: true,
			filterBy: false,
		},
	];

	for ( const language of translationLanguages( settings ) ) {
		const Render = ( { item }: { item: Row } ) => <LanguageCell item={ item } slug={ language.slug } name={ language.name } />;
		Render.displayName = `LanguageCell(${ language.slug })`;

		fields.push( {
			id: `${ LANGUAGE_FIELD_PREFIX }${ language.slug }`,
			label: language.name,
			getValue: ( { item } ) => item.languages[ language.slug ]?.state ?? 'missing',
			render: Render,
			enableSorting: false,
			enableHiding: true,
			elements: kinds.map( ( kind ) => ( { value: kind, label: labels[ kind ] } ) ),
			// Filtered by the counts above the table, in one click; DataViews'
			// own filter took a language first and a state second, and did
			// nothing in between.
			filterBy: false,
		} );
	}

	return fields;
}
