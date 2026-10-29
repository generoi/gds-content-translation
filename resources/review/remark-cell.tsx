/**
 * The table's cells. The translation shows what the suggestion would change
 * in place; the remark says why, with its buttons right under it.
 */
import { Button } from '@wordpress/components';
import { useContext } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ReviewContext } from './context';
import type { RemarkRow, Severity } from './types';

export function severityLabel( severity: Severity | '' ): string {
	switch ( severity ) {
		case 'error':
			return __( 'Error', 'gds-content-translation' );
		case 'suggestion':
			return __( 'Suggestion', 'gds-content-translation' );
		case 'warning':
			return __( 'Warning', 'gds-content-translation' );
		default:
			return '';
	}
}

export function isOpen( row: RemarkRow ): boolean {
	return row.state === 'open';
}

/** The suggestion can be written into the translation from here. */
export function canUse( row: RemarkRow ): boolean {
	return isOpen( row ) && row.suggestion !== '' && ! row.changed;
}

export function SegmentCell( { item }: { item: RemarkRow } ) {
	return (
		<span className="gds-ct-review__segment">
			{ item.segment }
			{ item.label && <span className="gds-ct-review__field">{ item.label }</span> }
		</span>
	);
}

export function SourceCell( { item }: { item: RemarkRow } ) {
	return <div className="gds-ct-review__text" dangerouslySetInnerHTML={ { __html: item.source } } />;
}

export function TranslationCell( { item }: { item: RemarkRow } ) {
	const diff = isOpen( item ) && item.suggestionDiff !== '' && ! item.changed;

	return (
		<div className="gds-ct-review__text">
			{ /* Server-sanitised HTML: the translation, or it with the suggestion's changes marked. */ }
			<div className={ diff ? 'gds-ct-review__diff' : undefined } dangerouslySetInnerHTML={ { __html: diff ? item.suggestionDiff : item.translation } } />
			{ item.changed && <span className="gds-ct-review__tag">{ __( 'Changed since the review', 'gds-content-translation' ) }</span> }
		</div>
	);
}

export function RemarkCell( { item }: { item: RemarkRow } ) {
	const { run, edit, busy } = useContext( ReviewContext );

	if ( item.state === 'none' ) {
		return <span className="gds-ct-review__none">{ __( 'No remarks', 'gds-content-translation' ) }</span>;
	}

	const isBusy = busy.has( item.remarkId );
	const closed = ! isOpen( item );

	return (
		<div className={ `gds-ct-review__remark gds-ct-review__remark--${ item.severity }${ closed ? ' is-closed' : '' }` }>
			<p className="gds-ct-review__remark-head">
				<span className={ `gds-ct-review__severity gds-ct-review__severity--${ item.severity }` }>{ severityLabel( item.severity ) }</span>
				<span className="gds-ct-review__category">{ item.categoryLabel }</span>
				{ closed && <span className="gds-ct-review__tag">{ item.stateLabel }</span> }
			</p>
			<p className="gds-ct-review__issue">{ item.issue }</p>
			<div className="gds-ct-review__buttons">
				{ closed && (
					<Button size="small" variant="secondary" isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled onClick={ () => void run( 'reopen', [ item ] ) }>
						{ __( 'Reopen', 'gds-content-translation' ) }
					</Button>
				) }
				{ ! closed && canUse( item ) && (
					<Button size="small" variant="primary" isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled onClick={ () => void run( 'use', [ item ] ) }>
						{ __( 'Use suggestion', 'gds-content-translation' ) }
					</Button>
				) }
				{ ! closed && ! item.changed && (
					<Button size="small" variant="secondary" disabled={ isBusy } accessibleWhenDisabled onClick={ () => edit( item ) }>
						{ __( 'Edit…', 'gds-content-translation' ) }
					</Button>
				) }
				{ ! closed && item.changed && (
					<Button size="small" variant="primary" isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled onClick={ () => void run( 'fixed', [ item ] ) }>
						{ __( 'Mark as fixed', 'gds-content-translation' ) }
					</Button>
				) }
				{ ! closed && (
					<Button size="small" variant="tertiary" disabled={ isBusy } accessibleWhenDisabled onClick={ () => void run( 'dismiss', [ item ] ) }>
						{ __( 'Not an issue', 'gds-content-translation' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
