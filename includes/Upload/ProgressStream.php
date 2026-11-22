<?php

declare( strict_types = 1 );

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
namespace Miraheze\ImportDump\Upload;

use Closure;
use function bin2hex;
use function fclose;
use function feof;
use function filesize;
use function fopen;
use function fread;
use function fstat;
use function in_array;
use function intdiv;
use function is_file;
use function min;
use function random_bytes;
use function stat;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function strlen;
use function substr;

/**
 * A read only stream that reports how much of a file was read, so that parsers
 * that open files by themselves can show progress. Use url() to get a path for them.
 */
final class ProgressStream {

	private const string PROTOCOL = 'importdump-progress';

	/** @var array<string, array{string, Closure}> */
	private static array $sources = [];

	/** @var mixed A stream context resource or null. Set by PHP. */
	public mixed $context = null;

	/** @var mixed The stream resource of the file, or null while it is not open. */
	private mixed $handle = null;

	private ?Closure $callback = null;
	private string $token = '';
	private int $size = 0;
	private int $read = 0;
	private int $reported = -1;

	/**
	 * @param string $path The file that will be read.
	 * @param Closure( int ): void $onProgress Called with the percentage that was read.
	 */
	public static function url( string $path, Closure $onProgress ): string {
		if ( !in_array( self::PROTOCOL, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( self::PROTOCOL, self::class );
		}

		$token = bin2hex( random_bytes( 8 ) );
		self::$sources[$token] = [ $path, $onProgress ];

		return self::PROTOCOL . '://' . $token;
	}

	/** Forgets a URL that was never opened, or has been closed. */
	public static function release( string $url ): void {
		unset( self::$sources[substr( $url, strlen( self::PROTOCOL ) + 3 )] );
	}

	/**
	 * Some readers check that a file exists before they open it.
	 *
	 * @param string $path
	 * @param int $flags @phan-unused-param
	 * @return array<int|string, int>|false
	 */
	public function url_stat( string $path, int $flags ): array|false {
		$file = self::$sources[substr( $path, strlen( self::PROTOCOL ) + 3 )][0] ?? null;
		return $file !== null && is_file( $file ) ? stat( $file ) : false;
	}

	/**
	 * @param string $path
	 * @param string $mode @phan-unused-param
	 * @param int $options @phan-unused-param
	 * @param ?string &$openedPath
	 */
	public function stream_open( string $path, string $mode, int $options, ?string &$openedPath ): bool {
		$token = substr( $path, strlen( self::PROTOCOL ) + 3 );
		if ( !isset( self::$sources[$token] ) ) {
			return false;
		}

		[ $file, $this->callback ] = self::$sources[$token];

		$handle = fopen( $file, 'rb' );
		if ( !$handle ) {
			return false;
		}

		$this->handle = $handle;
		$this->token = $token;
		$this->size = (int)filesize( $file );

		return true;
	}

	public function stream_read( int $count ): string|false {
		$data = fread( $this->handle, $count );
		if ( $data === false ) {
			return false;
		}

		$this->read += strlen( $data );

		$percent = $this->size > 0 ? min( 100, intdiv( $this->read * 100, $this->size ) ) : 0;
		if ( $percent !== $this->reported ) {
			$this->reported = $percent;
			( $this->callback )( $percent );
		}

		return $data;
	}

	public function stream_eof(): bool {
		return feof( $this->handle );
	}

	/** @return array<int|string, int>|false */
	public function stream_stat(): array|false {
		return fstat( $this->handle );
	}

	/**
	 * @param int $option @phan-unused-param
	 * @param int $arg1 @phan-unused-param
	 * @param ?int $arg2 @phan-unused-param
	 */
	public function stream_set_option( int $option, int $arg1, ?int $arg2 ): false {
		return false;
	}

	public function stream_close(): void {
		fclose( $this->handle );
		self::release( self::PROTOCOL . '://' . $this->token );
	}
}
