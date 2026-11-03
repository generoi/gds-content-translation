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

export interface FollowupOption {
	label: string;
	description: string;
}

export interface PostTypeTab {
	slug: string;
	label: string;
	icon: { type: 'dashicon' | 'image'; value: string };
}

/** What the Translation glossary screen gets on top. */
export interface GlossarySettings {
	/** The default language's code: "EN". */
	sourceLang: string;
	/** The other languages, with names in the admin's language. */
	targetLangs: Array< { code: string; name: string } >;
	/** Every approved term as DeepL's CSV; `target_lang` and `status` narrow it. */
	exportUrl: string;
	/** DeepL is the machine translation of Polylang. */
	deepl: boolean;
	/** The AI review is on, and uses the glossary too. */
	review: boolean;
	/** The Translation glossary screen. */
	url: string;
	/** Each translation language (Polylang slug): its code and approved terms. */
	languages: Record< string, { code: string; terms: number } >;
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
	/** Every follow-up with its title and one line of help, in order. */
	/** The site's instructions for the AI review (settings), shown as a hint. */
	instructions: string;
	instructionsMax: number;
	followups: Record< Followup, FollowupOption >;
	/** AI translations are published when their original is. */
	publish: boolean;
	createUrl: string;
	reviewRunUrl: string;
	pageUrl: string;
	settingsUrl: string;
	userId: number;
	glossary?: GlossarySettings;
	/** Polylang → Form translations, when Gravity Forms is active. */
	forms?: { available: boolean; url: string };
	/** Polylang → String translations. */
	strings?: {
		url: string;
		/** Polylang's own Strings translations screen. */
		polylangUrl: string;
		/** Polylang does not translate media: an image has an alt text per language. */
		alts: boolean;
		/** DeepL is set up. */
		translate: boolean;
	};
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
	instructions: '',
	instructionsMax: 4000,
	followups: {
		manual: { label: '', description: '' },
		apply: { label: '', description: '' },
		apply_review: { label: '', description: '' },
	},
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
