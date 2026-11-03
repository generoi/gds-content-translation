/**
 * The shapes of /media/{id} (src/MediaCheck.php, src/ContentGaps.php,
 * src/MediaActions.php).
 */
export type MediaKind = 'document' | 'svg' | 'image' | 'form' | 'embed' | 'link' | 'query' | 'page' | 'placeholder';

/** `translate` is the status screen's REST action, run on the original of a linked page or chosen post. */
export type MediaAction = 'replace' | 'restore' | 'svg-translate' | 'svg-labels' | 'document-translate' | 'document-use' | 'document-discard' | 'query-update' | 'translate';

/** A post a query loop chose that the translation does not show. */
export interface ChosenPost {
	/** The original's ID; 0 when the check could not tell which post it was. */
	id: number;
	title: string;
	/** The original in the editor. */
	url: string;
	field: 'include' | 'parents';
	/** Its translation in the language, when it has one by now. */
	translationId: number;
	published: boolean;
}

export interface DocumentJob {
	status: 'translating' | 'ready' | 'failed';
	error: string;
	secondsRemaining: number;
	billed: number;
	draft: { id: number; title: string; url: string } | null;
}

export interface MediaItem {
	key: string;
	kind: MediaKind;
	/**
	 * open: to handle; ok: marked fine as it is; unchecked: an image Claude has
	 * not looked at yet; not-checked: an image Claude could not check (see
	 * notChecked); translated: a translated copy (or a translated form).
	 */
	state: 'open' | 'ok' | 'unchecked' | 'not-checked' | 'translated';
	/** Why Claude could not check an image: its file is too large, could not be read, or Claude failed (asked again later). */
	notChecked?: 'too-large' | 'unreadable' | 'failed';
	title: string;
	url: string;
	editUrl?: string;
	attachmentId?: number;
	formId?: number;
	/** The words found: an SVG's text, or what Claude read in an image. */
	texts?: string[];
	thumbnail?: string;
	actions: MediaAction[];
	/** A translated SVG: its labels, and whether it was made from the original as it is now. */
	labels?: Array< { source: string; translation: string } >;
	current?: boolean;
	job?: DocumentJob;
	/** A DeepL draft of this file in this language exists already, made for another translation. */
	draftExists?: boolean;
	/** Where the translation shows it: 0 the page itself, else its synced patterns. Actions change each. */
	places?: number[];
	/** In a synced pattern the page shows: its ID and title. Actions change the pattern. */
	in?: number;
	inTitle?: string;
	/** The pattern is still the original's, so nothing here can be changed for this translation. */
	inOriginal?: boolean;
	/** Also in a pattern that is still the original's, which actions leave as it is. */
	inOriginalTitle?: string;
	/** A query loop: the chosen posts it does not show. */
	posts?: ChosenPost[];
	/** A linked page: its original, and its translation when that is a draft. */
	sourceId?: number;
	translationId?: number;
	href?: string;
	/** A placeholder: the original's texts with it; `inSource` when only the original has it. */
	sourceTexts?: string[];
	inSource?: boolean;
	/** Polylang does not translate media: the image's alt text, the media library's and the translation language's. */
	alt?: { source: string; translation: string };
}

export interface DocumentCost {
	characters: number;
	/** Euros, on DeepL API Pro; 0 on the free plan. */
	price: number;
	free: boolean;
	used: number;
	/** 0: no limit. */
	limit: number;
	enough: boolean;
}

export interface FormProgress {
	total: number;
	translated: number;
	remarks: number;
	url: string;
}

export interface MediaData {
	items: MediaItem[];
	open: number;
	unchecked: number;
	canCheckImages: boolean;
	sourceLanguage: string;
	targetLanguage: string;
	/** The translation's language slug. */
	targetSlug: string;
	/** AI translation is set up, for the linked pages and chosen posts. */
	canTranslate: boolean;
	/** DeepL can translate an image's alt text into it. */
	canTranslateAlts: boolean;
	editUrl: string;
	forms: Array< { id: number; title: string } >;
	documentCost: DocumentCost | null;
	formTranslation: Record< number, FormProgress >;
}
