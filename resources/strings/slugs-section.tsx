/**
 * Polylang Pro's translated URL slugs in one language: the bases of the
 * post types' and taxonomies' addresses (/tuotteet/ → /products/), archives,
 * author, search and page numbers. DeepL suggests URL-safe slugs to look at;
 * nothing changes until they are saved, after a question, since a changed
 * slug changes addresses.
 */
import { Button, Flex, Notice, TextControl, __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import { defaultLanguage } from '../settings';
import { notifyError, notifySuccess } from '../ui/notify';
import { saveStrings, suggestSlugs } from './api';
import type { StringsResponse } from './types';
import { useDrafts } from './use-drafts';

/** What an address part can hold, as Polylang Pro saves it. */
function urlSafe( value: string ): string {
	return value
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9/_-]+/g, '-' )
		.replace( /-{2,}/g, '-' )
		.replace( /\/{2,}/g, '/' );
}

interface Props {
	data: StringsResponse;
	onData: ( data: StringsResponse ) => void;
	onDirty: ( count: number ) => void;
}

export function SlugsSection( { data, onData, onDirty }: Props ) {
	const lang = data.language.slug;
	const drafts = useDrafts();
	const [ busy, setBusy ] = useState( '' );
	const [ confirming, setConfirming ] = useState( false );
	const dirty = drafts.dirty;
	const missing = data.slugs.filter( ( slug ) => slug.translation === '' );

	useEffect( () => onDirty( dirty.length ), [ dirty.length, onDirty ] );

	if ( ! data.slugsAvailable ) {
		return <p>{ __( 'Polylang Pro translates URL slugs when its Translate slugs module is on and the site has pretty permalinks.', 'gds-content-translation' ) }</p>;
	}

	const suggest = () => {
		setBusy( 'suggest' );
		suggestSlugs( lang, missing.map( ( slug ) => slug.id ) )
			.then( ( { suggestions } ) => {
				const entries = Object.entries( suggestions );

				for ( const [ id, suggestion ] of entries ) {
					drafts.set( id, suggestion, data.slugs.find( ( slug ) => slug.id === id )?.translation ?? '' );
				}

				notifySuccess(
					entries.length
						? sprintf(
								/* translators: %d: number of slugs */
								_n( 'DeepL suggested %d slug. Check it and save.', 'DeepL suggested %d slugs. Check them and save.', entries.length, 'gds-content-translation' ),
								entries.length
						  )
						: __( 'DeepL had no suggestions.', 'gds-content-translation' )
				);
			} )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'That did not work. Try again.', 'gds-content-translation' ) ) ) )
			.finally( () => setBusy( '' ) );
	};

	const save = () => {
		setConfirming( false );
		setBusy( 'save' );
		saveStrings( lang, { slugs: drafts.changes() } )
			.then( ( next ) => {
				onData( next );
				drafts.clear( dirty );
				notifySuccess( __( 'Slugs saved. The addresses changed.', 'gds-content-translation' ) );
			} )
			.catch( ( caught: unknown ) => notifyError( errorMessage( caught, __( 'That did not work. Try again.', 'gds-content-translation' ) ) ) )
			.finally( () => setBusy( '' ) );
	};

	return (
		<div className="gds-ct-strings__slugs">
			<Notice status="warning" isDismissible={ false }>
				{ __( 'A slug is part of the address of every page of its kind. Changing it changes those addresses: links to the old ones and search engine results stop working until a redirect is added. Translate slugs before the site goes live, or add redirects.', 'gds-content-translation' ) }
			</Notice>
			{ data.language.isDefault && (
				<p className="gds-ct-forms__intro">
					{ sprintf(
						/* translators: %s: language name, e.g. Finnish */
						__( 'These are the %s addresses: a slug translated here changes the addresses in the site’s default language.', 'gds-content-translation' ),
						data.language.name
					) }
				</p>
			) }
			<Flex className="gds-ct-forms__toolbar" justify="flex-start" gap={ 2 } wrap>
				{ data.canTranslate && ! data.language.isDefault && (
					<Button variant="secondary" isBusy={ busy === 'suggest' } disabled={ !! busy || ! missing.length } accessibleWhenDisabled onClick={ suggest }>
						{ missing.length
							? sprintf(
									/* translators: %d: number of slugs without a translation */
									_n( 'Suggest %d missing slug with DeepL', 'Suggest %d missing slugs with DeepL', missing.length, 'gds-content-translation' ),
									missing.length
							  )
							: __( 'Every slug is translated', 'gds-content-translation' ) }
					</Button>
				) }
				<Button variant={ dirty.length ? 'primary' : 'secondary' } isBusy={ busy === 'save' } disabled={ !! busy || ! dirty.length } accessibleWhenDisabled onClick={ () => setConfirming( true ) }>
					{ dirty.length
						? sprintf(
								/* translators: %d: number of changed slugs */
								_n( 'Save %d slug…', 'Save %d slugs…', dirty.length, 'gds-content-translation' ),
								dirty.length
						  )
						: __( 'Saved', 'gds-content-translation' ) }
				</Button>
			</Flex>
			<table className="widefat gds-ct-forms__texts gds-ct-strings__slug-table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Address of', 'gds-content-translation' ) }</th>
						<th scope="col">
							{
								/* translators: %s: language name of the original, e.g. Finnish */
								sprintf( __( 'Original (%s)', 'gds-content-translation' ), defaultLanguage()?.name ?? '' )
							}
						</th>
						<th scope="col">
							{
								/* translators: %s: language name of the translation, e.g. English */
								sprintf( __( 'Slug in %s', 'gds-content-translation' ), data.language.name )
							}
						</th>
					</tr>
				</thead>
				<tbody>
					{ data.slugs.map( ( slug ) => {
						const value = drafts.value( slug.id, slug.translation );
						const changed = dirty.includes( slug.id );

						return (
							<tr key={ slug.id } className={ changed ? 'is-changed' : '' }>
								<td>{ slug.what }</td>
								<td>
									<code>/{ slug.source }/</code>
								</td>
								<td>
									<TextControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										/* translators: 1: what the slug is of, 2: language name */
										label={ sprintf( __( 'Slug of %1$s in %2$s', 'gds-content-translation' ), slug.what, data.language.name ) }
										hideLabelFromVision
										value={ value }
										placeholder={ slug.source }
										help={ slug.translation === '' && ! changed ? __( 'Not translated: the original is used.', 'gds-content-translation' ) : undefined }
										onChange={ ( next ) => drafts.set( slug.id, urlSafe( next ), slug.translation ) }
									/>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
			{ confirming && (
				<ConfirmDialog confirmButtonText={ __( 'Change the addresses', 'gds-content-translation' ) } onConfirm={ save } onCancel={ () => setConfirming( false ) }>
					<p>
						<strong>{ __( 'Change these addresses?', 'gds-content-translation' ) }</strong>
					</p>
					<ul className="gds-ct-strings__changes">
						{ dirty.map( ( id ) => {
							const slug = data.slugs.find( ( candidate ) => candidate.id === id );
							const next = drafts.value( id, '' );

							return (
								<li key={ id }>
									{ slug?.what }: <code>/{ slug?.translation || slug?.source }/</code> → <code>/{ next || slug?.source }/</code>
								</li>
							);
						} ) }
					</ul>
					<p>{ __( 'Links to the old addresses stop working.', 'gds-content-translation' ) }</p>
				</ConfirmDialog>
			) }
		</div>
	);
}
