/**
 * The files, images and forms a translation still shows in the original's
 * language, with what each is and what to do about it:
 *
 * - use another file, image, form or address in the translation;
 * - translate an SVG's labels (DeepL), and correct them;
 * - have DeepL draft a document, after its cost is shown, and use the draft;
 * - translate a form (Form translations);
 * - for a query loop whose chosen posts have no translation, or a link to a
 *   page that has none: AI translate them, then have the loop show them;
 * - for a text still to be written ("[TÄYTÄ]"): write it, in the editor;
 * - or keep it: fine as it is.
 *
 * Translated copies stay listed, to correct or undo them. The status screen
 * shows the list in a modal, the AI review page above its remarks.
 */
import { Button, ExternalLink, Flex, Modal, SelectControl, Spinner, TextControl, __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage, runAction } from '../api';
import { saveAlts, translateAlts } from '../strings/api';
import { notifyError, notifySuccess } from '../ui/notify';
import { fetchMedia, markMedia, runMediaAction } from './api';
import { pickMedia } from './pick-media';
import type { ChosenPost, DocumentCost, MediaData, MediaItem, MediaKind } from './types';

const order: MediaKind[] = [ 'placeholder', 'query', 'document', 'form', 'svg', 'image', 'embed', 'link', 'page' ];

function kindLabel( kind: MediaKind ): string {
	return {
		document: __( 'File', 'gds-content-translation' ),
		svg: __( 'Image with text', 'gds-content-translation' ),
		image: __( 'Image with text', 'gds-content-translation' ),
		form: __( 'Form', 'gds-content-translation' ),
		embed: __( 'Embed', 'gds-content-translation' ),
		link: __( 'Link', 'gds-content-translation' ),
		query: __( 'List of posts', 'gds-content-translation' ),
		page: __( 'Link to a page', 'gds-content-translation' ),
		placeholder: __( 'Text to write', 'gds-content-translation' ),
	}[ kind ];
}

/** What the item is and what to do, in one line. */
function hint( item: MediaItem, data: MediaData ): string {
	const source = data.sourceLanguage;

	if ( item.state === 'translated' ) {
		switch ( item.kind ) {
			case 'svg':
				return item.current === false
					? __( 'A translated copy. The original illustration has changed since: update the copy.', 'gds-content-translation' )
					: __( 'A translated copy of the illustration. Check its labels.', 'gds-content-translation' );
			case 'form':
				// Its progress line says it.
				return '';
			default:
				return __( 'A translated copy of the original’s file.', 'gds-content-translation' );
		}
	}

	if ( item.state === 'not-checked' ) {
		switch ( item.notChecked ) {
			case 'too-large':
				return __( 'Claude could not check the image for text: its file is larger than 3.5 MB. Look at it yourself: use a translated version of the image, or keep this one.', 'gds-content-translation' );
			case 'unreadable':
				return __( 'Claude could not check the image for text: its file could not be read here (it may be kept elsewhere). Look at it yourself: use a translated version of the image, or keep this one.', 'gds-content-translation' );
			default:
				return __( 'Claude could not check the image for text. It is checked again later; meanwhile, look at it yourself.', 'gds-content-translation' );
		}
	}

	switch ( item.kind ) {
		case 'document':
			/* translators: %s: language name of the original, e.g. Finnish */
			return sprintf( __( 'The same file as on the %s page. Link a translated file in the translation, or keep this one.', 'gds-content-translation' ), source );
		case 'svg':
			/* translators: %s: language name of the original, e.g. Finnish */
			return sprintf( __( 'The illustration’s labels are in %s.', 'gds-content-translation' ), source );
		case 'image':
			return __( 'The AI review found words in the image. Use a translated version of the image, or keep this one.', 'gds-content-translation' );
		case 'form':
			/* translators: %s: language name of the original, e.g. Finnish */
			return sprintf( __( 'The form’s fields, buttons and messages are shown in %s.', 'gds-content-translation' ), source );
		case 'embed':
			return __( 'The same video or embed as on the original. Check that it suits this language.', 'gds-content-translation' );
		case 'link':
			/* translators: %s: language name of the original, e.g. Finnish */
			return sprintf( __( 'Probably a page in %s on another site. Link its version in this language, if it has one.', 'gds-content-translation' ), source );
		case 'query':
			return item.posts?.some( ( post ) => post.translationId > 0 )
				? __( 'The list does not show the chosen posts below. Some are translated now: update the list to show them, and translate the others.', 'gds-content-translation' )
				: sprintf(
						/* translators: %s: language name of the translation, e.g. Swedish */
						__( 'The list shows only posts in %s, and the chosen posts below have no translation, so the section shows fewer posts or none. Translate them, then update the list.', 'gds-content-translation' ),
						data.targetLanguage
				  );
		case 'page':
			return item.translationId
				? sprintf(
						/* translators: 1: language name of the translation, e.g. Swedish, 2: language name of the original, e.g. Finnish */
						__( 'The %1$s version of this page is a draft, so links to it still lead to the %2$s page. They change by themselves once it is published.', 'gds-content-translation' ),
						data.targetLanguage,
						source
				  )
				: sprintf(
						/* translators: 1: language name of the translation, e.g. Swedish, 2: language name of the original, e.g. Finnish */
						__( 'This page has no %1$s version yet, so links to it lead to the %2$s page. Once it is translated, they go to the translation by themselves.', 'gds-content-translation' ),
						data.targetLanguage,
						source
				  );
		case 'placeholder':
			return item.inSource
				? sprintf(
						/* translators: %s: language name of the original, e.g. Finnish */
						__( 'The %s original has a text still to be written. The translation may have it in other words: write it in the original, then in the translation.', 'gds-content-translation' ),
						source
				  )
				: __( 'A text still to be written. Write it in the translation in the editor, and in the original if it is there too.', 'gds-content-translation' );
	}
}

function Texts( { texts }: { texts: string[] } ) {
	const shown = texts.slice( 0, 6 );

	return (
		<ul className="gds-ct-media__texts">
			{ shown.map( ( text ) => (
				<li key={ text }>{ text }</li>
			) ) }
			{ texts.length > shown.length && (
				<li className="gds-ct-media__more">
					{
						/* translators: %d: number of other texts */
						sprintf( _n( '+%d more', '+%d more', texts.length - shown.length, 'gds-content-translation' ), texts.length - shown.length )
					}
				</li>
			) }
		</ul>
	);
}

function euros( value: number ): string {
	return new Intl.NumberFormat( undefined, { style: 'currency', currency: 'EUR' } ).format( value );
}

/** What a DeepL document costs, said before it is asked for. */
function costText( cost: DocumentCost, reused: boolean, target: string ): string[] {
	if ( reused ) {
		/* translators: %s: language name, e.g. English */
		return [ sprintf( __( 'A DeepL draft of this file in %s was made for another translation. It is used again, at no cost.', 'gds-content-translation' ), target ) ];
	}

	const characters = cost.characters.toLocaleString();
	const lines: string[] = [
		cost.free
			? sprintf(
					/* translators: %s: number of characters */
					__( 'DeepL counts every file as at least %s characters of the free plan’s monthly characters, however short the file is.', 'gds-content-translation' ),
					characters
			  )
			: sprintf(
					/* translators: 1: number of characters, 2: price, e.g. €1.00 */
					__( 'DeepL bills every file as at least %1$s characters, about %2$s, however short the file is. A longer file costs more. Translating a page usually costs a few cents.', 'gds-content-translation' ),
					characters,
					euros( cost.price )
			  ),
	];

	if ( cost.limit > 0 ) {
		lines.push(
			sprintf(
				/* translators: 1: characters used, 2: characters in the plan */
				__( 'This period, %1$s of the plan’s %2$s characters are used.', 'gds-content-translation' ),
				cost.used.toLocaleString(),
				cost.limit.toLocaleString()
			)
		);
	}

	lines.push( __( 'DeepL keeps the layout as well as it can, but a designed PDF can break: the result is a draft, used only once you have looked at it.', 'gds-content-translation' ) );

	return lines;
}

function LabelsModal( { item, onSave, onClose }: { item: MediaItem; onSave: ( labels: Record< string, string > ) => Promise< boolean >; onClose: () => void } ) {
	const [ labels, setLabels ] = useState< Record< string, string > >( () => Object.fromEntries( ( item.labels ?? [] ).map( ( label ) => [ label.source, label.translation ] ) ) );
	const [ isBusy, setIsBusy ] = useState( false );

	const save = () => {
		setIsBusy( true );
		void onSave( labels ).then( ( ok ) => {
			setIsBusy( false );

			if ( ok ) {
				onClose();
			}
		} );
	};

	return (
		<Modal title={ __( 'Labels of the illustration', 'gds-content-translation' ) } onRequestClose={ onClose } size="medium">
			<p className="gds-ct-media__intro">{ __( 'The copy of the illustration shows these. A label changed here changes in every translation that shows the copy.', 'gds-content-translation' ) }</p>
			<div className="gds-ct-media__labels">
				{ ( item.labels ?? [] ).map( ( label ) => (
					<TextControl
						key={ label.source }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label.source }
						value={ labels[ label.source ] ?? '' }
						onChange={ ( value ) => setLabels( { ...labels, [ label.source ]: value } ) }
					/>
				) ) }
			</div>
			<Flex justify="flex-end" style={ { marginTop: 16 } }>
				<Button variant="tertiary" onClick={ onClose } disabled={ isBusy }>
					{ __( 'Cancel', 'gds-content-translation' ) }
				</Button>
				<Button variant="primary" onClick={ save } isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled>
					{ __( 'Save labels', 'gds-content-translation' ) }
				</Button>
			</Flex>
		</Modal>
	);
}

/** An address typed in place, for a link or an embed. */
function UrlForm( { item, onSave, onCancel }: { item: MediaItem; onSave: ( url: string ) => void; onCancel: () => void } ) {
	const [ url, setUrl ] = useState( item.url );

	return (
		<form
			className="gds-ct-media__inline"
			onSubmit={ ( event ) => {
				event.preventDefault();
				onSave( url );
			} }
		>
			<TextControl __next40pxDefaultSize __nextHasNoMarginBottom type="url" label={ __( 'New address', 'gds-content-translation' ) } value={ url } onChange={ setUrl } />
			<Button variant="primary" type="submit" disabled={ url.trim() === '' || url === item.url } accessibleWhenDisabled>
				{ __( 'Use', 'gds-content-translation' ) }
			</Button>
			<Button variant="tertiary" onClick={ onCancel }>
				{ __( 'Cancel', 'gds-content-translation' ) }
			</Button>
		</form>
	);
}

function FormChoice( { item, forms, onSave, onCancel }: { item: MediaItem; forms: MediaData[ 'forms' ]; onSave: ( formId: number ) => void; onCancel: () => void } ) {
	const [ formId, setFormId ] = useState( String( item.formId ?? '' ) );

	return (
		<div className="gds-ct-media__inline">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Show this form instead', 'gds-content-translation' ) }
				value={ formId }
				options={ forms.map( ( form ) => ( { value: String( form.id ), label: form.title } ) ) }
				onChange={ setFormId }
			/>
			<Button variant="primary" onClick={ () => onSave( Number( formId ) ) } disabled={ formId === String( item.formId ) } accessibleWhenDisabled>
				{ __( 'Use', 'gds-content-translation' ) }
			</Button>
			<Button variant="tertiary" onClick={ onCancel }>
				{ __( 'Cancel', 'gds-content-translation' ) }
			</Button>
		</div>
	);
}

/**
 * The image's alt text in the translation's language, when Polylang does
 * not translate media: one image, one media library alt text, in the
 * original's language, unless the language has its own.
 */
function AltText( { item, data }: { item: MediaItem; data: MediaData } ) {
	const [ alt, setAlt ] = useState( item.alt?.translation ?? '' );
	const [ editing, setEditing ] = useState< string | null >( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const id = item.attachmentId ?? 0;

	if ( ! item.alt || ! id || ! data.targetSlug ) {
		return null;
	}

	const done = ( message: string ) => ( result: { alts: Record< string, string > } ) => {
		setAlt( result.alts[ String( id ) ] ?? alt );
		setEditing( null );
		notifySuccess( message );
	};

	const fail = ( caught: unknown ) => notifyError( errorMessage( caught, __( 'That did not work. Try again.', 'gds-content-translation' ) ) );

	const translate = () => {
		setIsBusy( true );
		translateAlts( data.targetSlug, [ id ] )
			.then( done( __( 'The alt text is translated.', 'gds-content-translation' ) ) )
			.catch( fail )
			.finally( () => setIsBusy( false ) );
	};

	const save = ( value: string ) => {
		setIsBusy( true );
		saveAlts( data.targetSlug, { [ String( id ) ]: value } )
			.then( done( __( 'The alt text is saved.', 'gds-content-translation' ) ) )
			.catch( fail )
			.finally( () => setIsBusy( false ) );
	};

	return (
		<div className="gds-ct-media__alt">
			<p className="gds-ct-media__hint">
				{ alt
					? sprintf(
							/* translators: 1: language name, e.g. English, 2: the alt text */
							__( 'Alt text in %1$s: %2$s', 'gds-content-translation' ),
							data.targetLanguage,
							alt
					  )
					: sprintf(
							/* translators: 1: language name, e.g. English, 2: language name of the original, 3: the alt text */
							__( 'No alt text in %1$s: the %2$s one is shown, “%3$s”.', 'gds-content-translation' ),
							data.targetLanguage,
							data.sourceLanguage,
							item.alt.source
					  ) }
			</p>
			{ editing !== null ? (
				<form
					className="gds-ct-media__inline"
					onSubmit={ ( event ) => {
						event.preventDefault();
						save( editing );
					} }
				>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						/* translators: %s: language name, e.g. English */
						label={ sprintf( __( 'Alt text in %s', 'gds-content-translation' ), data.targetLanguage ) }
						value={ editing }
						onChange={ setEditing }
					/>
					<Button variant="primary" type="submit" isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled>
						{ __( 'Save', 'gds-content-translation' ) }
					</Button>
					<Button variant="tertiary" onClick={ () => setEditing( null ) }>
						{ __( 'Cancel', 'gds-content-translation' ) }
					</Button>
				</form>
			) : (
				<div className="gds-ct-media__actions">
					{ ! alt && item.alt.source && data.canTranslateAlts && (
						<Button variant="secondary" size="compact" isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled onClick={ translate }>
							{ __( 'Translate the alt text', 'gds-content-translation' ) }
						</Button>
					) }
					<Button variant="tertiary" size="compact" disabled={ isBusy } accessibleWhenDisabled onClick={ () => setEditing( alt || item.alt?.source || '' ) }>
						{ __( 'Edit the alt text…', 'gds-content-translation' ) }
						<span className="screen-reader-text">{ `: ${ item.title }` }</span>
					</Button>
				</div>
			) }
		</div>
	);
}

interface ItemProps {
	item: MediaItem;
	data: MediaData;
	busy: boolean;
	/** Another item's action is running: one at a time, as each rewrites the translation. */
	locked: boolean;
	onMark: ( ok: boolean ) => void;
	onAction: ( action: string, args?: { value?: string | number; labels?: Record< string, string > }, done?: string ) => Promise< boolean >;
	/** Originals whose AI translation was asked for here. */
	queued: number[];
	onTranslate: ( sourceId: number, title: string ) => void;
}

/** A translate button for an original, or that it is queued. */
function TranslateButton( { sourceId, title, data, queued, disabled, onTranslate }: { sourceId: number; title: string; data: MediaData; queued: number[]; disabled: boolean; onTranslate: ItemProps[ 'onTranslate' ] } ) {
	if ( queued.includes( sourceId ) ) {
		return <span className="gds-ct-media__done">{ __( 'Queued for AI translation', 'gds-content-translation' ) }</span>;
	}

	return (
		<Button variant="secondary" size="compact" disabled={ disabled } accessibleWhenDisabled onClick={ () => onTranslate( sourceId, title ) }>
			{
				/* translators: %s: language name, e.g. Swedish */
				sprintf( __( 'AI translate into %s', 'gds-content-translation' ), data.targetLanguage )
			}
			<span className="screen-reader-text">{ `: ${ title }` }</span>
		</Button>
	);
}

/** A query loop's chosen posts that it does not show. */
function ChosenPosts( { posts, data, queued, disabled, onTranslate }: { posts: ChosenPost[]; data: MediaData; queued: number[]; disabled: boolean; onTranslate: ItemProps[ 'onTranslate' ] } ) {
	return (
		<ul className="gds-ct-media__texts">
			{ posts.map( ( post, index ) => (
				<li key={ `${ post.field }-${ post.id }-${ index }` }>
					{ post.url ? <ExternalLink href={ post.url }>{ post.title }</ExternalLink> : post.title }
					{ post.field === 'parents' && ` (${ __( 'parent page', 'gds-content-translation' ) })` }{ ' ' }
					{ post.translationId > 0 && (
						<span className="gds-ct-media__done">{ post.published ? __( 'Translated', 'gds-content-translation' ) : __( 'Translated, draft', 'gds-content-translation' ) }</span>
					) }
					{ post.translationId === 0 && post.id > 0 && data.canTranslate && <TranslateButton sourceId={ post.id } title={ post.title } data={ data } queued={ queued } disabled={ disabled } onTranslate={ onTranslate } /> }
				</li>
			) ) }
		</ul>
	);
}

function Item( { item, data, busy, locked, onMark, onAction, queued, onTranslate }: ItemProps ) {
	const [ editing, setEditing ] = useState< '' | 'url' | 'form' | 'labels' | 'document' >( '' );
	const ok = item.state === 'ok';
	const translated = item.state === 'translated';
	const has = ( action: string ) => item.actions.includes( action as never );
	const job = item.job;
	const form = item.formId ? data.formTranslation[ item.formId ] : undefined;

	const pickFile = () => {
		const type = item.kind === 'svg' ? 'image/svg+xml' : item.kind === 'image' ? 'image' : '';

		void pickMedia( __( 'Choose the file for this translation', 'gds-content-translation' ), __( 'Use in the translation', 'gds-content-translation' ), type ).then( ( id ) => {
			if ( id && id !== item.attachmentId ) {
				void onAction( 'replace', { value: id }, __( 'The translation now uses the new file.', 'gds-content-translation' ) );
			}
		} );
	};

	const replaceLabel = {
		document: __( 'Use another file…', 'gds-content-translation' ),
		svg: __( 'Use another image…', 'gds-content-translation' ),
		image: __( 'Use another image…', 'gds-content-translation' ),
		form: __( 'Show another form…', 'gds-content-translation' ),
		embed: __( 'Change the address…', 'gds-content-translation' ),
		link: __( 'Change the link…', 'gds-content-translation' ),
		query: '',
		page: '',
		placeholder: '',
	}[ item.kind ];

	const replace = () => {
		if ( item.kind === 'embed' || item.kind === 'link' ) {
			setEditing( 'url' );
		} else if ( item.kind === 'form' ) {
			setEditing( 'form' );
		} else {
			pickFile();
		}
	};

	return (
		<li className={ `gds-ct-media__item gds-ct-media__item--${ item.kind } is-${ item.state }` }>
			{ item.thumbnail && <img className="gds-ct-media__thumb" src={ item.thumbnail } alt="" loading="lazy" /> }
			<div className="gds-ct-media__body">
				<p className="gds-ct-media__title">
					<span className="gds-ct-media__kind">{ kindLabel( item.kind ) }</span>
					{ item.url ? (
						<a href={ item.url } target="_blank" rel="noopener noreferrer">
							{ item.title }
							<span className="screen-reader-text">{ __( '(opens in a new tab)', 'gds-content-translation' ) }</span>
						</a>
					) : (
						<span>{ item.title }</span>
					) }
					{ translated && <span className="gds-ct-media__done">{ __( 'Translated', 'gds-content-translation' ) }</span> }
				</p>
				{ item.inTitle && (
					<p className="gds-ct-media__hint">
						{ sprintf(
							/* translators: %s: synced pattern title */
							item.inOriginal ? __( 'In the pattern “%s”, which the page still shows in the original language: translate the pattern first.', 'gds-content-translation' ) : __( 'In the pattern “%s”: a change here changes it on every page that shows the pattern.', 'gds-content-translation' ),
							item.inTitle
						) }
					</p>
				) }
				{ item.inOriginalTitle && (
					<p className="gds-ct-media__hint">
						{ sprintf(
							/* translators: %s: synced pattern title */
							__( 'Also in the pattern “%s”, which the page still shows in the original language: a change here leaves it as it is.', 'gds-content-translation' ),
							item.inOriginalTitle
						) }
					</p>
				) }
				{ ! ok && hint( item, data ) && <p className="gds-ct-media__hint">{ hint( item, data ) }</p> }
				{ ! ok && ! translated && !! item.texts?.length && <Texts texts={ item.texts } /> }
				{ ! ok && item.kind === 'placeholder' && !! item.sourceTexts?.length && (
					<>
						<p className="gds-ct-media__hint">
							{
								/* translators: %s: language name of the original, e.g. Finnish */
								sprintf( __( 'In the %s original:', 'gds-content-translation' ), data.sourceLanguage )
							}
						</p>
						<Texts texts={ item.sourceTexts } />
					</>
				) }
				{ ! ok && item.kind === 'query' && !! item.posts?.length && <ChosenPosts posts={ item.posts } data={ data } queued={ queued } disabled={ busy || locked } onTranslate={ onTranslate } /> }
				{ translated && !! item.labels?.length && <Texts texts={ item.labels.map( ( label ) => label.translation ) } /> }
				<AltText item={ item } data={ data } />

				{ item.kind === 'form' && form && ! ok && (
					<p className="gds-ct-media__hint">
						{ sprintf(
							/* translators: 1: texts translated, 2: all texts of the form, 3: language name */
							__( '%1$d of %2$d texts translated into %3$s.', 'gds-content-translation' ),
							form.translated,
							form.total,
							data.targetLanguage
						) }{ ' ' }
						<ExternalLink href={ form.url }>{ translated ? __( 'Edit the form’s translations', 'gds-content-translation' ) : __( 'Translate the form', 'gds-content-translation' ) }</ExternalLink>
					</p>
				) }

				{ job?.status === 'translating' && (
					<p className="gds-ct-media__job">
						<Spinner />
						{ job.secondsRemaining > 0
							? sprintf(
									/* translators: %d: seconds */
									__( 'DeepL is translating the file, about %d seconds left. You can leave this page.', 'gds-content-translation' ),
									job.secondsRemaining
							  )
							: __( 'DeepL is translating the file. You can leave this page.', 'gds-content-translation' ) }
					</p>
				) }
				{ job?.status === 'ready' && job.draft && (
					<p className="gds-ct-media__job">
						{ __( 'DeepL’s draft is ready:', 'gds-content-translation' ) } <ExternalLink href={ job.draft.url }>{ job.draft.title }</ExternalLink>
						{ job.billed > 0 &&
							' ' +
								sprintf(
									/* translators: %s: number of characters */
									__( '(billed as %s characters)', 'gds-content-translation' ),
									job.billed.toLocaleString()
								) }
						<br />
						{ __( 'Open it and check the text and layout before you use it.', 'gds-content-translation' ) }
					</p>
				) }
				{ job?.status === 'failed' && <p className="gds-ct-media__job is-error">{ job.error || __( 'DeepL could not translate the file.', 'gds-content-translation' ) }</p> }

				{ editing === 'url' && <UrlForm item={ item } onCancel={ () => setEditing( '' ) } onSave={ ( url ) => void onAction( 'replace', { value: url }, __( 'The translation now uses the new address.', 'gds-content-translation' ) ).then( ( done ) => done && setEditing( '' ) ) } /> }
				{ editing === 'form' && <FormChoice item={ item } forms={ data.forms } onCancel={ () => setEditing( '' ) } onSave={ ( formId ) => void onAction( 'replace', { value: formId }, __( 'The translation now shows the other form.', 'gds-content-translation' ) ).then( ( done ) => done && setEditing( '' ) ) } /> }

				<div className="gds-ct-media__actions">
					{ has( 'query-update' ) && ! ok && (
						<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || locked } accessibleWhenDisabled onClick={ () => void onAction( 'query-update', {}, __( 'The list now shows the translated posts.', 'gds-content-translation' ) ) }>
							{ __( 'Update the list', 'gds-content-translation' ) }
						</Button>
					) }
					{ item.kind === 'page' && has( 'translate' ) && ! ok && item.sourceId && <TranslateButton sourceId={ item.sourceId } title={ item.title } data={ data } queued={ queued } disabled={ busy || locked } onTranslate={ onTranslate } /> }
					{ item.kind === 'page' && item.editUrl && ! ok && (
						<Button variant="tertiary" size="compact" href={ item.editUrl } target="_blank" rel="noopener noreferrer">
							{ __( 'Edit the original', 'gds-content-translation' ) }
							<span className="screen-reader-text">{ `: ${ item.title } ${ __( '(opens in a new tab)', 'gds-content-translation' ) }` }</span>
						</Button>
					) }
					{ item.kind === 'placeholder' && data.editUrl && ! ok && (
						<Button variant="tertiary" size="compact" href={ data.editUrl } target="_blank" rel="noopener noreferrer">
							{ __( 'Edit the translation', 'gds-content-translation' ) }
							<span className="screen-reader-text">{ __( '(opens in a new tab)', 'gds-content-translation' ) }</span>
						</Button>
					) }
					{ has( 'svg-translate' ) && ! ok && (
						<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || locked } accessibleWhenDisabled onClick={ () => void onAction( 'svg-translate', {}, __( 'The labels are translated. The translation shows the copy of the illustration.', 'gds-content-translation' ) ) }>
							{ __( 'Translate the labels', 'gds-content-translation' ) }
						</Button>
					) }
					{ has( 'svg-labels' ) && (
						<>
							{ item.current === false && (
								<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || locked } accessibleWhenDisabled onClick={ () => void onAction( 'svg-labels', { labels: {} }, __( 'The copy is updated.', 'gds-content-translation' ) ) }>
									{ __( 'Update the copy', 'gds-content-translation' ) }
								</Button>
							) }
							<Button variant="secondary" size="compact" disabled={ busy || locked } accessibleWhenDisabled onClick={ () => setEditing( 'labels' ) }>
								{ __( 'Edit the labels…', 'gds-content-translation' ) }
							</Button>
						</>
					) }
					{ has( 'document-translate' ) && ! ok && (
						<Button variant="secondary" size="compact" disabled={ busy || locked } accessibleWhenDisabled onClick={ () => setEditing( 'document' ) }>
							{ __( 'Translate with DeepL…', 'gds-content-translation' ) }
						</Button>
					) }
					{ has( 'document-use' ) && job?.status === 'ready' && (
						<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || locked } accessibleWhenDisabled onClick={ () => void onAction( 'document-use', {}, __( 'The translation now links the translated file.', 'gds-content-translation' ) ) }>
							{ __( 'Use this file', 'gds-content-translation' ) }
						</Button>
					) }
					{ has( 'document-discard' ) && job?.status !== 'translating' && (
						<Button variant="tertiary" size="compact" disabled={ busy || locked } accessibleWhenDisabled onClick={ () => void onAction( 'document-discard' ) }>
							{ job?.status === 'failed' ? __( 'Dismiss', 'gds-content-translation' ) : __( 'Not now', 'gds-content-translation' ) }
						</Button>
					) }
					{ has( 'replace' ) && ! ok && ! editing && (
						<Button variant={ item.kind === 'image' || item.kind === 'document' ? 'secondary' : 'tertiary' } size="compact" disabled={ busy || locked } accessibleWhenDisabled onClick={ replace }>
							{ replaceLabel }
						</Button>
					) }
					{ has( 'restore' ) && (
						<Button variant="tertiary" size="compact" disabled={ busy || locked } accessibleWhenDisabled onClick={ () => void onAction( 'restore', {}, __( 'The translation shows the original’s file again.', 'gds-content-translation' ) ) }>
							{ __( 'Use the original again', 'gds-content-translation' ) }
						</Button>
					) }
					{ ! translated && (
						<Button variant="tertiary" size="compact" onClick={ () => onMark( ! ok ) } disabled={ busy || locked } accessibleWhenDisabled>
							{ ok ? __( 'Count again', 'gds-content-translation' ) : __( 'Fine as it is', 'gds-content-translation' ) }
							<span className="screen-reader-text">{ `: ${ item.title }` }</span>
						</Button>
					) }
				</div>
			</div>

			{ editing === 'labels' && (
				<LabelsModal item={ item } onClose={ () => setEditing( '' ) } onSave={ ( labels ) => onAction( 'svg-labels', { labels }, __( 'The labels are saved.', 'gds-content-translation' ) ) } />
			) }
			{ editing === 'document' && data.documentCost && (
				<ConfirmDialog
					confirmButtonText={ item.draftExists ? __( 'Use the draft', 'gds-content-translation' ) : __( 'Translate with DeepL', 'gds-content-translation' ) }
					onConfirm={ () => {
						setEditing( '' );
						void onAction( 'document-translate', {}, item.draftExists ? '' : __( 'DeepL is translating the file. It takes from a few seconds to a few minutes.', 'gds-content-translation' ) );
					} }
					onCancel={ () => setEditing( '' ) }
				>
					<p>
						<strong>
							{
								/* translators: %s: file name */
								sprintf( __( 'Translate %s with DeepL?', 'gds-content-translation' ), item.title )
							}
						</strong>
					</p>
					{ costText( data.documentCost, !! item.draftExists, data.targetLanguage ).map( ( line ) => (
						<p key={ line }>{ line }</p>
					) ) }
					{ ! data.documentCost.enough && ! item.draftExists && <p className="gds-ct-media__job is-error">{ __( 'The plan may not have enough characters left this period.', 'gds-content-translation' ) }</p> }
				</ConfirmDialog>
			) }
		</li>
	);
}

interface Props {
	postId: number;
	/** After something changed: the counts elsewhere change. */
	onChange?: ( data: MediaData ) => void;
	/** A post was queued for AI translation: the status screen follows its job. */
	onQueued?: () => void;
}

export function MediaList( { postId, onChange, onQueued }: Props ) {
	const [ data, setData ] = useState< MediaData | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ queued, setQueued ] = useState< number[] >( [] );

	useEffect( () => {
		let current = true;

		fetchMedia( postId )
			.then( ( loaded ) => current && setData( loaded ) )
			.catch( ( caught: unknown ) => current && setFailed( errorMessage( caught, __( 'Could not load. Reload the page.', 'gds-content-translation' ) ) ) );

		return () => {
			current = false;
		};
	}, [ postId ] );

	// While DeepL translates a document, ask how far it is.
	const translating = !! data?.items.some( ( item ) => item.job?.status === 'translating' );

	// Bumped by each action, so a poll that started before one does not
	// overwrite what it returned; and by a failed poll, to ask again.
	const versionRef = useRef( 0 );
	const [ retry, setRetry ] = useState( 0 );

	useEffect( () => {
		if ( ! translating ) {
			return;
		}

		const timer = setTimeout( () => {
			const version = versionRef.current;

			fetchMedia( postId )
				.then( ( next ) => version === versionRef.current && setData( next ) )
				.catch( () => setRetry( ( count ) => count + 1 ) );
		}, 10000 );

		return () => clearTimeout( timer );
	}, [ translating, data, postId, retry ] );

	if ( failed ) {
		return <p className="gds-ct-media__empty">{ failed }</p>;
	}

	if ( ! data ) {
		return (
			<p className="gds-ct-media__empty">
				<Spinner /> { __( 'Loading…', 'gds-content-translation' ) }
			</p>
		);
	}

	const changed = ( next: MediaData ) => {
		versionRef.current++;
		setData( next );
		onChange?.( next );
	};

	const mark = ( item: MediaItem, ok: boolean ) => {
		setBusy( item.key );
		markMedia( postId, [ item.key ], ok )
			.then( changed )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'Could not save. Try again.', 'gds-content-translation' ) ) ) )
			.finally( () => setBusy( '' ) );
	};

	const act = ( item: MediaItem ) => ( action: string, args: { value?: string | number; labels?: Record< string, string > } = {}, done = '' ): Promise< boolean > => {
		setBusy( item.key );

		return runMediaAction( postId, item.key, action, args )
			.then( ( next ) => {
				changed( next );

				if ( done ) {
					notifySuccess( done );
				}

				return true;
			} )
			.catch( ( caught: unknown ) => {
				notifyError( errorMessage( caught, __( 'That did not work. Try again.', 'gds-content-translation' ) ) );

				return false;
			} )
			.finally( () => setBusy( '' ) );
	};

	// The status screen's AI translation, in the background: the list counts
	// the post again once its translation is saved.
	const translate = ( sourceId: number, title: string ) => {
		setQueued( ( ids ) => [ ...ids, sourceId ] );
		runAction( 'translate', [ sourceId ], { languages: [ data.targetSlug ] } )
			.then( ( response ) => {
				const result = response.results[ 0 ];

				if ( ! response.ok && result && ! result.ok ) {
					throw new Error( result.message || response.message );
				}

				onQueued?.();
				notifySuccess(
					sprintf(
						/* translators: 1: post title, 2: language name, e.g. Swedish */
						__( '“%1$s” is queued for AI translation into %2$s. This list counts it again once it is translated.', 'gds-content-translation' ),
						title,
						data.targetLanguage
					)
				);
			} )
			.catch( ( caught: unknown ) => {
				setQueued( ( ids ) => ids.filter( ( id ) => id !== sourceId ) );
				notifyError( errorMessage( caught, __( 'That did not work. Try again.', 'gds-content-translation' ) ) );
			} );
	};

	const rank = { open: 0, 'not-checked': 1, translated: 2, ok: 3, unchecked: 4 };
	const sorted = data.items
		.filter( ( item ) => item.state !== 'unchecked' )
		.sort( ( a, b ) => rank[ a.state ] - rank[ b.state ] || order.indexOf( a.kind ) - order.indexOf( b.kind ) );

	return (
		<div className="gds-ct-media">
			{ sorted.length ? (
				<ul className="gds-ct-media__list">
					{ sorted.map( ( item ) => (
						<Item key={ item.key } item={ item } data={ data } busy={ busy === item.key } locked={ busy !== '' && busy !== item.key } onMark={ ( ok ) => mark( item, ok ) } onAction={ act( item ) } queued={ queued } onTranslate={ translate } />
					) ) }
				</ul>
			) : (
				<p className="gds-ct-media__empty">
					{
						/* translators: %s: language name of the original, e.g. Finnish */
						sprintf( __( 'No files, images, forms or links in %s found, and no texts still to write.', 'gds-content-translation' ), data.sourceLanguage )
					}
				</p>
			) }
			{ data.unchecked > 0 && data.canCheckImages && (
				<p className="gds-ct-media__note">
					{ sprintf(
						/* translators: %d: number of images */
						_n( '%d image is still being checked for text.', '%d images are still being checked for text.', data.unchecked, 'gds-content-translation' ),
						data.unchecked
					) }
				</p>
			) }
			{ ! data.canCheckImages && <p className="gds-ct-media__note">{ __( 'Images are not checked for text: that needs the Claude API key of the AI review.', 'gds-content-translation' ) }</p> }
		</div>
	);
}
