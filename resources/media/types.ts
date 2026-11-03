/**
 * The shapes of /media/{id} (src/MediaCheck.php, src/MediaActions.php).
 */
export type MediaKind = 'document' | 'svg' | 'image' | 'form' | 'embed' | 'link';

export type MediaAction = 'replace' | 'restore' | 'svg-translate' | 'svg-labels' | 'document-translate' | 'document-use' | 'document-discard';

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
	 * not looked at yet; translated: a translated copy (or a translated form).
	 */
	state: 'open' | 'ok' | 'unchecked' | 'translated';
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
	editUrl: string;
	forms: Array< { id: number; title: string } >;
	documentCost: DocumentCost | null;
	formTranslation: Record< number, FormProgress >;
}
