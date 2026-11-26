( function () {
	const $output = $( '.ext-importdump-output' );
	if ( !$output.length ) {
		return;
	}

	const pollInterval = 2000;
	const maxFailures = 5;
	const requestId = $output.data( 'request-id' );
	const container = $output[ 0 ];
	const api = new mw.Api();

	// The stored parts, by number, with an empty block of the right height until they are loaded.
	const parts = {};
	const $live = $( '<span>' ).addClass( 'ext-importdump-output-live' );
	$output.append( $live );

	let started = false;
	let liveText = null;

	const observer = new IntersectionObserver( ( entries ) => {
		entries.forEach( ( entry ) => {
			if ( entry.isIntersecting ) {
				load( $( entry.target ) );
			}
		} );
	}, { root: container, rootMargin: '2000px 0px' } );

	function load( $block ) {
		if ( $block.data( 'loaded' ) || $block.data( 'loading' ) ) {
			return;
		}

		$block.data( 'loading', true );
		api.get( {
			action: 'requestimportoutput',
			requestid: requestId,
			part: $block.data( 'part' ),
			formatversion: 2
		} ).then( ( data ) => {
			$block.text( data.requestimportoutput.text ).data( 'loaded', true );
			observer.unobserve( $block[ 0 ] );
		} ).always( () => {
			$block.data( 'loading', false );
		} );
	}

	function addPart( info ) {
		if ( parts[ info.part ] ) {
			return;
		}

		// The height is in lines of the text, so it is right whatever the font is.
		const $block = $( '<span>' )
			.addClass( 'ext-importdump-output-part' )
			.data( 'part', info.part )
			.css( 'height', info.lines + 'lh' );

		const later = Object.keys( parts ).map( Number )
			.filter( ( number ) => number > info.part )
			.sort( ( first, second ) => first - second );

		const $next = later.length ? parts[ later[ 0 ] ] : $live;
		$block.insertBefore( $next );
		parts[ info.part ] = $block;
		observer.observe( $block[ 0 ] );
	}

	function update( result ) {
		const top = container.scrollTop;
		const atBottom = container.scrollHeight - top - container.clientHeight < 20;

		result.parts.forEach( addPart );

		// What is being printed is also stored once its part is done, so it is shown until then.
		const live = result.live && !parts[ result.live.part ] ? result.live.text : '';
		const empty = !result.parts.length && !live;
		const text = empty && !result.running ? mw.msg( 'requestimport-output-empty' ) : live;

		// Only touch the text when it changed, so that nothing moves for a reader who is scrolled.
		if ( text !== liveText ) {
			$live.text( text );
			liveText = text;
		}

		// A running import is followed from the end, and a finished one is read from the start.
		if ( result.running && ( atBottom || !started ) ) {
			container.scrollTop = container.scrollHeight;
		} else {
			container.scrollTop = top;
		}

		started = true;
	}

	function poll( failures ) {
		api.get( {
			action: 'requestimportoutput',
			requestid: requestId,
			formatversion: 2
		} ).then( ( data ) => {
			const result = data.requestimportoutput;
			update( result );
			if ( result.running ) {
				setTimeout( () => poll( 0 ), pollInterval );
			}
		}, () => {
			if ( failures + 1 < maxFailures ) {
				setTimeout( () => poll( failures + 1 ), pollInterval * 2 );
			}
		} );
	}

	poll( 0 );
}() );
