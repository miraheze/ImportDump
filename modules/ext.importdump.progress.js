( function () {
	const $container = $( '.ext-importdump-verification' );
	if ( !$container.length ) {
		return;
	}

	const pollInterval = 3000;
	const maxFailures = 5;

	const requestId = $container.data( 'request-id' );
	const $label = $container.find( '.ext-importdump-verification-label' );
	const bar = OO.ui.infuse( $( '#ext-importdump-verification-bar' ) );
	const api = new mw.Api();

	function showProgress( stage, percent ) {
		// Messages that can be used here:
		// * requestimport-verification-checking
		// * requestimport-verification-extracting
		// * requestimport-verification-joining
		// * requestimport-verification-merging
		// * requestimport-verification-storing
		// * requestimport-verification-waiting
		const text = mw.msg( 'requestimport-verification-' + stage );
		if ( percent === null ) {
			bar.setProgress( false );
			$label.text( text );
			return;
		}

		bar.setProgress( percent );
		$label.text(
			mw.msg( 'requestimport-verification-label', text, mw.language.convertNumber( percent ) )
		);
	}

	// The handling options are rendered by the server, so they only change after a reload.
	// That is left to the handler, because it would lose anything they have typed.
	function showResult( status ) {
		const message = status === 'declined' ?
			'requestimport-verification-rejected' :
			'requestimport-verification-done';

		const $reload = $( '<a>' )
			.attr( 'href', '#' )
			.text( mw.msg( 'requestimport-verification-reload' ) )
			.on( 'click', ( e ) => {
				e.preventDefault();
				window.location.reload();
			} );

		bar.$element.remove();
		$label.text( mw.msg( message ) ).append( ' ', $reload );
	}

	function poll( failures ) {
		if ( document.hidden ) {
			setTimeout( () => poll( failures ), pollInterval );
			return;
		}

		api.get( {
			action: 'requestimportprogress',
			requestid: requestId,
			formatversion: 2
		} ).then( ( data ) => {
			const result = data.requestimportprogress;
			if ( result.status !== 'verifying' ) {
				showResult( result.status );
				return;
			}

			showProgress( result.stage, result.percent );
			setTimeout( () => poll( 0 ), pollInterval );
		}, () => {
			if ( failures + 1 < maxFailures ) {
				setTimeout( () => poll( failures + 1 ), pollInterval * 2 );
			}
		} );
	}

	setTimeout( () => poll( 0 ), pollInterval );
}() );
