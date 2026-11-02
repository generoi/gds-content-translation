/**
 * What can be done with terms, one or the selection: a suggestion is
 * accepted or rejected, any term edited, moved between in use and drafts,
 * or deleted (after a question in a dialog).
 */
import { Button, Flex } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { check, closeSmall, drafts, pencil, published, trash } from '@wordpress/icons';
import type { Action, RenderModalProps } from '../dataviews';
import type { BulkOperation, Term } from './types';

export interface TermOps {
	/** Resolves true when the change went through. */
	bulk: ( terms: Term[], operation: BulkOperation ) => Promise< boolean >;
	edit: ( term: Term ) => void;
}

function DeleteModal( { items, closeModal, onActionPerformed, ops }: RenderModalProps< Term > & { ops: TermOps } ) {
	const [ isBusy, setIsBusy ] = useState( false );
	const first = items[ 0 ];

	const question =
		items.length === 1 && first
			? /* translators: 1: source term, 2: its translation */
			  sprintf( __( 'Delete “%1$s” → “%2$s”?', 'gds-content-translation' ), first.source, first.target )
			: /* translators: %d: number of terms */
			  sprintf( _n( 'Delete %d term?', 'Delete %d terms?', items.length, 'gds-content-translation' ), items.length );

	const submit = () => {
		setIsBusy( true );
		void ops.bulk( items, 'delete' ).then( ( ok ) => {
			if ( ok ) {
				onActionPerformed?.( items );
				closeModal?.();
			} else {
				setIsBusy( false );
			}
		} );
	};

	return (
		<div className="gds-ct__modal">
			<p>{ question }</p>
			<p>{ __( 'This cannot be undone. To keep a term out of use but at hand, move it to the drafts instead.', 'gds-content-translation' ) }</p>
			<Flex justify="flex-end" className="gds-ct__modal-buttons">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isBusy }>
					{ __( 'Cancel', 'gds-content-translation' ) }
				</Button>
				<Button variant="primary" isDestructive onClick={ submit } isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled>
					{ __( 'Delete', 'gds-content-translation' ) }
				</Button>
			</Flex>
		</div>
	);
}

export function buildActions( ops: TermOps ): Action< Term >[] {
	const is = ( ...statuses: Term[ 'status' ][] ) => ( term: Term ) => statuses.includes( term.status );

	return [
		{
			id: 'accept',
			label: __( 'Accept', 'gds-content-translation' ),
			icon: check,
			isPrimary: true,
			supportsBulk: true,
			isEligible: is( 'suggested' ),
			callback: ( items ) => void ops.bulk( items, 'approve' ),
		},
		{
			id: 'edit',
			label: __( 'Edit…', 'gds-content-translation' ),
			icon: pencil,
			callback: ( items ) => items[ 0 ] && ops.edit( items[ 0 ] ),
		},
		{
			id: 'reject',
			label: __( 'Reject', 'gds-content-translation' ),
			icon: closeSmall,
			supportsBulk: true,
			isEligible: is( 'suggested' ),
			callback: ( items ) => void ops.bulk( items, 'reject' ),
		},
		{
			id: 'approve',
			label: __( 'Approve', 'gds-content-translation' ),
			icon: published,
			supportsBulk: true,
			isEligible: is( 'draft', 'rejected' ),
			callback: ( items ) => void ops.bulk( items, 'approve' ),
		},
		{
			id: 'draft',
			label: __( 'Move to drafts', 'gds-content-translation' ),
			icon: drafts,
			supportsBulk: true,
			isEligible: is( 'approved', 'rejected' ),
			callback: ( items ) => void ops.bulk( items, 'draft' ),
		},
		{
			id: 'delete',
			label: __( 'Delete…', 'gds-content-translation' ),
			icon: trash,
			modalHeader: __( 'Delete terms', 'gds-content-translation' ),
			modalSize: 'small',
			supportsBulk: true,
			isEligible: is( 'approved', 'draft', 'rejected' ),
			RenderModal: ( props ) => <DeleteModal { ...props } ops={ ops } />,
		},
	];
}
