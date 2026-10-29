import { ErrorBoundary } from '../ui/error-boundary';
import { Notices } from '../ui/notices';
import { ReviewScreen } from './review-screen';

export function ReviewApp( { postId }: { postId: number } ) {
	return (
		<ErrorBoundary context="review">
			<ReviewScreen postId={ postId } />
			<Notices />
		</ErrorBoundary>
	);
}
