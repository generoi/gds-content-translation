/**
 * What GET /reviews/{id} sends (AuditReview::remarkRows()).
 */
export type Severity = 'error' | 'warning' | 'suggestion';

/** A remark's state; `none` is a text without remarks. */
export type RemarkState = 'open' | 'resolved' | 'dismissed' | 'none';

export type RemarkOperation = 'use' | 'edit' | 'fixed' | 'dismiss' | 'reopen';

export interface RemarkRow {
	/** `r12` for remark 12, `s5` for text 5 without remarks. */
	id: string;
	remarkId: number;
	segment: number;
	/** Which field: the ACF field's label, or Title, Excerpt, SEO title and so on; empty for the content. */
	label: string;
	/** HTML, sanitised by the server. */
	source: string;
	translation: string;
	/** The translation as stored, for Edit…: not sanitised, never rendered as HTML. */
	text: string;
	/** The text was changed in the editor after the review. */
	changed: boolean;
	severity: Severity | '';
	category: string;
	categoryLabel: string;
	issue: string;
	suggestion: string;
	/** HTML: the translation with the suggestion's changes as <del>/<ins>; empty when the texts are too long to compare. */
	suggestionDiff: string;
	/** HTML, sanitised by the server: the suggestion as it is, shown when there is no diff. */
	suggestionText: string;
	state: RemarkState;
	stateLabel: string;
}

export interface OpenCounts {
	error: number;
	warning: number;
	suggestion: number;
}

export interface ReviewData {
	rows: RemarkRow[];
	counts: OpenCounts;
	sourceLanguage: string;
	targetLanguage: string;
}

export interface RemarksResponse extends ReviewData {
	results: Array< { id: number; ok: boolean; message: string } >;
}
