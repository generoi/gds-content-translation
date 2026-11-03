/**
 * What a check of pages costs, said before it is asked for: what is sent,
 * how many tokens that is, and the price at the model's.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

export function dollars( value: number ): string {
	// A cent or two is the usual price: show it, not $0.00.
	return new Intl.NumberFormat( undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: value < 0.1 ? 3 : 2 } ).format( value );
}

export interface CostFigures {
	texts: number;
	characters: number;
	tokens: number;
	outputTokens: number;
	price: number;
	modelLabel?: string;
}

export function costLines( cost: CostFigures, pages = 1 ): string[] {
	return [
		pages > 1
			? sprintf(
					/* translators: 1: number of texts, 2: number of pages, 3: number of characters */
					__( '%1$s texts of %2$d pages, %3$s characters, are sent to Claude.', 'gds-content-translation' ),
					cost.texts.toLocaleString(),
					pages,
					cost.characters.toLocaleString()
			  )
			: sprintf(
					/* translators: 1: number of texts, 2: number of characters */
					_n( '%1$s text of the page, %2$s characters, is sent to Claude.', '%1$s texts of the page, %2$s characters, are sent to Claude.', cost.texts, 'gds-content-translation' ),
					cost.texts.toLocaleString(),
					cost.characters.toLocaleString()
			  ),
		sprintf(
			/* translators: 1: price, e.g. $0.04, 2: input tokens, 3: answer tokens, 4: model name */
			__( 'About %1$s: %2$s tokens sent and at most about %3$s for the answer, with %4$s. The answer’s length is a guess, so the real price is usually lower.', 'gds-content-translation' ),
			dollars( cost.price ),
			cost.tokens.toLocaleString(),
			cost.outputTokens.toLocaleString(),
			cost.modelLabel ?? 'Claude'
		),
	];
}
