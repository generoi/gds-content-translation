/**
 * Import a CSV in DeepL's format: "source","target","EN","SV" a row, no
 * header. The file is read here first, to say what is in it and to ask for
 * the language only when its rows have no language columns. After the
 * upload, the dialog says what changed.
 */
import { Button, Flex, Modal, Notice, RadioControl, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import type { GlossarySettings } from '../settings';
import { importTerms } from './api';
import { languageName } from './fields';
import type { ImportMode, ImportResponse } from './types';

interface FileSummary {
	rows: number;
	/** Target language codes of the four-column rows. */
	languages: string[];
	/** Some rows have only a source and a target. */
	twoColumns: boolean;
}

/** One CSV line's cells; quotes may hold commas and doubled quotes. */
function cells( line: string ): string[] {
	const out: string[] = [];
	let cell = '';
	let quoted = false;

	for ( let i = 0; i < line.length; i++ ) {
		const char = line[ i ];

		if ( quoted ) {
			if ( char === '"' && line[ i + 1 ] === '"' ) {
				cell += '"';
				i++;
			} else if ( char === '"' ) {
				quoted = false;
			} else {
				cell += char;
			}
		} else if ( char === '"' ) {
			quoted = true;
		} else if ( char === ',' ) {
			out.push( cell );
			cell = '';
		} else {
			cell += char;
		}
	}

	out.push( cell );

	return out;
}

function summarize( text: string ): FileSummary {
	const languages = new Set< string >();
	let rows = 0;
	let twoColumns = false;

	for ( const line of text.replace( /^\uFEFF/, '' ).split( /\r?\n/ ) ) {
		if ( line.trim() === '' ) {
			continue;
		}

		const row = cells( line );
		rows++;

		if ( row.length >= 4 && row[ 3 ] ) {
			languages.add( row[ 3 ].trim().toUpperCase() );
		} else {
			twoColumns = true;
		}
	}

	return { rows, languages: [ ...languages ], twoColumns };
}

export function ImportModal( { glossary, targetLang, onImported, onClose }: { glossary: GlossarySettings; targetLang: string; onImported: () => void; onClose: () => void } ) {
	const [ file, setFile ] = useState< File | null >( null );
	const [ summary, setSummary ] = useState< FileSummary | null >( null );
	const [ mode, setMode ] = useState< ImportMode >( 'merge' );
	const [ language, setLanguage ] = useState( targetLang || glossary.targetLangs[ 0 ]?.code || '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState< ImportResponse | null >( null );

	const choose = ( chosen: File | null ) => {
		setFile( chosen );
		setSummary( null );
		setError( '' );

		chosen
			?.text()
			.then( ( text ) => setSummary( summarize( text ) ) )
			.catch( () => setError( __( 'The file could not be read.', 'gds-content-translation' ) ) );
	};

	const submit = () => {
		if ( ! file ) {
			return;
		}

		setIsBusy( true );
		setError( '' );
		importTerms( file, mode, summary?.twoColumns ? language : '' )
			.then( ( response ) => {
				setResult( response );
				onImported();
			} )
			.catch( ( caught: unknown ) => setError( errorMessage( caught, __( 'The file could not be imported. Check your connection and try again.', 'gds-content-translation' ) ) ) )
			.finally( () => setIsBusy( false ) );
	};

	const names = ( codes: string[] ) => codes.map( ( code ) => languageName( glossary, code ) ).join( ', ' );
	const replaced = summary ? [ ...summary.languages, ...( summary.twoColumns ? [ language ] : [] ) ] : [];

	if ( result ) {
		const lines: Array< [ string, number ] > = [
			[ __( 'Added', 'gds-content-translation' ), result.added ],
			[ __( 'Updated', 'gds-content-translation' ), result.updated ],
			[ __( 'Unchanged', 'gds-content-translation' ), result.unchanged ],
			[ __( 'Skipped', 'gds-content-translation' ), result.skipped ],
		];

		return (
			<Modal title={ __( 'Import CSV', 'gds-content-translation' ) } onRequestClose={ onClose } size="medium" className="gds-ct-glossary__modal">
				<Notice status={ result.skipped ? 'warning' : 'success' } isDismissible={ false }>
					{ result.message }
				</Notice>
				<dl className="gds-ct-glossary__result">
					{ lines.map( ( [ label, count ] ) => (
						<div key={ label }>
							<dt>{ label }</dt>
							<dd>{ count.toLocaleString() }</dd>
						</div>
					) ) }
				</dl>
				{ result.pairs.length > 0 && (
					<p className="description">
						{
							/* translators: %s: language pairs, e.g. EN → SV, EN → DE */
							sprintf( __( 'Languages: %s', 'gds-content-translation' ), result.pairs.join( ', ' ) )
						}
					</p>
				) }
				<Flex justify="flex-end" className="gds-ct__modal-buttons">
					<Button variant="primary" onClick={ onClose }>
						{ __( 'Done', 'gds-content-translation' ) }
					</Button>
				</Flex>
			</Modal>
		);
	}

	return (
		<Modal title={ __( 'Import CSV', 'gds-content-translation' ) } onRequestClose={ onClose } size="medium" className="gds-ct-glossary__modal">
			<form
				onSubmit={ ( event ) => {
					event.preventDefault();
					submit();
				} }
			>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<div className="gds-ct-glossary__form">
					<div className="gds-ct-glossary__file">
						<label htmlFor="gds-ct-glossary-file">{ __( 'CSV file', 'gds-content-translation' ) }</label>
						<input id="gds-ct-glossary-file" type="file" accept=".csv,text/csv" aria-describedby="gds-ct-glossary-file-help" onChange={ ( event ) => choose( event.target.files?.[ 0 ] ?? null ) } />
						<p id="gds-ct-glossary-file-help" className="description">
							{ __( 'A glossary exported from DeepL: one term a row, as source, translation, source language, target language.', 'gds-content-translation' ) }
						</p>
						{ summary && (
							<p className="gds-ct-glossary__file-summary">
								{ summary.languages.length
									? sprintf(
											/* translators: 1: number of rows, 2: language names */
											_n( '%1$s term in %2$s.', '%1$s terms in %2$s.', summary.rows, 'gds-content-translation' ),
											summary.rows.toLocaleString(),
											names( summary.languages )
									  )
									: sprintf(
											/* translators: %s: number of rows */
											_n( '%s term, without languages.', '%s terms, without languages.', summary.rows, 'gds-content-translation' ),
											summary.rows.toLocaleString()
									  ) }
							</p>
						) }
					</div>
					{ summary?.twoColumns && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Language of the translations', 'gds-content-translation' ) }
							help={ __( 'For the rows with only a source term and a translation.', 'gds-content-translation' ) }
							value={ language }
							options={ glossary.targetLangs.map( ( item ) => ( { value: item.code, label: item.name } ) ) }
							onChange={ setLanguage }
						/>
					) }
					<RadioControl
						label={ __( 'How to import', 'gds-content-translation' ) }
						selected={ mode }
						options={ [
							{
								value: 'merge',
								label: __( 'Add and update', 'gds-content-translation' ),
								description: __( 'New terms are added. A term already here gets the translation from the file.', 'gds-content-translation' ),
							},
							{
								value: 'replace',
								label: __( 'Replace the languages in the file', 'gds-content-translation' ),
								description: replaced.length
									? sprintf(
											/* translators: %s: language names */
											__( 'The terms in use and drafts in %s are removed first. Suggestions and rejected terms stay.', 'gds-content-translation' ),
											names( replaced )
									  )
									: __( 'The terms in use and drafts of the languages in the file are removed first. Suggestions and rejected terms stay.', 'gds-content-translation' ),
							},
						] }
						onChange={ ( value ) => setMode( value as ImportMode ) }
					/>
				</div>
				<Flex justify="flex-end" className="gds-ct__modal-buttons">
					<Button variant="tertiary" onClick={ onClose } disabled={ isBusy }>
						{ __( 'Cancel', 'gds-content-translation' ) }
					</Button>
					<Button variant="primary" type="submit" isDestructive={ mode === 'replace' } isBusy={ isBusy } disabled={ ! file || isBusy } accessibleWhenDisabled>
						{ mode === 'replace' ? __( 'Replace and import', 'gds-content-translation' ) : __( 'Import', 'gds-content-translation' ) }
					</Button>
				</Flex>
			</form>
		</Modal>
	);
}
