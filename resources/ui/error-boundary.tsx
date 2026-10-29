/**
 * Keeps a rendering error where it happened (one cell, the progress panel)
 * instead of unmounting the whole screen.
 */
import { Button, Notice } from '@wordpress/components';
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

interface Props {
	children?: ReactNode;
	/** Where it failed, for the console. */
	context: string;
	/** What to show instead; an inline error with "Try again" by default. */
	fallback?: ReactNode;
}

interface State {
	error: Error | null;
}

export class ErrorBoundary extends Component< Props, State > {
	override state: State = { error: null };

	static getDerivedStateFromError( error: unknown ): State {
		return { error: error instanceof Error ? error : new Error( String( error ) ) };
	}

	override componentDidCatch( error: unknown ): void {
		console.error( `[gds-content-translation] ${ this.props.context }`, error );
	}

	override render(): ReactNode {
		if ( ! this.state.error ) {
			return this.props.children ?? null;
		}

		if ( this.props.fallback !== undefined ) {
			return this.props.fallback;
		}

		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Something went wrong while showing this part of the translation status.', 'gds-content-translation' ) }{ ' ' }
				<Button variant="link" onClick={ () => this.setState( { error: null } ) }>
					{ __( 'Try again', 'gds-content-translation' ) }
				</Button>
			</Notice>
		);
	}
}
