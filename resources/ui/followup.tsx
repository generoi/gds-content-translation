/**
 * The three ways to start an AI translation when the AI review is on: what
 * happens with the review's suggestions. Shared by the cell's AI translate
 * menu and the bulk AI translation dialog, so both look and read the same.
 * Titles and help lines come from PHP (TranslationAudit::followupOptions).
 */
import { Button, MenuGroup, MenuItem, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { check, Icon, info, pencil, update } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import type { ReactElement } from 'react';
import { getSettings } from '../settings';
import type { Followup } from '../settings';

export const FOLLOWUP_ICONS: Record< Followup, ReactElement > = {
	manual: pencil,
	apply: check,
	apply_review: update,
};

function followups(): Followup[] {
	return Object.keys( getSettings().followups ) as Followup[];
}

function DefaultTag() {
	return <span className="gds-ct__default-tag">{ __( 'Default', 'gds-content-translation' ) }</span>;
}

/**
 * Optional instructions for the AI review of the translations about to be
 * created, on top of the site's (Content translation settings). Folded away
 * behind a link until wanted.
 */
export function InstructionsField( { value, onChange }: { value: string; onChange: ( value: string ) => void } ) {
	const settings = getSettings();
	const [ open, setOpen ] = useState( value !== '' );

	if ( ! open ) {
		return (
			<Button variant="link" className="gds-ct__instructions-toggle" onClick={ () => setOpen( true ) }>
				{ __( 'Add instructions for the AI review (optional)', 'gds-content-translation' ) }
			</Button>
		);
	}

	return (
		<TextareaControl
			className="gds-ct__instructions"
			__nextHasNoMarginBottom
			label={ __( 'Instructions for the AI review (optional)', 'gds-content-translation' ) }
			help={
				settings.instructions
					? __( 'Added to the site’s instructions from the settings, for these translations only.', 'gds-content-translation' )
					: __( 'For these translations only: terms, names to keep, tone.', 'gds-content-translation' )
			}
			placeholder={ __( 'For example: this page is for distributors, use a formal tone.', 'gds-content-translation' ) }
			value={ value }
			onChange={ onChange }
			rows={ 3 }
			maxLength={ settings.instructionsMax }
		/>
	);
}

/**
 * Whether the translations about to be created have a glossary: how many
 * terms DeepL and the AI review follow, with links to edit or replace it,
 * or to start one.
 */
export function GlossaryNote( { slugs }: { slugs: string[] } ) {
	const settings = getSettings();
	const glossary = settings.glossary;

	if ( ! glossary?.url || ! slugs.length ) {
		return null;
	}

	const name = ( slug: string ) => settings.languages.find( ( language ) => language.slug === slug )?.name ?? slug;
	const terms = ( slug: string ) => glossary.languages[ slug ]?.terms ?? 0;
	const without = slugs.filter( ( slug ) => terms( slug ) === 0 );
	const total = slugs.reduce( ( sum, slug ) => sum + terms( slug ), 0 );
	const [ first = '' ] = slugs;
	const single = slugs.length === 1 ? glossary.languages[ first ]?.code ?? '' : '';
	const link = ( args: Record< string, string > ) => addQueryArgs( glossary.url, { ...( single ? { target_lang: single } : {} ), ...args } );

	let title: string;

	if ( total === 0 ) {
		title =
			slugs.length === 1
				? /* translators: %s: language name */
				  sprintf( __( 'No glossary for %s', 'gds-content-translation' ), name( first ) )
				: __( 'No glossary for these languages', 'gds-content-translation' );
	} else if ( slugs.length === 1 ) {
		/* translators: 1: number of terms, 2: language name */
		title = sprintf( _n( 'Glossary in use: %1$s term for %2$s', 'Glossary in use: %1$s terms for %2$s', total, 'gds-content-translation' ), total.toLocaleString(), name( first ) );
	} else if ( without.length ) {
		/* translators: %s: language names */
		title = sprintf( __( 'Glossary in use, but none for %s', 'gds-content-translation' ), without.map( name ).join( ', ' ) );
	} else {
		/* translators: %d: number of languages */
		title = sprintf( _n( 'Glossary in use for %d language', 'Glossary in use for %d languages', slugs.length, 'gds-content-translation' ), slugs.length );
	}

	let help = '';

	if ( total > 0 ) {
		help = glossary.deepl ? __( 'DeepL and the AI review follow it.', 'gds-content-translation' ) : __( 'The AI review follows it.', 'gds-content-translation' );
	}

	return (
		<div className={ `gds-ct__glossary-note${ total === 0 ? ' is-empty' : '' }` }>
			<Icon icon={ total > 0 ? check : info } size={ 20 } />
			<div>
				<span className="gds-ct__glossary-note-title">{ title }</span>
				{ help && <span className="gds-ct__glossary-note-help">{ help }</span> }
				<span className="gds-ct__glossary-note-links">
					<a href={ link( {} ) } target="_blank" rel="noreferrer">
						{ total > 0 ? __( 'Edit glossary', 'gds-content-translation' ) : __( 'Add terms', 'gds-content-translation' ) }
					</a>
					<a href={ link( { import: '1' } ) } target="_blank" rel="noreferrer">
						{ total > 0 ? __( 'Replace with a CSV', 'gds-content-translation' ) : __( 'Import a CSV', 'gds-content-translation' ) }
					</a>
				</span>
			</div>
		</div>
	);
}

/** The cell's menu: one item per way, the site default tagged. */
export function FollowupMenu( { slug, onChoose }: { slug: string; onChoose: ( followup: Followup, instructions: string ) => void } ) {
	const settings = getSettings();
	const [ instructions, setInstructions ] = useState( '' );

	return (
		<>
			<MenuGroup className="gds-ct__followup-menu" label={ __( 'Start AI translation', 'gds-content-translation' ) }>
				{ followups().map( ( followup ) => (
					<MenuItem
						key={ followup }
						className="gds-ct__followup-item"
						icon={ FOLLOWUP_ICONS[ followup ] }
						iconPosition="left"
						info={ settings.followups[ followup ].description }
						onClick={ () => onChoose( followup, instructions.trim() ) }
					>
						<span className="gds-ct__followup-title">{ settings.followups[ followup ].label }</span>
						{ followup === settings.followup && <DefaultTag /> }
					</MenuItem>
				) ) }
			</MenuGroup>
			<div className="gds-ct__followup-instructions">
				<InstructionsField value={ instructions } onChange={ setInstructions } />
			</div>
			<GlossaryNote slugs={ [ slug ] } />
		</>
	);
}

/** The bulk dialog's choice: the same ways as radio cards. */
export function FollowupRadios( { selected, onChange }: { selected: Followup; onChange: ( followup: Followup ) => void } ) {
	const settings = getSettings();

	return (
		<fieldset className="gds-ct__followup-radios">
			<legend>{ __( 'How to AI translate', 'gds-content-translation' ) }</legend>
			{ followups().map( ( followup ) => (
				<label key={ followup } className="gds-ct__followup-card">
					<input
						type="radio"
						name="gds-ct-followup"
						value={ followup }
						checked={ selected === followup }
						onChange={ () => onChange( followup ) }
						aria-describedby={ `gds-ct-followup-help-${ followup }` }
					/>
					<span className="gds-ct__followup-icon" aria-hidden="true">
						{ FOLLOWUP_ICONS[ followup ] }
					</span>
					<span className="gds-ct__followup-text">
						<span className="gds-ct__followup-title">
							{ settings.followups[ followup ].label }
							{ followup === settings.followup && <DefaultTag /> }
						</span>
						<span id={ `gds-ct-followup-help-${ followup }` } className="gds-ct__followup-help">
							{ settings.followups[ followup ].description }
						</span>
					</span>
				</label>
			) ) }
		</fieldset>
	);
}
