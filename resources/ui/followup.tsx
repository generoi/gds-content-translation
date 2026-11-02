/**
 * The three ways to start an AI translation when the AI review is on: what
 * happens with the review's suggestions. Shared by the cell's AI translate
 * menu and the bulk AI translation dialog, so both look and read the same.
 * Titles and help lines come from PHP (TranslationAudit::followupOptions).
 */
import { MenuGroup, MenuItem } from '@wordpress/components';
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

/** The cell's menu: one item per way, the site default tagged. */
export function FollowupMenu( { onChoose }: { onChoose: ( followup: Followup ) => void } ) {
	const settings = getSettings();

	return (
		<MenuGroup className="gds-ct__followup-menu" label={ __( 'Start AI translation', 'gds-content-translation' ) }>
			{ followups().map( ( followup ) => (
				<MenuItem
					key={ followup }
					className="gds-ct__followup-item"
					icon={ FOLLOWUP_ICONS[ followup ] }
					iconPosition="left"
					info={ settings.followups[ followup ].description }
					onClick={ () => onChoose( followup ) }
				>
					<span className="gds-ct__followup-title">{ settings.followups[ followup ].label }</span>
					{ followup === settings.followup && <DefaultTag /> }
				</MenuItem>
			) ) }
		</MenuGroup>
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
