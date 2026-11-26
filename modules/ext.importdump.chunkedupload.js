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

	function sendChunk( file, type, start, end, filekey, onProgress, retries ) {
		const data = {
			action: 'requestimportupload',
			chunk: file.slice( start, end ),
			filesize: file.size,
			offset: start,
			type
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
						() => sendChunk( file, type, start, end, filekey, onProgress, retries - 1 )
					);
				}
				return $.Deferred().reject( { code, result } );
			}
		);
	}

	function checkStatus( filekey, type ) {
		return new mw.Api().postWithEditToken( {
			action: 'requestimportupload',
			type,
			checkstatus: 1,
			filekey
		} ).then(
			( result ) => result.requestimportupload,
			( code, result ) => $.Deferred().reject( { code, result } )
		);
	}

	// Assembling a large file can outlast the request, so a timeout on the last chunk
	// does not mean it failed. Ask the server what happened before sending it again.
	async function sendLastChunk( file, type, start, filekey, onProgress ) {
		let lastError;
		for ( let attempt = 0; attempt <= maxRetries; attempt++ ) {
			try {
				return await sendChunk( file, type, start, file.size, filekey, onProgress, 0 );
			} catch ( error ) {
				if ( !error || error.code !== 'http' ) {
					throw error;
				}

				lastError = error;
				let progress = null;
				try {
					progress = await checkStatus( filekey, type );
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

	async function uploadFile( file, type, onProgress ) {
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
				result = await sendChunk(
					file, type, offset, end, filekey, onProgress, maxRetries
				);
			} else {
				result = await sendLastChunk( file, type, offset, filekey, onProgress );
			}

			filekey = result.filekey || filekey;
			offset = end;
			onProgress( offset );
		}

		if ( result.result !== 'Success' ) {
			throw Object.assign( new Error( 'unexpected-result' ), { code: 'unexpected-result' } );
		}

		return result.filekey;
	}

	// The files that have a field for them. The archive of images is not always there.
	function getFields() {
		const names = [ { name: 'UploadFile', type: config.type } ];
		if ( config.images ) {
			names.push( { name: 'UploadImages', type: 'images' } );
		}

		return names.map( ( field ) => ( {
			type: field.type,
			$file: $( 'input[name="wp' + field.name + '"]' ),
			$key: $( 'input[name="wp' + field.name + 'FileKey"]' )
		} ) ).filter( ( field ) => field.$file.length && field.$key.length );
	}

	// The largest files are sent in parts until what is left fits in the form's own request.
	function chooseUploads( selected ) {
		const rest = selected.slice().sort( ( a, b ) => b.file.size - a.file.size );
		const parts = [];
		const total = () => rest.reduce( ( sum, item ) => sum + item.file.size, 0 );

		while ( rest.length && total() > getDirectLimit() ) {
			parts.push( rest.shift() );
		}

		return parts;
	}

	$( () => {
		const fields = getFields();
		const $form = fields.length ? fields[ 0 ].$file.closest( 'form' ) : $();
		if ( !$form.length ) {
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

		function getSelected() {
			const $source = $form.find( 'input[name="wpUploadSourceType"]:checked' );
			const fromUrl = $source.length && $source.val() !== 'File';

			return fields.map( ( field ) => ( {
				field,
				file: field.$file[ 0 ].files && field.$file[ 0 ].files[ 0 ]
			} ) ).filter(
				( item ) => item.file && !( fromUrl && item.field.type === config.type )
			);
		}

		$form.on( 'submit', ( e ) => {
			if ( done ) {
				return;
			}

			const uploads = chooseUploads( getSelected() );
			if ( !uploads.length ) {
				return;
			}

			e.preventDefault();
			if ( busy ) {
				return;
			}

			if ( config.maxUploadSize &&
				uploads.some( ( item ) => item.file.size > config.maxUploadSize ) ) {
				fail( mw.message( 'largefileserver' ).text() );
				return;
			}

			busy = true;
			const submitter = e.originalEvent && e.originalEvent.submitter;
			const totalSize = uploads.reduce( ( sum, item ) => sum + item.file.size, 0 );
			let finished = 0;
			$wrapper.show();
			$status.text( mw.message( 'requestimport-chunkedupload-progress' ).text() );

			// One after the other, so that the parts of different files are never mixed.
			uploads.reduce( ( previous, item ) => previous.then( async () => {
				const filekey = await uploadFile( item.file, item.field.type, ( sent ) => {
					progress.setProgress(
						Math.min( 100, Math.round( ( ( finished + sent ) / totalSize ) * 100 ) )
					);
				} );

				finished += item.file.size;
				item.field.$key.val( filekey );
				item.field.$file.val( '' ).prop( 'disabled', true );
			} ), Promise.resolve() ).then( () => {
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
				fail( mw.message( 'requestimport-chunkedupload-error', message ).text() );
			} );
		} );

		fields.forEach( ( field ) => {
			field.$file.on( 'change', () => {
				field.$key.val( '' );
			} );
		} );
	} );
}() );
