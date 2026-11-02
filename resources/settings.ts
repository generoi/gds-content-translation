/**
 * What the server hands the app once, in `window.gdsContentTranslation`
 * (Admin::appSettings()).
 */
export interface Language {
	slug: string;
	/** In the admin's language: "Swedish", not Polylang's "Svenska". */
	name: string;
	/** "SV", where there is no room for the name. */
	code: string;
	isDefault: boolean;
}

/** What happens with the AI review's suggestions on a new AI translation. */
export type Followup = 'manual' | 'apply' | 'apply_review';

export interface PostTypeTab {
	slug: string;
	label: string;
	icon: { type: 'dashicon' | 'image'; value: string };
}

export interface Settings {
	postTypes: PostTypeTab[];
	postType: string;
	languages: Language[];
	machineTranslation: boolean;
	copyTranslation: boolean;
	review: boolean;
	/** The site's default follow-up of the review of a new AI translation. */
	followup: Followup;
	/** Every follow-up with its label, in order. */
	followups: Record< Followup, string >;
	/** AI translations are published when their original is. */
	publish: boolean;
	createUrl: string;
	reviewRunUrl: string;
	pageUrl: string;
	settingsUrl: string;
	userId: number;
}

declare global {
	interface Window {
		gdsContentTranslation?: Settings;
	}
}

const fallback: Settings = {
	postTypes: [],
	postType: '',
	languages: [],
	machineTranslation: false,
	copyTranslation: false,
	review: false,
	followup: 'manual',
	followups: { manual: '', apply: '', apply_review: '' },
	publish: false,
	createUrl: '',
	reviewRunUrl: '',
	pageUrl: '',
	settingsUrl: '',
	userId: 0,
};

export function getSettings(): Settings {
	return window.gdsContentTranslation ?? fallback;
}

/** Every language but the default one: a column each. */
export function translationLanguages( settings: Settings = getSettings() ): Language[] {
	return settings.languages.filter( ( language ) => ! language.isDefault );
}

export function defaultLanguage( settings: Settings = getSettings() ): Language | undefined {
	return settings.languages.find( ( language ) => language.isDefault );
}

export function languageCode( slug: string, settings: Settings = getSettings() ): string {
	return settings.languages.find( ( language ) => language.slug === slug )?.code ?? slug.toUpperCase();
}

export function languageName( slug: string, settings: Settings = getSettings() ): string {
	return settings.languages.find( ( language ) => language.slug === slug )?.name ?? slug.toUpperCase();
}
