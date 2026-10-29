import { StatusScreen } from './list/status-screen';
import { ErrorBoundary } from './ui/error-boundary';

export function App() {
	return (
		<ErrorBoundary context="screen">
			<StatusScreen />
		</ErrorBoundary>
	);
}
