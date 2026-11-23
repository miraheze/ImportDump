( function () {
	const $output = $( '.ext-importdump-output' );
	if ( !$output.length ) {
		return;
	}

	const pollInterval = 2000;
	const maxFailures = 5;
	const requestId = $output.data( 'request-id' );
	const api = new mw.Api();

	function show( text ) {
		const element = $output[ 0 ];
		const atBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 20;
		$output.text( text || mw.msg( 'importdump-output-empty' ) );
		if ( atBottom ) {
			element.scrollTop = element.scrollHeight;
		}
	}

	// The output is already on the page, so it only needs to be followed while the import runs.
	function poll( failures ) {
		api.get( {
			action: 'requestimportoutput',
			requestid: requestId,
			formatversion: 2
		} ).then( ( data ) => {
			const result = data.requestimportoutput;
			show( result.output );
			if ( result.running ) {
				setTimeout( () => poll( 0 ), pollInterval );
			}
		}, () => {
			if ( failures + 1 < maxFailures ) {
				setTimeout( () => poll( failures + 1 ), pollInterval * 2 );
			}
		} );
	}

	$output[ 0 ].scrollTop = $output[ 0 ].scrollHeight;
	if ( $output.data( 'running' ) ) {
		setTimeout( () => poll( 0 ), pollInterval );
	}
}() );
