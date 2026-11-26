( function () {
	const $container = $( '.ext-importdump-import' );
	if ( !$container.length ) {
		return;
	}

	const pollInterval = 3000;
	const maxFailures = 5;

	const requestId = $container.data( 'request-id' );
	const $label = $container.find( '.ext-importdump-import-label' );
	const bar = OO.ui.infuse( $( '#ext-importdump-import-bar' ) );
	const api = new mw.Api();

	function showProgress( stage, step, steps, percent, done, total ) {
		if ( !stage ) {
			bar.setProgress( false );
			$label.text( mw.msg( 'requestimport-import-waiting' ) );
			return;
		}

		// Messages that can be used here:
		// * requestimport-stage-afterimport
		// * requestimport-stage-counts
		// * requestimport-stage-download
		// * requestimport-stage-import
		// * requestimport-stage-recentchanges
		// * requestimport-stage-refreshlinks
		// * requestimport-stage-sitestats
		// * requestimport-stage-textindex
		const name = mw.msg( 'requestimport-stage-' + stage );
		const stepNumber = mw.language.convertNumber( step );
		const stepCount = mw.language.convertNumber( steps );

		if ( percent === null ) {
			bar.setProgress( false );
			$label.text( mw.msg( 'requestimport-import-step', stepNumber, stepCount, name ) );
			return;
		}

		bar.setProgress( percent );
		if ( done !== null && total !== null ) {
			$label.text( mw.msg(
				'requestimport-import-step-pages',
				stepNumber,
				stepCount,
				name,
				mw.language.convertNumber( done ),
				mw.language.convertNumber( total ),
				mw.language.convertNumber( percent )
			) );
			return;
		}

		$label.text( mw.msg(
			'requestimport-import-step-percent',
			stepNumber,
			stepCount,
			name,
			mw.language.convertNumber( percent )
		) );
	}

	// The rest of the page is rendered by the server, so it only changes after a reload.
	function showResult( status ) {
		const message = status === 'complete' ?
			'requestimport-import-finished' :
			'requestimport-import-stopped';

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
			if ( result.status !== 'starting' && result.status !== 'inprogress' ) {
				showResult( result.status );
				return;
			}

			showProgress(
				result.stage,
				result.step,
				result.steps,
				result.percent,
				result.done,
				result.total
			);
			setTimeout( () => poll( 0 ), pollInterval );
		}, () => {
			if ( failures + 1 < maxFailures ) {
				setTimeout( () => poll( failures + 1 ), pollInterval * 2 );
			}
		} );
	}

	setTimeout( () => poll( 0 ), pollInterval );
}() );
