/**
 * The shapes of GET/POST /media/{id} (src/MediaCheck.php).
 */
export type MediaKind = 'document' | 'svg' | 'image' | 'form' | 'embed' | 'link';

export interface MediaItem {
	key: string;
	kind: MediaKind;
	/** open: to handle; ok: marked fine as it is; unchecked: an image Claude has not looked at yet. */
	state: 'open' | 'ok' | 'unchecked';
	title: string;
	url: string;
	editUrl?: string;
	attachmentId?: number;
	/** The words found: an SVG's text, or what Claude read in an image. */
	texts?: string[];
	thumbnail?: string;
}

export interface MediaData {
	items: MediaItem[];
	open: number;
	unchecked: number;
	canCheckImages: boolean;
	sourceLanguage: string;
	editUrl: string;
}
