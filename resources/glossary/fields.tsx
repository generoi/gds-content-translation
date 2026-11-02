/**
 * The columns: the source term (the primary column, which opens the term),
 * its translation, the language, the note, where the term came from, when it
 * changed. A hidden field filters to the conflicts.
 */
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, caution } from '@wordpress/icons';
import type { Field } from '../dataviews';
import type { GlossarySettings } from '../settings';
import type { Term, TermOrigin, TermStatus } from './types';

export function originLabels(): Record< TermOrigin, string > {
	return {
		import: __( 'Imported', 'gds-content-translation' ),
		manual: __( 'Added by hand', 'gds-content-translation' ),
		review: __( 'AI review', 'gds-content-translation' ),
		proofread: __( 'Proofreading', 'gds-content-translation' ),
	};
}

export function statusLabels(): Record< TermStatus, string > {
	return {
		approved: __( 'In use', 'gds-content-translation' ),
		suggested: __( 'Suggested', 'gds-content-translation' ),
		draft: __( 'Draft', 'gds-content-translation' ),
		rejected: __( 'Rejected', 'gds-content-translation' ),
	};
}

export function languageName( glossary: GlossarySettings, code: string ): string {
	return glossary.targetLangs.find( ( language ) => language.code === code )?.name ?? code;
}

function SourceCell( { item }: { item: Term } ) {
	return <span className="gds-ct-glossary__term">{ item.source }</span>;
}

function TargetCell( { item }: { item: Term } ) {
	return (
		<span className="gds-ct-glossary__target">
			<span className="gds-ct-glossary__term">{ item.target }</span>
			{ item.conflict && (
				<span className="gds-ct-glossary__conflict" title={ __( 'Another term in use has the same source term and a different translation.', 'gds-content-translation' ) }>
					<Icon icon={ caution } size={ 16 } />
					{ __( 'Two translations', 'gds-content-translation' ) }
				</span>
			) }
			{ item.status === 'suggested' && item.replaces && (
				<span className="gds-ct-glossary__replaces">
					{
						/* translators: %s: the translation in use now */
						sprintf( __( 'Replaces: %s', 'gds-content-translation' ), item.replaces )
					}
				</span>
			) }
		</span>
	);
}

function NoteCell( { item }: { item: Term } ) {
	return item.note ? <span className="gds-ct-glossary__note">{ item.note }</span> : null;
}

function OriginCell( { item }: { item: Term } ) {
	const seen = item.origin === 'review' || item.origin === 'proofread';

	return (
		<span className="gds-ct-glossary__origin">
			<span>
				{ originLabels()[ item.origin ] ?? item.origin }
				{ seen && item.hits > 1 && (
					<span className="gds-ct-glossary__hits">
						{ ' · ' }
						{
							/* translators: %d: how many times the term was seen */
							sprintf( _n( 'Seen %d time', 'Seen %d times', item.hits, 'gds-content-translation' ), item.hits )
						}
					</span>
				) }
			</span>
			{ item.example && <q className="gds-ct-glossary__example">{ item.example }</q> }
			{ item.postTitle &&
				( item.postEditUrl ? (
					<a className="gds-ct-glossary__post" href={ item.postEditUrl } target="_blank" rel="noreferrer">
						{ item.postTitle }
					</a>
				) : (
					<span className="gds-ct-glossary__post">{ item.postTitle }</span>
				) ) }
		</span>
	);
}

function UpdatedCell( { item }: { item: Term } ) {
	return item.updatedAt ? <time dateTime={ item.updatedAt }>{ dateI18n( getDateSettings().formats.date, item.updatedAt, undefined ) }</time> : null;
}

export function buildFields( glossary: GlossarySettings ): Field< Term >[] {
	const origins = originLabels();
	const sourceName = glossary.sourceLang;

	return [
		{
			id: 'source',
			/* translators: %s: language code, e.g. EN */
			label: sprintf( __( 'Source term (%s)', 'gds-content-translation' ), sourceName ),
			getValue: ( { item } ) => item.source,
			render: SourceCell,
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'target',
			label: __( 'Translation', 'gds-content-translation' ),
			getValue: ( { item } ) => item.target,
			render: TargetCell,
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'targetLang',
			label: __( 'Language', 'gds-content-translation' ),
			getValue: ( { item } ) => item.targetLang,
			render: ( { item } ) => <span title={ item.targetLang }>{ languageName( glossary, item.targetLang ) }</span>,
			elements: glossary.targetLangs.map( ( language ) => ( { value: language.code, label: language.name } ) ),
			// One language at a time: the server filters by one.
			filterBy: { operators: [ 'is' ] },
			enableSorting: false,
		},
		{
			id: 'note',
			label: __( 'Note', 'gds-content-translation' ),
			getValue: ( { item } ) => item.note,
			render: NoteCell,
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'origin',
			label: __( 'Origin', 'gds-content-translation' ),
			getValue: ( { item } ) => item.origin,
			render: OriginCell,
			elements: ( Object.keys( origins ) as TermOrigin[] ).map( ( origin ) => ( { value: origin, label: origins[ origin ] } ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
		},
		{
			id: 'hits',
			label: __( 'Times seen', 'gds-content-translation' ),
			type: 'integer',
			getValue: ( { item } ) => item.hits,
			filterBy: false,
		},
		{
			id: 'conflict',
			label: __( 'Conflicts', 'gds-content-translation' ),
			getValue: ( { item } ) => ( item.conflict ? 'yes' : '' ),
			render: ( { item } ) => ( item.conflict ? __( 'Two translations', 'gds-content-translation' ) : null ),
			elements: [ { value: 'yes', label: __( 'Two translations', 'gds-content-translation' ) } ],
			filterBy: { operators: [ 'is' ] },
			enableSorting: false,
		},
		{
			id: 'updatedAt',
			label: __( 'Updated', 'gds-content-translation' ),
			type: 'datetime',
			getValue: ( { item } ) => item.updatedAt,
			render: UpdatedCell,
			filterBy: false,
		},
	];
}
