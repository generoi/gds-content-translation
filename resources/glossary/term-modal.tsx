/**
 * Add a term, or edit one: its language, the source term, the translation,
 * a note for the editors, and whether it is in use. A suggestion shows where
 * it was seen. A refused save (such as a duplicate) is told in the dialog.
 */
import { Button, Flex, Modal, Notice, SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import type { GlossarySettings } from '../settings';
import { saveTerm } from './api';
import { originLabels, statusLabels } from './fields';
import type { Term, TermStatus } from './types';

const STATUSES: TermStatus[] = [ 'approved', 'suggested', 'draft', 'rejected' ];

interface Props {
	glossary: GlossarySettings;
	/** The term to edit; null adds one. */
	term: Term | null;
	/** A new term's language: the one the table is filtered to, if any. */
	targetLang: string;
	onSaved: ( term: Term ) => void;
	onClose: () => void;
}

export function TermModal( { glossary, term, targetLang, onSaved, onClose }: Props ) {
	const labels = statusLabels();
	const [ language, setLanguage ] = useState( term?.targetLang ?? ( targetLang || glossary.targetLangs[ 0 ]?.code || '' ) );
	const [ source, setSource ] = useState( term?.source ?? '' );
	const [ target, setTarget ] = useState( term?.target ?? '' );
	const [ note, setNote ] = useState( term?.note ?? '' );
	const [ status, setStatus ] = useState< TermStatus >( term?.status ?? 'approved' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const save = () => {
		setIsBusy( true );
		setError( '' );
		saveTerm( term?.id ?? null, { targetLang: language, source: source.trim(), target: target.trim(), note: note.trim(), status } )
			.then( ( saved ) => {
				onSaved( saved );
				onClose();
			} )
			.catch( ( caught: unknown ) => {
				setIsBusy( false );
				setError( errorMessage( caught, __( 'The term could not be saved. Check your connection and try again.', 'gds-content-translation' ) ) );
			} );
	};

	const canSave = ! isBusy && source.trim() !== '' && target.trim() !== '' && language !== '';

	return (
		<Modal title={ term ? __( 'Edit term', 'gds-content-translation' ) : __( 'Add term', 'gds-content-translation' ) } onRequestClose={ onClose } size="medium" className="gds-ct-glossary__modal">
			<form
				onSubmit={ ( event ) => {
					event.preventDefault();

					if ( canSave ) {
						save();
					}
				} }
			>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ term && ( term.origin === 'review' || term.origin === 'proofread' ) && ( term.example || term.postTitle ) && (
					<div className="gds-ct-glossary__context">
						<p className="gds-ct-review__modal-label">
							{
								/* translators: %s: where the term came from, e.g. AI review */
								sprintf( __( 'Suggested by: %s', 'gds-content-translation' ), originLabels()[ term.origin ] )
							}
						</p>
						{ term.example && <q className="gds-ct-glossary__example">{ term.example }</q> }
						{ term.postTitle && term.postEditUrl && (
							<a href={ term.postEditUrl } target="_blank" rel="noreferrer">
								{ term.postTitle }
							</a>
						) }
					</div>
				) }
				<div className="gds-ct-glossary__form">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Language', 'gds-content-translation' ) }
						value={ language }
						options={ glossary.targetLangs.map( ( item ) => ( { value: item.code, label: item.name } ) ) }
						onChange={ setLanguage }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						/* translators: %s: language code, e.g. EN */
						label={ sprintf( __( 'Source term (%s)', 'gds-content-translation' ), glossary.sourceLang ) }
						value={ source }
						onChange={ setSource }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						/* translators: %s: language code, e.g. SV */
						label={ sprintf( __( 'Translation (%s)', 'gds-content-translation' ), language ) }
						help={ __( 'Write it as it should appear in a sentence. Case matters to DeepL.', 'gds-content-translation' ) }
						value={ target }
						onChange={ setTarget }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Note (optional)', 'gds-content-translation' ) }
						help={ __( 'For editors and the AI review. DeepL does not see it.', 'gds-content-translation' ) }
						value={ note }
						onChange={ setNote }
						rows={ 2 }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Status', 'gds-content-translation' ) }
						help={ __( 'Only terms in use are sent to DeepL and the AI review.', 'gds-content-translation' ) }
						value={ status }
						options={ STATUSES.filter( ( item ) => item !== 'suggested' || term?.status === 'suggested' ).map( ( item ) => ( { value: item, label: labels[ item ] } ) ) }
						onChange={ ( value ) => setStatus( value as TermStatus ) }
					/>
				</div>
				<Flex justify="flex-end" className="gds-ct__modal-buttons">
					<Button variant="tertiary" onClick={ onClose } disabled={ isBusy }>
						{ __( 'Cancel', 'gds-content-translation' ) }
					</Button>
					<Button variant="primary" type="submit" isBusy={ isBusy } disabled={ ! canSave } accessibleWhenDisabled>
						{ term ? __( 'Save', 'gds-content-translation' ) : __( 'Add term', 'gds-content-translation' ) }
					</Button>
				</Flex>
			</form>
		</Modal>
	);
}
