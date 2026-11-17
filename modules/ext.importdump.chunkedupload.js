( function () {
	const config = mw.config.get( 'wgRequestImportChunkedUpload' );
	if ( !config || !window.FormData ) {
		return;
	}

	const preferredChunkSize = 50 * 1024 * 1024;
	const maxRetries = 2;

	// The most that fits in one request, which is what chunks are sized against.
	function getChunkLimit() {
		return config.maxPhpUploadSize || Infinity;
	}

	// Files up to this size can use the normal upload without chunking.
	function getDirectLimit() {
		return config.maxDirectUploadSize || Infinity;
	}

	function wait( ms ) {
		return new Promise( ( resolve ) => {
			setTimeout( resolve, ms );
		} );
	}

	function sendChunk( file, start, end, filekey, onProgress, retries ) {
		const data = {
			action: 'requestimportupload',
			async: 1,
			chunk: file.slice( start, end ),
			filesize: file.size,
			offset: start,
			type: config.type
		};

		if ( filekey ) {
			data.filekey = filekey;
		}

		return new mw.Api().postWithEditToken( data, {
			contentType: 'multipart/form-data',
			timeout: 0,
			xhr: () => {
				const xhr = $.ajaxSettings.xhr();
				if ( xhr.upload ) {
					xhr.upload.addEventListener( 'progress', ( ev ) => {
						if ( ev.lengthComputable ) {
							onProgress( start + ev.loaded );
						}
					} );
				}
				return xhr;
			}
		} ).then(
			( result ) => result.requestimportupload,
			( code, result ) => {
				if ( code === 'http' && retries > 0 && result.textStatus !== 'abort' ) {
					return wait( 1000 ).then(
						() => sendChunk( file, start, end, filekey, onProgress, retries - 1 )
					);
				}
				return $.Deferred().reject( { code, result } );
			}
		);
	}

	function checkStatus( filekey ) {
		return new mw.Api().postWithEditToken( {
			action: 'requestimportupload',
			type: config.type,
			checkstatus: 1,
			filekey
		} ).then(
			( result ) => result.requestimportupload,
			( code, result ) => $.Deferred().reject( { code, result } )
		);
	}

	// Assembling a large file can outlast the request, so a timeout on the last chunk
	// does not mean it failed. Ask the server what happened before sending it again.
	async function sendLastChunk( file, start, filekey, onProgress ) {
		let lastError;
		for ( let attempt = 0; attempt <= maxRetries; attempt++ ) {
			try {
				return await sendChunk( file, start, file.size, filekey, onProgress, 0 );
			} catch ( error ) {
				if ( !error || error.code !== 'http' ) {
					throw error;
				}

				lastError = error;
				let progress = null;
				try {
					progress = await checkStatus( filekey );
				} catch ( ignored ) {
					// The status could not be read, so send the chunk again.
				}

				if ( progress && progress.result !== 'Continue' ) {
					return progress;
				}

				await wait( 1000 );
			}
		}

		throw lastError;
	}

	async function waitForAssembly( filekey, progress, onAssembling ) {
		let delay = 1000;
		let failures = 0;

		while ( progress.result === 'Poll' ) {
			onAssembling();
			await wait( delay );
			delay = Math.min( delay * 1.5, 10000 );

			try {
				progress = await checkStatus( filekey );
				failures = 0;
			} catch ( error ) {
				// Checking is safe to repeat, so allow a few network errors.
				failures++;
				if ( !error || error.code !== 'http' || failures > 5 ) {
					throw error;
				}
			}
		}

		return progress;
	}

	async function uploadFile( file, onProgress, onAssembling ) {
		const chunkSize = Math.min(
			Math.max( preferredChunkSize, config.minChunkSize || 0 ),
			getChunkLimit()
		);

		let filekey = '';
		let offset = 0;
		let result;

		while ( offset < file.size ) {
			const end = Math.min( offset + chunkSize, file.size );
			if ( end < file.size ) {
				result = await sendChunk( file, offset, end, filekey, onProgress, maxRetries );
			} else {
				result = await sendLastChunk( file, offset, filekey, onProgress );
			}

			filekey = result.filekey || filekey;
			offset = end;
			onProgress( offset );
		}

		if ( result.result === 'Poll' ) {
			result = await waitForAssembly( filekey, result, onAssembling );
		}

		if ( result.result !== 'Success' ) {
			throw Object.assign( new Error( 'unexpected-result' ), { code: 'unexpected-result' } );
		}

		return result.filekey;
	}

	$( () => {
		const $file = $( 'input[name="wpUploadFile"]' );
		const $key = $( 'input[name="wpUploadFileKey"]' );
		const $form = $file.closest( 'form' );
		if ( !$file.length || !$key.length || !$form.length ) {
			return;
		}

		const progress = new OO.ui.ProgressBarWidget( { progress: 0 } );
		const $status = $( '<p>' ).addClass( 'ext-importdump-chunkedupload-status' );
		const $wrapper = $( '<div>' )
			.addClass( 'ext-importdump-chunkedupload' )
			.append( progress.$element, $status )
			.hide();

		const $buttons = $form.find( '.mw-htmlform-submit-buttons' );
		if ( $buttons.length ) {
			$buttons.first().before( $wrapper );
		} else {
			$form.append( $wrapper );
		}

		const form = $form[ 0 ];
		let busy = false;
		let done = false;

		function fail( message ) {
			busy = false;
			progress.setProgress( 0 );
			$wrapper.hide();
			mw.notify( message, { type: 'error', autoHide: false } );
		}

		$form.on( 'submit', ( e ) => {
			if ( done ) {
				return;
			}

			const $source = $form.find( 'input[name="wpUploadSourceType"]:checked' );
			if ( $source.length && $source.val() !== 'File' ) {
				return;
			}

			const file = $file[ 0 ].files && $file[ 0 ].files[ 0 ];
			if ( !file || file.size <= getDirectLimit() ) {
				return;
			}

			e.preventDefault();
			if ( busy ) {
				return;
			}

			if ( config.maxUploadSize && file.size > config.maxUploadSize ) {
				fail( mw.message( 'largefileserver' ).text() );
				return;
			}

			busy = true;
			const submitter = e.originalEvent && e.originalEvent.submitter;
			$wrapper.show();
			$status.text( mw.message( 'importdump-chunkedupload-progress' ).text() );

			uploadFile( file, ( sent ) => {
				progress.setProgress( Math.min( 100, Math.round( ( sent / file.size ) * 100 ) ) );
			}, () => {
				$status.text( mw.message( 'importdump-chunkedupload-assembling' ).text() );
			} ).then( ( filekey ) => {
				$key.val( filekey );
				$file.val( '' ).prop( 'disabled', true );
				done = true;
				if ( form.requestSubmit && submitter ) {
					form.requestSubmit( submitter );
				} else {
					form.submit();
				}
			} ).catch( ( error ) => {
				const message = error && error.result ?
					new mw.Api().getErrorMessage( error.result ).text() :
					String( error && error.code );
				fail( mw.message( 'importdump-chunkedupload-error', message ).text() );
			} );
		} );

		$file.on( 'change', () => {
			$key.val( '' );
		} );
	} );
}() );
