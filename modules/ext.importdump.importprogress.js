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

	function showProgress( stage, step, steps, percent ) {
		if ( !stage ) {
			bar.setProgress( false );
			$label.text( mw.msg( 'importdump-import-waiting' ) );
			return;
		}

		// Messages that can be used here:
		// * importdump-stage-afterimport
		// * importdump-stage-counts
		// * importdump-stage-import
		// * importdump-stage-recentchanges
		// * importdump-stage-refreshlinks
		// * importdump-stage-sitestats
		// * importdump-stage-textindex
		const name = mw.msg( 'importdump-stage-' + stage );
		const stepNumber = mw.language.convertNumber( step );
		const stepCount = mw.language.convertNumber( steps );

		if ( percent === null ) {
			bar.setProgress( false );
			$label.text( mw.msg( 'importdump-import-step', stepNumber, stepCount, name ) );
			return;
		}

		bar.setProgress( percent );
		$label.text( mw.msg(
			'importdump-import-step-percent',
			stepNumber,
			stepCount,
			name,
			mw.language.convertNumber( percent )
		) );
	}

	// The rest of the page is rendered by the server, so it only changes after a reload.
	function showResult( status ) {
		const message = status === 'complete' ?
			'importdump-import-finished' :
			'importdump-import-stopped';

		const $reload = $( '<a>' )
			.attr( 'href', window.location.href )
			.text( mw.msg( 'importdump-verification-reload' ) );

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

			showProgress( result.stage, result.step, result.steps, result.percent );
			setTimeout( () => poll( 0 ), pollInterval );
		}, () => {
			if ( failures + 1 < maxFailures ) {
				setTimeout( () => poll( failures + 1 ), pollInterval * 2 );
			}
		} );
	}

	setTimeout( () => poll( 0 ), pollInterval );
}() );
