/**
 * DeepL's side of the glossary in one line: when the terms were last sent
 * and how many, that changes are on their way, or what went wrong, with a
 * button to send them now. Without DeepL, says only the AI review uses it.
 */
import { Button } from '@wordpress/components';
import { humanTimeDiff } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, caution, check, cloudUpload, info } from '@wordpress/icons';
import type { Sync } from './types';

interface Props {
	sync: Sync | null;
	isSending: boolean;
	onSend: () => void;
}

function sentLine( sync: Sync ): string {
	const entries = sync.dictionaries.reduce( ( sum, dictionary ) => sum + dictionary.entries, 0 );
	const languages = sync.dictionaries.length;
	/* translators: %s: number of terms */
	const terms = sprintf( _n( '%s term', '%s terms', entries, 'gds-content-translation' ), entries.toLocaleString() );
	/* translators: %d: number of languages */
	const pairs = sprintf( _n( '%d language', '%d languages', languages, 'gds-content-translation' ), languages );

	/* translators: 1: how long ago, e.g. 5 minutes ago, 2: e.g. 2,560 terms, 3: e.g. 5 languages */
	return sprintf( __( 'Sent to DeepL %1$s: %2$s in %3$s', 'gds-content-translation' ), humanTimeDiff( sync.syncedAt ), terms, pairs );
}

export function SyncStatus( { sync, isSending, onSend }: Props ) {
	if ( ! sync ) {
		return <p className="gds-ct-glossary__sync" aria-busy="true" />;
	}

	if ( ! sync.configured ) {
		return (
			<p className="gds-ct-glossary__sync">
				<Icon icon={ info } size={ 20 } />
				<span>{ __( 'DeepL is not set up in Polylang, so only the AI review uses the glossary.', 'gds-content-translation' ) }</span>
			</p>
		);
	}

	let icon = check;
	let text: string;
	let className = 'gds-ct-glossary__sync';

	if ( isSending ) {
		icon = cloudUpload;
		text = __( 'Sending to DeepL…', 'gds-content-translation' );
	} else if ( sync.error ) {
		icon = caution;
		text = sync.error;
		className += ' is-error';
	} else if ( sync.pending ) {
		icon = cloudUpload;
		text = __( 'Changes are sent to DeepL within a minute.', 'gds-content-translation' );
	} else if ( sync.syncedAt ) {
		text = sentLine( sync );
	} else {
		icon = info;
		text = __( 'Not sent to DeepL yet.', 'gds-content-translation' );
	}

	return (
		<p className={ className } role="status">
			<Icon icon={ icon } size={ 20 } />
			<span>{ text }</span>
			<Button variant={ sync.error ? 'secondary' : 'tertiary' } size="small" onClick={ onSend } isBusy={ isSending } disabled={ isSending } accessibleWhenDisabled>
				{ sync.error ? __( 'Try again', 'gds-content-translation' ) : __( 'Send to DeepL now', 'gds-content-translation' ) }
			</Button>
		</p>
	);
}
