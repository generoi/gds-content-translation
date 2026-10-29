/**
 * Write the translation of one text by hand, starting from the suggestion,
 * with the original above for comparison.
 */
import { Button, Flex, Modal, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { RemarkRow } from './types';

interface Props {
	row: RemarkRow;
	sourceLanguage: string;
	onSave: ( text: string ) => Promise< boolean >;
	onClose: () => void;
}

export function EditModal( { row, sourceLanguage, onSave, onClose }: Props ) {
	const [ text, setText ] = useState( row.suggestion !== '' ? row.suggestion : row.translation );
	const [ isBusy, setIsBusy ] = useState( false );

	const save = () => {
		setIsBusy( true );
		void onSave( text ).then( ( ok ) => {
			setIsBusy( false );

			if ( ok ) {
				onClose();
			}
		} );
	};

	return (
		<Modal title={ __( 'Edit the translation', 'gds-content-translation' ) } onRequestClose={ onClose } size="medium">
			<p className="gds-ct-review__modal-label">
				{
					/* translators: %s: language name, e.g. Finnish */
					sprintf( __( 'Original (%s)', 'gds-content-translation' ), sourceLanguage )
				}
			</p>
			<div className="gds-ct-review__modal-source" dangerouslySetInnerHTML={ { __html: row.source } } />
			<p className="gds-ct-review__modal-issue">{ row.issue }</p>
			<TextareaControl __nextHasNoMarginBottom label={ __( 'Translation', 'gds-content-translation' ) } value={ text } onChange={ setText } rows={ 5 } />
			<Flex justify="flex-end" style={ { marginTop: 16 } }>
				<Button variant="tertiary" onClick={ onClose } disabled={ isBusy }>
					{ __( 'Cancel', 'gds-content-translation' ) }
				</Button>
				<Button variant="primary" onClick={ save } isBusy={ isBusy } disabled={ isBusy || text.trim() === '' } accessibleWhenDisabled>
					{ __( 'Save to translation', 'gds-content-translation' ) }
				</Button>
			</Flex>
		</Modal>
	);
}
