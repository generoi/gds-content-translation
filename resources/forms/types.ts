/**
 * The shapes of /forms (src/FormTranslation.php).
 */
export interface FormProgress {
	total: number;
	translated: number;
	remarks: number;
}

export interface FormSummary {
	id: number;
	title: string;
	languages: Record< string, FormProgress >;
	editUrl: string;
}

export interface FormRow {
	source: string;
	/** What the text is: "“Email” – label". */
	context: string;
	translation: string;
	html: boolean;
	remark: { issue: string; suggestion: string } | null;
}

export interface FormRows {
	form: { id: number; title: string; editUrl: string };
	language: string;
	rows: FormRow[];
	canTranslate: boolean;
	canReview: boolean;
	changed?: number;
}
