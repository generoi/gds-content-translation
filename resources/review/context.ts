import { createContext } from '@wordpress/element';
import type { RemarkOperation, RemarkRow } from './types';

export interface ReviewContextValue {
	/** Runs an operation on remarks; resolves once the table is updated. */
	run: ( operation: RemarkOperation, rows: RemarkRow[], text?: string ) => Promise< boolean >;
	/** Opens the editing modal for a remark. */
	edit: ( row: RemarkRow ) => void;
	/** Remark IDs being changed. */
	busy: Set< number >;
}

export const ReviewContext = createContext< ReviewContextValue >( {
	run: () => Promise.resolve( false ),
	edit: () => undefined,
	busy: new Set(),
} );
