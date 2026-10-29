/**
 * The bulk AI translation queue, from GET /jobs: polled every few seconds
 * while anything is queued or running, and once on load. Calls `onProgress`
 * whenever a job has finished since the last poll, so the rows show the new
 * translation.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { clearJobs, fetchJobs } from '../api';
import type { Job } from '../types';

const INTERVAL = 3000;

/** Polling slows down after this long without anything finishing. */
const SLOW_AFTER = 10 * 60 * 1000;

export interface JobsState {
	jobs: Job[];
	follow: () => void;
	cancelQueued: () => Promise< void >;
	dismissFinished: () => Promise< void >;
}

export function isActive( job: Job ): boolean {
	return job.status === 'queued' || job.status === 'running';
}

export function useJobs( onProgress: () => void ): JobsState {
	const [ jobs, setJobs ] = useState< Job[] >( [] );
	const timerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const finishedRef = useRef< number | null >( null );
	const lastChangeRef = useRef( 0 );
	const progressRef = useRef( onProgress );
	const pollRef = useRef< () => void >( () => undefined );

	useEffect( () => {
		progressRef.current = onProgress;
	}, [ onProgress ] );

	const poll = useCallback( () => {
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
			timerRef.current = null;
		}

		const later = ( delay: number ) => {
			timerRef.current = setTimeout( () => pollRef.current(), delay );
		};

		fetchJobs()
			.then( ( { jobs: next } ) => {
				const count = next.filter( ( job ) => ! isActive( job ) ).length;

				if ( finishedRef.current !== null && count !== finishedRef.current ) {
					lastChangeRef.current = Date.now();
					progressRef.current();
				}

				finishedRef.current = count;
				setJobs( next );

				if ( next.some( isActive ) ) {
					later( Date.now() - lastChangeRef.current > SLOW_AFTER ? INTERVAL * 5 : INTERVAL );
				}
			} )
			.catch( () => later( INTERVAL * 3 ) );
	}, [] );

	useEffect( () => {
		pollRef.current = poll;
		lastChangeRef.current = Date.now();
		poll();

		return () => {
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
			}
		};
	}, [ poll ] );

	const follow = useCallback( () => {
		lastChangeRef.current = Date.now();
		poll();
	}, [ poll ] );

	const cancelQueued = useCallback( async () => {
		await clearJobs( 'queued' );
		poll();
		progressRef.current();
	}, [ poll ] );

	const dismissFinished = useCallback( async () => {
		await clearJobs( 'finished' );
		poll();
		progressRef.current();
	}, [ poll ] );

	return { jobs, follow, cancelQueued, dismissFinished };
}
