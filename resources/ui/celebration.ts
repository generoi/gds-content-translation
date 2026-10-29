/**
 * The proof-read celebration: a burst of emoji (or confetti) from the
 * checkbox. One canvas for the page, one particle pool and one animation
 * loop that every burst joins, so checking several rows in a row does not
 * jank. Emoji are drawn from sprites rendered once; motion is time-based, so
 * it looks the same at 60 and 120 Hz. Nothing at all with reduced motion.
 */
const EMOJIS = [ '🦄', '⭐', '✨', '🌟', '💫', '🎉', '🥳', '👏', '💚', '🎊', '✅', '🌈', '🔥', '💎', '🍀' ];
const COLORS = [ '#007017', '#72aee6', '#dba617', '#d63638', '#9b51e0', '#00a32a' ];
const PARTICLES_PER_BURST = 36;
const MAX_PARTICLES = 150;
const LIFETIME = 2200;
const GRAVITY = 0.38;
const DRAG = 0.988;
const FRAME = 1000 / 60;

type Sprite = HTMLCanvasElement | OffscreenCanvas;

interface Particle {
	x: number;
	y: number;
	vx: number;
	vy: number;
	rotation: number;
	spin: number;
	born: number;
	opacity: number;
	sprite?: Sprite;
	size?: number;
	w?: number;
	h?: number;
	color?: string;
}

const state = {
	canvas: null as HTMLCanvasElement | null,
	ctx: null as CanvasRenderingContext2D | null,
	particles: [] as Particle[],
	running: false,
	last: 0,
	width: 0,
	height: 0,
	dpr: 1,
	sprites: new Map< string, Sprite >(),
};

function pick< T >( list: T[] ): T {
	return list[ Math.floor( Math.random() * list.length ) ] as T;
}

function canvas(): CanvasRenderingContext2D | null {
	if ( ! state.canvas ) {
		state.canvas = document.createElement( 'canvas' );
		state.canvas.className = 'gds-ct__celebration';
		state.canvas.setAttribute( 'aria-hidden', 'true' );
		state.canvas.width = 0;
		state.canvas.height = 0;
		document.body.appendChild( state.canvas );
		state.ctx = state.canvas.getContext( '2d' );
	}

	return state.ctx;
}

function sizeCanvas(): void {
	const dpr = Math.min( window.devicePixelRatio || 1, 2 );
	const { innerWidth: width, innerHeight: height } = window;

	if ( ! state.canvas || ( state.width === width && state.height === height && state.dpr === dpr && state.canvas.width > 0 ) ) {
		return;
	}

	state.width = width;
	state.height = height;
	state.dpr = dpr;
	state.canvas.width = Math.round( width * dpr );
	state.canvas.height = Math.round( height * dpr );
}

function sprite( emoji: string ): Sprite {
	const key = `${ emoji }@${ state.dpr }`;
	const existing = state.sprites.get( key );

	if ( existing ) {
		return existing;
	}

	const size = Math.ceil( 32 * state.dpr );
	const made: Sprite = typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas( size, size ) : document.createElement( 'canvas' );
	made.width = size;
	made.height = size;

	const ctx = made.getContext( '2d' ) as CanvasRenderingContext2D | OffscreenCanvasRenderingContext2D | null;

	if ( ctx ) {
		ctx.font = `${ Math.round( 26 * state.dpr ) }px serif`;
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.fillText( emoji, size / 2, size / 2 + state.dpr );
	}

	state.sprites.set( key, made );

	return made;
}

function burst( originX: number, originY: number, now: number ): void {
	const confetti = Math.floor( Math.random() * ( EMOJIS.length + 1 ) ) === 0;
	const emojiSprite = confetti ? undefined : sprite( pick( EMOJIS ) );

	for ( let i = 0; i < PARTICLES_PER_BURST; i++ ) {
		const angle = Math.random() * Math.PI * 2;
		const speed = 7 + Math.random() * 11;

		state.particles.push( {
			x: originX + ( Math.random() - 0.5 ) * 12,
			y: originY + ( Math.random() - 0.5 ) * 8,
			vx: Math.cos( angle ) * speed,
			vy: Math.sin( angle ) * speed - ( 6 + Math.random() * 8 ),
			rotation: Math.random() * Math.PI * 2,
			spin: ( ( Math.random() - 0.5 ) * 14 * Math.PI ) / 180,
			born: now,
			opacity: 1,
			...( emojiSprite
				? { sprite: emojiSprite, size: 22 + Math.random() * 8 }
				: { w: 8 + Math.random() * 6, h: 12 + Math.random() * 8, color: pick( COLORS ) } ),
		} );
	}

	if ( state.particles.length > MAX_PARTICLES ) {
		state.particles.splice( 0, state.particles.length - MAX_PARTICLES );
	}
}

function stop(): void {
	state.running = false;
	state.particles = [];

	if ( state.canvas ) {
		state.canvas.style.visibility = 'hidden';
		// Give the backing store back while idle.
		state.canvas.width = 0;
		state.canvas.height = 0;
	}

	state.width = 0;
}

function frame( now: number ): void {
	const ctx = state.ctx;

	if ( ! ctx || ! state.canvas ) {
		return;
	}

	const dt = Math.min( now - state.last, 50 ) / FRAME;
	const drag = Math.pow( DRAG, dt );
	const dpr = state.dpr;

	state.last = now;
	ctx.setTransform( 1, 0, 0, 1, 0, 0 );
	ctx.clearRect( 0, 0, state.canvas.width, state.canvas.height );

	const alive: Particle[] = [];

	for ( const p of state.particles ) {
		const age = now - p.born;

		p.vx *= drag;
		p.vy += GRAVITY * dt;
		p.x += p.vx * dt;
		p.y += p.vy * dt;
		p.rotation += p.spin * dt;
		p.opacity = age > LIFETIME * 0.55 ? Math.max( 0, 1 - ( age - LIFETIME * 0.55 ) / ( LIFETIME * 0.45 ) ) : 1;

		if ( p.opacity <= 0 || age > LIFETIME || p.y > state.height + 60 || p.x < -40 || p.x > state.width + 40 ) {
			continue;
		}

		alive.push( p );

		const cos = Math.cos( p.rotation ) * dpr;
		const sin = Math.sin( p.rotation ) * dpr;

		ctx.globalAlpha = p.opacity;
		ctx.setTransform( cos, sin, -sin, cos, p.x * dpr, p.y * dpr );

		if ( p.sprite && p.size ) {
			ctx.drawImage( p.sprite, -p.size / 2, -p.size / 2, p.size, p.size );
		} else if ( p.w && p.h ) {
			ctx.fillStyle = p.color ?? COLORS[ 0 ]!;
			ctx.fillRect( -p.w / 2, -p.h / 2, p.w, p.h );
		}
	}

	ctx.globalAlpha = 1;
	state.particles = alive;

	if ( alive.length ) {
		window.requestAnimationFrame( frame );
	} else {
		stop();
	}
}

export function celebrate( anchor: Element ): void {
	if ( window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches || ! canvas() ) {
		return;
	}

	const rect = anchor.getBoundingClientRect();
	const now = performance.now();

	sizeCanvas();
	burst( rect.left + rect.width / 2, rect.top + rect.height / 2, now );

	if ( ! state.running && state.canvas ) {
		state.running = true;
		state.last = now;
		state.canvas.style.visibility = 'visible';
		window.requestAnimationFrame( frame );
	}
}

window.addEventListener( 'resize', () => {
	if ( state.running ) {
		sizeCanvas();
	}
} );
