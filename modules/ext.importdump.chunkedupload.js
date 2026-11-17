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

	function sendChunk( file, start, end, filekey, onProgress, retries ) {
		const data = {
			action: 'requestimportupload',
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
					return new Promise( ( resolve ) => {
						setTimeout( resolve, 1000 );
					} ).then(
						() => sendChunk( file, start, end, filekey, onProgress, retries - 1 )
					);
				}
				return $.Deferred().reject( { code, result } );
			}
		);
	}

	async function uploadFile( file, onProgress ) {
		const chunkSize = Math.min(
			Math.max( preferredChunkSize, config.minChunkSize || 0 ),
			getChunkLimit()
		);

		let filekey = '';
		let offset = 0;
		let result;

		while ( offset < file.size ) {
			const end = Math.min( offset + chunkSize, file.size );
			result = await sendChunk( file, offset, end, filekey, onProgress, maxRetries );
			filekey = result.filekey;
			offset = end;
			onProgress( offset );
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
