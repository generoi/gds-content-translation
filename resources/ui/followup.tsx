/**
 * The three ways to start an AI translation when the AI review is on: what
 * happens with the review's suggestions. Shared by the cell's AI translate
 * menu and the bulk AI translation dialog, so both look and read the same.
 * Titles and help lines come from PHP (TranslationAudit::followupOptions).
 */
import { Button, MenuGroup, MenuItem, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { check, pencil, update } from '@wordpress/icons';
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

/** The cell's menu: one item per way, the site default tagged. */
export function FollowupMenu( { onChoose }: { onChoose: ( followup: Followup, instructions: string ) => void } ) {
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
