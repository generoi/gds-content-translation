/**
 * What GET /scans/{id} and GET /scans/{id}/estimate send (PageScan::data(),
 * PageScan::estimate()).
 */
export type ScanStatus = 'none' | 'queued' | 'running' | 'stuck' | 'done' | 'failed';

/** Where on the page a text is: its outermost landmark (PageText::regions). */
export type ScanRegion = 'head' | 'header' | 'nav' | 'main' | 'aside' | 'footer' | 'body';

export type ScanSourceType = 'content' | 'pattern' | 'template' | 'theme' | 'media' | 'string' | 'menu' | 'script' | 'other';

export interface ScanFinding {
	id: number;
	text: string;
	where: ScanRegion[];
	/** What the text is on the page: text, alt, title, aria-label, placeholder, value, meta, jsonld, svg. */
	kinds: string[];
	language: string;
	suggestion: string;
	source: { type: ScanSourceType; label: string; url: string };
}

export interface ScanEstimate {
	texts: number;
	calls: number;
	characters: number;
	tokens: number;
	outputTokens: number;
	/** US dollars. */
	price: number;
	model: string;
	modelLabel: string;
}

export interface ScanResult {
	status: 'done' | 'failed';
	createdAt: number;
	url: string;
	preview: boolean;
	model: string;
	texts: number;
	regions: Partial< Record< ScanRegion, number > >;
	estimate: ScanEstimate | null;
	error: string;
	/** The translation changed after the check. */
	changed: boolean;
	findings: ScanFinding[];
}

export interface ScanData {
	postId: number;
	status: ScanStatus;
	available: boolean;
	sourceLanguage: string;
	targetLanguage: string;
	published: boolean;
	result: ScanResult | null;
}

export interface PageEstimate extends ScanEstimate {
	url: string;
	preview: boolean;
	/** The page has the same texts as at the last check. */
	unchanged: boolean;
}
