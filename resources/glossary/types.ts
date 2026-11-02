/**
 * The glossary's REST shapes (Rest\GlossaryController).
 */
export type TermStatus = 'approved' | 'draft' | 'suggested' | 'rejected';

/** Where a term came from: a CSV, typed in, the AI review, an editor's proofreading. */
export type TermOrigin = 'import' | 'manual' | 'review' | 'proofread';

export type BulkOperation = 'approve' | 'reject' | 'draft' | 'delete';

export interface Term {
	id: number;
	sourceLang: string;
	targetLang: string;
	source: string;
	target: string;
	status: TermStatus;
	origin: TermOrigin;
	note: string;
	/** The source sentence a suggestion was seen in; may be ''. */
	example: string;
	postId: number;
	postTitle: string;
	/** '' when there is none. */
	postEditUrl: string;
	/** How many times the review or editors confirmed it. */
	hits: number;
	/** Another approved term of the pair has the same source and another target. */
	conflict: boolean;
	/** A suggestion's: the target of the approved term it would replace, or ''. */
	replaces: string;
	updatedAt: string;
}

export interface Sync {
	/** A DeepL key is set. */
	configured: boolean;
	glossaryId: string;
	/** ISO 8601, or '' when never sent. */
	syncedAt: string;
	/** Changes not sent yet. */
	pending: boolean;
	error: string;
	dictionaries: Array< { sourceLang: string; targetLang: string; entries: number } >;
}

export type Counts = Record< TermStatus, number >;

export interface TermsResponse {
	items: Term[];
	total: number;
	totalPages: number;
	counts: Counts;
	pairs: Array< { targetLang: string; approved: number } >;
	sync: Sync;
}

export interface TermInput {
	targetLang?: string;
	source?: string;
	target?: string;
	note?: string;
	status?: TermStatus;
}

export interface BulkResponse {
	changed: number;
	message: string;
}

export type ImportMode = 'merge' | 'replace';

export interface ImportResponse {
	added: number;
	updated: number;
	unchanged: number;
	skipped: number;
	pairs: string[];
	message: string;
}
