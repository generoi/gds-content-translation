/**
 * The one place DataViews is imported from.
 *
 * The `/wp` entry brings its own copy of @wordpress/components, so it never
 * clashes with the one WordPress ships; the screen's own chrome around it
 * (tabs, summary, progress, notices) uses core's wp-components.
 */
export { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
export type { Action, Field, View, SupportedLayouts, RenderModalProps } from '@wordpress/dataviews/wp';
