import { ErrorBoundary } from '../ui/error-boundary';
import { GlossaryScreen } from './glossary-screen';

export function GlossaryApp() {
	return (
		<ErrorBoundary context="glossary">
			<GlossaryScreen />
		</ErrorBoundary>
	);
}
