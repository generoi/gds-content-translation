/**
 * The shapes of /strings and /alts (src/StringTranslation.php, src/ImageAlt.php).
 */
export interface StringRow {
	/** Polylang's key: the md5 of the text. */
	id: string;
	source: string;
	/** A theme's text as the default language shows it, when it is translated there. */
	original: string;
	name: string;
	group: string;
	multiline: boolean;
	html: boolean;
	/** Kept as it is in every language: a format, an address, a placeholder. */
	fixed: boolean;
	translation: string;
	remark: { issue: string; suggestion: string } | null;
}

export interface SlugRow {
	/** Polylang Pro's key: `tuote`, `category`, `paged`. */
	id: string;
	source: string;
	/** What the slug is the base of: "Products (post type)". */
	what: string;
	translation: string;
}

export interface StringsResponse {
	language: { slug: string; name: string; isDefault: boolean };
	rows: StringRow[];
	slugs: SlugRow[];
	/** Polylang Pro's Translate slugs module is on. */
	slugsAvailable: boolean;
	/** What each language is missing. */
	missing: Record< string, { texts: number; slugs: number } >;
	canTranslate: boolean;
	canReview: boolean;
	changed?: number;
}

export interface AltItem {
	id: number;
	title: string;
	file: string;
	thumbnail: string;
	editUrl: string;
	/** The media library's alt text, in the default language. */
	source: string;
	translation: string;
}

export interface AltsResponse {
	items: AltItem[];
	total: number;
	totalPages: number;
	missing: Record< string, number >;
	canTranslate: boolean;
}

export interface AltsChanged {
	alts: Record< string, string >;
	changed: number;
	missing: Record< string, number >;
}

export type StringsSection = 'texts' | 'slugs' | 'alts';
