import { ErrorBoundary } from '../ui/error-boundary';
import { StringsScreen } from './strings-screen';

export function StringsApp() {
	return (
		<ErrorBoundary context="strings">
			<StringsScreen />
		</ErrorBoundary>
	);
}
