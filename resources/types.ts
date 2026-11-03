/**
 * The shapes the REST API sends (src/StatusList.php, src/Rest/StatusController.php).
 */
export type AuditStatus = 'none' | 'pending' | 'stuck' | 'failed' | 'done';

export interface Audit {
	status: AuditStatus;
	errors: number;
	other: number;
	edited: boolean;
	error: string;
	url: string;
}

export type JobStatus = 'queued' | 'running' | 'done' | 'skipped' | 'failed';

export interface CellJob {
	status: JobStatus;
	error: string;
	queuedAt: number;
}

/** The last check of a translation's page (PageScan::statuses()). */
export interface CellScan {
	status: 'none' | 'queued' | 'running' | 'stuck' | 'done' | 'failed';
	/** Texts found in the wrong language. */
	found: number;
}

export interface Cell {
	state: 'translated' | 'trashed' | 'missing';
	postId: number;
	/** The translation's post status: publish, draft, pending, private, future. */
	status?: string;
	editUrl?: string;
	viewUrl?: string;
	proofread?: boolean;
	openNotes?: number;
	canDelete?: boolean;
	audit?: Audit | null;
	/** Files, images and forms still in the original's language (MediaCheck). */
	media?: number;
	scan?: CellScan;
	job?: CellJob | null;
}

export interface Row {
	id: string;
	sourceId: number;
	title: string;
	date: string;
	status: string;
	editUrl: string;
	viewUrl: string;
	openNotes: number;
	canTrashAll: boolean;
	translationCount: number;
	languages: Record< string, Cell >;
}

export interface SummaryItem {
	name: string;
	slug: string;
	count: number;
}

export interface Summary {
	total: number;
	postTypeLabel: string;
	defaultLanguageName: string;
	missing: SummaryItem[];
	notProofread: SummaryItem[];
	aiErrors: SummaryItem[];
	reviewRunning: SummaryItem[];
	media: SummaryItem[];
	/** The site-wide check of files and images: translations checked of all, images queued for Claude. */
	mediaCheck: { checked: number; total: number; images: number };
}

export interface RowsResponse {
	rows: Row[];
	total: number;
	totalPages: number;
	summary: Summary;
}

export interface ActionResult {
	id: number;
	ok: boolean;
	title?: string;
	code?: string;
	message?: string;
	/** Trash and its Undo: the posts changed. Proof read: how many. */
	changed?: number[] | number;
	[ key: string ]: unknown;
}

export interface ActionResponse {
	ok: boolean;
	message: string;
	results: ActionResult[];
}

export interface Job {
	sourceId: number;
	language: string;
	status: JobStatus;
	error: string;
	queuedAt: number;
	finishedAt: number;
	mine: boolean;
	title: string;
	translationId: number;
	editUrl: string;
	reviewUrl: string;
}

/** What a cell is filtered by; the server's StatusList::filterKinds. */
export type FilterKind = 'missing' | 'unproofread' | 'errors' | 'running' | 'media';
