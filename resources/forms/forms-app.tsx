/**
 * Polylang → Form translations: every Gravity Forms form with how much of it
 * is translated into each language, and one form's texts in one language to
 * translate (DeepL), check (AI review) and edit. The form and language are
 * in the address (`&form=1&form_lang=en`, not `lang`, which is
 * Polylang's filter of the admin), so the list of files still in the
 * original's language links straight to a form.
 */
import { Button, Flex, Notice, Spinner, TextareaControl } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs, getQueryArg } from '@wordpress/url';
import { errorMessage } from '../api';
import { defaultLanguage, getSettings, translationLanguages } from '../settings';
import { ErrorBoundary } from '../ui/error-boundary';
import { Notices } from '../ui/notices';
import { notifyError, notifySuccess } from '../ui/notify';
import { fetchFormRows, fetchForms, formOperation, saveForm } from './api';
import type { FormRows, FormSummary } from './types';

function screenUrl( formId = 0, lang = '' ): string {
	const base = getSettings().forms?.url ?? window.location.href;

	return formId ? addQueryArgs( base, { form: formId, form_lang: lang } ) : base;
}

function Overview( { onOpen }: { onOpen: ( formId: number, lang: string ) => void } ) {
	const [ forms, setForms ] = useState< FormSummary[] | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const languages = translationLanguages();

	useEffect( () => {
		fetchForms()
			.then( ( loaded ) => setForms( loaded.forms ) )
			.catch( ( caught: unknown ) => setFailed( errorMessage( caught, __( 'Could not load the forms.', 'gds-content-translation' ) ) ) );
	}, [] );

	if ( failed ) {
		return <Notice status="error" isDismissible={ false }>{ failed }</Notice>;
	}

	if ( ! forms ) {
		return <Spinner />;
	}

	return (
		<>
			<p className="gds-ct-forms__intro">
				{ sprintf(
					/* translators: %s: language name of the original, e.g. Finnish */
					__( 'A form is written once, in %s. Its labels, choices, buttons and messages are translated here, and a page in another language shows the form in that language. Entries stay in the form’s one list, each with its language.', 'gds-content-translation' ),
					defaultLanguage()?.name ?? ''
				) }
			</p>
			{ ! forms.length ? (
				<p>{ __( 'There are no forms yet.', 'gds-content-translation' ) }</p>
			) : (
				<table className="widefat striped gds-ct-forms__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Form', 'gds-content-translation' ) }</th>
							{ languages.map( ( language ) => (
								<th scope="col" key={ language.slug }>
									{ language.name }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ forms.map( ( form ) => (
							<tr key={ form.id }>
								<td>
									<strong>{ form.title }</strong>
								</td>
								{ languages.map( ( language ) => {
									const progress = form.languages[ language.slug ];
									const done = progress && progress.total > 0 && progress.translated >= progress.total;

									return (
										<td key={ language.slug }>
											<Button variant="link" href={ screenUrl( form.id, language.slug ) } onClick={ ( event: React.MouseEvent ) => { event.preventDefault(); onOpen( form.id, language.slug ); } }>
												{ progress
													? sprintf(
															/* translators: 1: texts translated, 2: all texts */
															__( '%1$d of %2$d translated', 'gds-content-translation' ),
															progress.translated,
															progress.total
													  )
													: '' }
											</Button>
											{ done && <span className="gds-ct-forms__done" aria-hidden="true"> ✓</span> }
											{ !! progress?.remarks && (
												<span className="gds-ct__audit gds-ct__audit--remarks gds-ct-forms__remarks">
													{
														/* translators: %d: number of AI remarks */
														sprintf( _n( '%d to check', '%d to check', progress.remarks, 'gds-content-translation' ), progress.remarks )
													}
												</span>
											) }
										</td>
									);
								} ) }
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</>
	);
}

function Editor( { formId, lang, onBack }: { formId: number; lang: string; onBack: () => void } ) {
	const [ data, setData ] = useState< FormRows | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const [ drafts, setDrafts ] = useState< Record< string, string > >( {} );
	const [ busy, setBusy ] = useState< '' | 'save' | 'translate' | 'review' | string >( '' );
	const source = defaultLanguage()?.name ?? '';

	useEffect( () => {
		fetchFormRows( formId, lang )
			.then( setData )
			.catch( ( caught: unknown ) => setFailed( errorMessage( caught, __( 'Could not load the form.', 'gds-content-translation' ) ) ) );
	}, [ formId, lang ] );

	const dirty = useMemo( () => Object.keys( drafts ).filter( ( key ) => drafts[ key ] !== ( data?.rows.find( ( row ) => row.source === key )?.translation ?? '' ) ), [ drafts, data ] );

	useEffect( () => {
		if ( dirty.length === 0 ) {
			return;
		}

		const warn = ( event: BeforeUnloadEvent ) => event.preventDefault();
		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty.length ] );

	if ( failed ) {
		return <Notice status="error" isDismissible={ false }>{ failed }</Notice>;
	}

	if ( ! data ) {
		return <Spinner />;
	}

	const missing = data.rows.filter( ( row ) => row.translation === '' ).length;
	const remarks = data.rows.filter( ( row ) => row.remark ).length;

	// Clears the drafts of the texts the request saved, and keeps the others.
	const run = ( what: string, request: () => Promise< FormRows >, done: ( next: FormRows ) => string, saved: string[] = [] ) => {
		setBusy( what );
		request()
			.then( ( next ) => {
				setData( next );
				setDrafts( ( current ) => Object.fromEntries( Object.entries( current ).filter( ( [ key ] ) => ! saved.includes( key ) ) ) );
				notifySuccess( done( next ) );
			} )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'That did not work. Try again.', 'gds-content-translation' ) ) ) )
			.finally( () => setBusy( '' ) );
	};

	const save = () =>
		run(
			'save',
			() => saveForm( formId, lang, Object.fromEntries( dirty.map( ( key ) => [ key, drafts[ key ] ?? '' ] ) ) ),
			() => __( 'Translations saved.', 'gds-content-translation' ),
			dirty
		);

	const back = () => {
		if ( dirty.length === 0 || window.confirm( __( 'Leave without saving your changes?', 'gds-content-translation' ) ) ) {
			onBack();
		}
	};

	return (
		<div className="gds-ct-forms__editor">
			<p>
				<Button variant="link" onClick={ back }>
					{ __( '← All forms', 'gds-content-translation' ) }
				</Button>
			</p>
			<h2>
				{
					/* translators: 1: form title, 2: language name */
					sprintf( __( '%1$s in %2$s', 'gds-content-translation' ), data.form.title, data.language )
				}
			</h2>
			<Flex className="gds-ct-forms__toolbar" justify="flex-start" gap={ 2 } wrap>
				{ data.canTranslate && (
					<Button
						variant={ missing ? 'primary' : 'secondary' }
						isBusy={ busy === 'translate' }
						disabled={ !! busy || ! missing || dirty.length > 0 }
						accessibleWhenDisabled
						onClick={ () =>
							run( 'translate', () => formOperation( formId, lang, 'translate' ), ( next ) =>
								sprintf(
									/* translators: %d: number of texts */
									_n( 'DeepL translated %d text.', 'DeepL translated %d texts.', next.changed ?? 0, 'gds-content-translation' ),
									next.changed ?? 0
								)
							)
						}
					>
						{ missing
							? sprintf(
									/* translators: %d: number of texts without a translation */
									_n( 'Translate %d missing text with DeepL', 'Translate %d missing texts with DeepL', missing, 'gds-content-translation' ),
									missing
							  )
							: __( 'Everything is translated', 'gds-content-translation' ) }
					</Button>
				) }
				{ data.canReview && (
					<Button
						variant="secondary"
						isBusy={ busy === 'review' }
						disabled={ !! busy || missing === data.rows.length || dirty.length > 0 }
						accessibleWhenDisabled
						onClick={ () =>
							run( 'review', () => formOperation( formId, lang, 'review' ), ( next ) =>
								next.changed
									? sprintf(
											/* translators: %d: number of remarks */
											_n( 'The AI review has %d remark.', 'The AI review has %d remarks.', next.changed, 'gds-content-translation' ),
											next.changed
									  )
									: __( 'The AI review found nothing to change.', 'gds-content-translation' )
							)
						}
					>
						{ __( 'Check with the AI review', 'gds-content-translation' ) }
					</Button>
				) }
				<Button variant={ dirty.length ? 'primary' : 'secondary' } isBusy={ busy === 'save' } disabled={ !! busy || ! dirty.length } accessibleWhenDisabled onClick={ save }>
					{ dirty.length
						? sprintf(
								/* translators: %d: number of changed texts */
								_n( 'Save %d change', 'Save %d changes', dirty.length, 'gds-content-translation' ),
								dirty.length
						  )
						: __( 'Saved', 'gds-content-translation' ) }
				</Button>
				<a className="gds-ct-forms__edit" href={ data.form.editUrl }>
					{ __( 'Edit the form', 'gds-content-translation' ) }
				</a>
			</Flex>
			{ remarks > 0 && (
				<p className="gds-ct-forms__summary">
					{ sprintf(
						/* translators: %d: number of remarks */
						_n( '%d AI remark to go through.', '%d AI remarks to go through.', remarks, 'gds-content-translation' ),
						remarks
					) }
				</p>
			) }
			<table className="widefat gds-ct-forms__texts">
				<thead>
					<tr>
						<th scope="col">
							{
								/* translators: %s: language name of the original, e.g. Finnish */
								sprintf( __( 'Original (%s)', 'gds-content-translation' ), source )
							}
						</th>
						<th scope="col">
							{
								/* translators: %s: language name of the translation, e.g. English */
								sprintf( __( 'Translation (%s)', 'gds-content-translation' ), data.language )
							}
						</th>
					</tr>
				</thead>
				<tbody>
					{ data.rows.map( ( row ) => {
						const value = drafts[ row.source ] ?? row.translation;

						return (
							<tr key={ row.source } className={ row.remark ? 'has-remark' : '' }>
								<td>
									<span className="gds-ct-forms__context">{ row.context }</span>
									<div className="gds-ct-forms__source">{ row.source }</div>
								</td>
								<td>
									<TextareaControl
										__nextHasNoMarginBottom
										label={ row.context }
										hideLabelFromVision
										value={ value }
										rows={ row.html || row.source.length > 80 ? 4 : 1 }
										placeholder={ __( 'Not translated: the original is shown', 'gds-content-translation' ) }
										onChange={ ( next ) => setDrafts( { ...drafts, [ row.source ]: next } ) }
									/>
									{ row.remark && (
										<div className="gds-ct-forms__remark">
											<p>{ row.remark.issue }</p>
											<p className="gds-ct-forms__suggestion">{ row.remark.suggestion }</p>
											<Button
												variant="primary"
												size="compact"
												disabled={ !! busy }
												accessibleWhenDisabled
												onClick={ () => run( `remark-${ row.source }`, () => formOperation( formId, lang, 'remark', { source: row.source, use: true } ), () => __( 'Suggestion used.', 'gds-content-translation' ), [ row.source ] ) }
											>
												{ __( 'Use suggestion', 'gds-content-translation' ) }
											</Button>{ ' ' }
											<Button
												variant="tertiary"
												size="compact"
												disabled={ !! busy }
												accessibleWhenDisabled
												onClick={ () => run( `remark-${ row.source }`, () => formOperation( formId, lang, 'remark', { source: row.source, use: false } ), () => __( 'Marked as not an issue.', 'gds-content-translation' ), [ row.source ] ) }
											>
												{ __( 'Not an issue', 'gds-content-translation' ) }
											</Button>
										</div>
									) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
			{ dirty.length > 0 && (
				<p>
					<Button variant="primary" isBusy={ busy === 'save' } disabled={ !! busy } onClick={ save }>
						{ __( 'Save changes', 'gds-content-translation' ) }
					</Button>
				</p>
			) }
		</div>
	);
}

export function FormsApp() {
	const initialForm = Number( getQueryArg( window.location.href, 'form' ) ?? 0 );
	const initialLang = String( getQueryArg( window.location.href, 'form_lang' ) ?? '' );
	const [ open, setOpen ] = useState< { formId: number; lang: string } | null >( initialForm && initialLang ? { formId: initialForm, lang: initialLang } : null );

	const go = ( next: { formId: number; lang: string } | null ) => {
		setOpen( next );
		window.history.pushState( null, '', next ? screenUrl( next.formId, next.lang ) : screenUrl() );
	};

	useEffect( () => {
		const onPop = () => {
			const formId = Number( getQueryArg( window.location.href, 'form' ) ?? 0 );
			const lang = String( getQueryArg( window.location.href, 'form_lang' ) ?? '' );
			setOpen( formId && lang ? { formId, lang } : null );
		};

		window.addEventListener( 'popstate', onPop );

		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	return (
		<ErrorBoundary context="forms">
			{ open ? <Editor key={ `${ open.formId }-${ open.lang }` } formId={ open.formId } lang={ open.lang } onBack={ () => go( null ) } /> : <Overview onOpen={ ( formId, lang ) => go( { formId, lang } ) } /> }
			<Notices />
		</ErrorBoundary>
	);
}
