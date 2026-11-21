<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use function base64_decode;

/**
 * Small archives made by the xz and 7z programs, because the tests cannot make them without those
 * programs. Every archive holds a file named dump.xml with the text in XML, unless noted.
 */
final class ExternalFixtures {

	public const string XML = '<mediawiki>hello</mediawiki>';

	/** An XZ file of the XML. */
	public const string XZ_XML =
		'/Td6WFoAAATm1rRGAgAhARYAAAB0L+Wj4AAbABhdAB4bSKZbClpd31fYqFguYVbBO8Pr80QAAACU'
		. 'N1lJxSBS7AABNByTGq2PH7bzfQEAAAAABFla';

	/** An XZ file of a TAR with the XML in it. */
	public const string XZ_TAR =
		'/Td6WFoAAATm1rRGAgAhARwAAAAQz1jM4Af/AFBdADIdSd6v4ZF2dUrcDGAXA4OA84cagWxdSKBz'
		. 'HLdpP0NGpWeUSMBhP9Hs0oYowZyDzckL88dgQmJQc2O21eJT8GUw8+aFI12Zs18DPUZiyZx0AIlM'
		. 'lZ0/W3XKAAFsgBAAAADx/JxdscRn+wIAAAAABFla';

	/** An XZ file of a TAR with the files one.xml and two.xml. */
	public const string XZ_TAR_TWO_FILES =
		'/Td6WFoAAATm1rRGAgAhARYAAAB0L+Wj4Av/AF5dADebiM9riMRLUSL1dNBwpt6qq2BFSbyRlL2C'
		. 'yvtM44fKAYkqPr+0ytZISF0qD+FE/ZDI3zkiT6kF45ScVWFZuqOD0JK8Bv8LJUbyqpdmSE2jRvUd'
		. 'WIw4I+vx+pSr4QAAAAAaIgpMHgIbGwABeoAYAAAAmDWnTbHEZ/sCAAAAAARZWg==';

	/** An XZ file of a TAR with the XML and a symbolic link. */
	public const string XZ_TAR_LINK =
		'/Td6WFoAAATm1rRGAgAhARYAAAB0L+Wj4An/AGldADIdSd6v4ZF2dUrcDGAXA4OA84cagWxdSKBz'
		. 'HLdpP0NGpWeUSMBhP9Hs0gXuwngBwdLod/TnK8hsMVexZ8D+swmFNij7/tS8KTk7Ov01bqyWzdzq'
		. 'xbVxqCESwIXiOYSv4UJj1T+fYw3nAAAAAACXSGMinurWbgABhQGAFAAAkcU+RrHEZ/sCAAAAAARZ'
		. 'Wg==';

	/** An XZ file of 8 MiB of zeros. */
	public const string XZ_ZEROS =
		'/Td6WFoAAATm1rRGAgAhARYAAAB0L+Wj//8RAWxdAABv/f//o7f/Rz5IFXI5YVG4kijmo4YH+e7k'
		. 'HoLTL8U6PAFLsX7JiopNL6MN2X+m44wjEVPgWRjFdYrid/i2lH8MasDedElk4ulcU7IE2PdEDKtf'
		. 'DW1G6eXDdoi3llestk3haR1v+0uIEGxCy4g/XACP0E6vJiiUcR89jyThcJ6nI1/sKMuF0ZWYin4q'
		. 'kfIndfcZwAaYTZj92K/VkA/EJVP49ZE2MQWlsO5vwXBNRwzRkRGqrWAdus6xJxhcWYbpZlJYvul2'
		. 'rFnk5VsFCPnH2q38+1IrdM0eWyBC+d1TPfgpZAk7gMsqbN+1O/DEvS5fqg8+S2ZCkBMO/xCT+HF4'
		. 'WfgLzf+VKEYPqfx83vuaMC5WwI+F84OBwGXEJVP49ZE2MQWlsO5vwXBNRwzRkRGqrWAdus6xJxhc'
		. 'WYbpZlJYvul2rFnk5VsFCPnH2q38+1IrdM0eWyBC+d1TPfgpZAk7gMsqbN+1O/DEvEgn5lif/xAB'
		. 'KwDsc1On/b6ufDEan7eNMW5wnqcjX+woy4XRlZiKfiqR8id19xnABphNmP3Yr9WQD8QlU/j1kTYx'
		. 'BaWw7m/BcE1HDNGREaqtYB26zrEnGFxZhulmUli+6XasWeTlWwUI+cfarfz7Uit0zR5bIEL53VM9'
		. '+ClkCTuAyyps37U78MS9Ll+qDz5LZkKQEw7/EJP4cXhZ+AvN/5UoRg+p/Hze+5owLlbAj4Xzg4HA'
		. 'ZcQlU/j1kTYxBaWw7m/BcE1HDNGREaqtYB26zrEnGFxZhulmUli+6XasWeTlWwUI+cfarfz7Uit0'
		. 'zR5bIEL53VM9+ClkCTuAyyps37U78MS9Ll+qDz5LZkKQEw7/EJP4cXhZ+AvN/5UoRg+p/Hze+5ow'
		. 'LlbAj4Xzg4HAZcQlUQ8/sp//EAErAOxzU6f9vq58MRqft40xbnCepyNf7CjLhdGVmIp+KpHyJ3X3'
		. 'GcAGmE2Y/div1ZAPxCVT+PWRNjEFpbDub8FwTUcM0ZERqq1gHbrOsScYXFmG6WZSWL7pdqxZ5OVb'
		. 'BQj5x9qt/PtSK3TNHlsgQvndUz34KWQJO4DLKmzftTvwxL0uX6oPPktmQpATDv8Qk/hxeFn4C83/'
		. 'lShGD6n8fN77mjAuVsCPhfODgcBlxCVT+PWRNjEFpbDub8FwTUcM0ZERqq1gHbrOsScYXFmG6WZS'
		. 'WL7pdqxZ5OVbBQj5x9qt/PtSK3TNHlsgQvndUz34KWQJO4DLKmzftTvwxL0uX6oPPktmQpATDv8Q'
		. 'k/hxeFn4C83/lShGD6n8fN77mjAuVsCPhfODgcBlxCVRDz+yn/8QASsA7HNTp/2+rnwxGp+3jTFu'
		. 'cJ6nI1/sKMuF0ZWYin4qkfIndfcZwAaYTZj92K/VkA/EJVP49ZE2MQWlsO5vwXBNRwzRkRGqrWAd'
		. 'us6xJxhcWYbpZlJYvul2rFnk5VsFCPnH2q38+1IrdM0eWyBC+d1TPfgpZAk7gMsqbN+1O/DEvS5f'
		. 'qg8+S2ZCkBMO/xCT+HF4WfgLzf+VKEYPqfx83vuaMC5WwI+F84OBwGXEJVP49ZE2MQWlsO5vwXBN'
		. 'RwzRkRGqrWAdus6xJxhcWYbpZlJYvul2rFnk5VsFCPnH2q38+1IrdM0eWyBC+d1TPfgpZAk7gMsq'
		. 'bN+1O/DEvS5fqg8+S2ZCkBMO/xCT+HF4WfgLzf+VKEYPqfx83vuaMC5WwI+F84OBwGXEJVEPP7KA'
		. 'A7oABQB7YffvAAAAAC0r4M46TxjTAAGmCoCAgARmzyJ2scRn+wIAAAAABFla';

	/** A 7z archive with the XML. */
	public const string SEVEN_ZIP_ONE =
		'N3q8ryccAARVhmoSIAAAAAAAAABaAAAAAAAAAN3kzw7gABsAGF0AHhtIplsKWl3fV9ioWC5hVsE7'
		. 'w+vzRAAAAAEEBgABCSAABwsBAAEhIQEADBwACAoBds0kXAAABQEZDAAAAAAAAAAAAAAAABETAGQA'
		. 'dQBtAHAALgB4AG0AbAAAABkAFAoBADsb6ZeMd90BFQYBACCApIEAAA==';

	/** A 7z archive with a directory d that holds the XML. */
	public const string SEVEN_ZIP_DIRECTORY =
		'N3q8ryccAAQ7/luXgAAAAAAAAAAgAAAAAAAAAEuGMRPgABsAGF0AHhtIplsKWl3fV9ioWC5hVsE7'
		. 'w+vzRAAAAAAAgTMHrg/QDrA8nz9HQQuzhjUvF/0jOtRVxrdRXc2L7cvRmZbu8LblBrnBvUpOs3wc'
		. 'lnLop/zwoWQYMuAS7oUmnsESiZWJ/Zpxsb5IQ7m0+exXXzWd2yGFQBGjwAAAABcGIAEJYAAHCwEA'
		. 'ASMDAQEFXQAQAAAMbgoBHK4fwAAA';

	/** A 7z archive with d/dump.xml and other.txt. */
	public const string SEVEN_ZIP_TWO_FILES =
		'N3q8ryccAAQHarVMnQAAAAAAAAAhAAAAAAAAAHSqlKjgABwAGV0AHhtIplsKWl3fV9ioWC5hVsE7'
		. 'w+v0KbeAAAAAAIEzB64P0B3SfJ8/R0EtHqDWMwgy78ydfEB94YqqxcRHmz0FICXEfpC0/IIPouSG'
		. '1ufziUrSfDItN60o+46r1/+IKGwXGZk2HLL01sxVF7AfhriL5oatwKZhBwKLFH+N8yNrcCcmU93B'
		. 'EnoznoKVeJ5gW+qmXUf+QAAAFwYhAQl8AAcLAQABIwMBAQVdABAAAAyAkgoBNv64vAAA';

	/** A 7z archive with a symbolic link. */
	public const string SEVEN_ZIP_LINK =
		'N3q8ryccAATEpFG0DAAAAAAAAABaAAAAAAAAANv1ED8BAAdkdW1wLnhtbAABBAYAAQkMAAcLAQAB'
		. 'ISEBAAwIAAgKAZOrM0wAAAUBGQwAAAAAAAAAAAAAAAAREwBsAGkAbgBrAC4AeABtAGwAAAAZABQK'
		. 'AQDEbO+XjHfdARUGAQAggP+hAAA=';

	/** A 7z archive with an encrypted file. */
	public const string SEVEN_ZIP_ENCRYPTED =
		'N3q8ryccAASlNT5nIAAAAAAAAABqAAAAAAAAAM7lFOT09/agjuEd8MVW1W8mA2ImwcdKq/nDOpSE'
		. 'kFFz0mTzwgEEBgABCSAABwsBAAIkBvEHARJTD4qKOq90Q+GXsjCZLcUFbH0hIQEAAQAMIBwACAoB'
		. 'ds0kXAAABQEZAQAREwBkAHUAbQBwAC4AeABtAGwAAAAZABQKAQA7G+mXjHfdARUGAQAggKSBAAA=';

	/** A 7z archive with encrypted headers. */
	public const string SEVEN_ZIP_ENCRYPTED_HEADERS =
		'N3q8ryccAAQBnKFSkAAAAAAAAAAuAAAAAAAAAFx+yF8tvOJNkc3X2nFByogfVuAinTCayvkiBsvc'
		. '7xLO8W5t+tGmRn+r5hPd3cMNevVOzoQamMybX/2Uappytu157rWPQAnAxAMVUFvz9483oDRM0Obs'
		. 'Z5M9Qo6fR/ftOg5Yc7dudhT9FsANFb/VEUuEogxgnPcsu5X7Xo9kElfOu75iZHU/tgk3tC9ie3tE'
		. 'OdRouz4XBiABCXAABwsBAAEkBvEHARJTDwAxhzt2WdHGyQl0i+ThsokMagoBKSUg9gAA';

	/** A 7z archive with the XML and the macOS file ._dump.xml. */
	public const string SEVEN_ZIP_MACOS =
		'N3q8ryccAAQEwstDiwAAAAAAAAAgAAAAAAAAAJdUDqjgACAAHV0AMJws2GYsBT4dsdVk1//Ny1r8'
		. 'mDWW+4VJoVmAAAAAAACBMweuD9BaW3yfP0dBBYeGo4oceG1GZbRC/5JStyLaC5H/dqgD5aDXp4/b'
		. 'BeSYrsbexrqulDVCUrR6bYBuKHFVQwr7+OWJ5ylurnfrXtXn0FCaZqJS5zw5gBp0iCU6K1N0AAAA'
		. 'FwYlAQlmAAcLAQABIwMBAQVdABAAAAx+CgFeteNTAAA=';

	/** A 7z archive with zeros8.xml, which is 8 MiB of zeros. */
	public const string SEVEN_ZIP_ZEROS =
		'N3q8ryccAARRrqcTEgUAAAAAAABiAAAAAAAAAFb4NLb/95oBa10AAG/9//+jt/9HPkgVcjlhUbiS'
		. 'KOajhgf57uQegtMvxTo8AUuxfsmKik0vow3Zf6bjjCMRU+BZGMV1iuJ3+LaUfwxqwN50SWTi6VxT'
		. 'sgTY90QMq18NbUbp5cN2iLeWV6y2TeFpHW/7S4gQbELLiD9cAI/QTq8mKJRxHz2PJOFwnqcjX+wo'
		. 'y4XRlZiKfiqR8id19xnABphNmP3Yr9WQD8QlU/j1kTYxBaWw7m/BcE1HDNGREaqtYB26zrEnGFxZ'
		. 'hulmUli+6XasWeTlWwUI+cfarfz7Uit0zR5bIEL53VM9+ClkCTuAyyps37U78MS9Ll+qDz5LZkKQ'
		. 'Ew7/EJP4cXhZ+AvN/5UoRg+p/Hze+5owLlbAj4Xzg4HAZcQlU/j1kTYxBaWw7m/BcE1HDNGREaqt'
		. 'YB26zrEnGFxZhulmUli+6XasWeTlWwUI+cfarfz7Uit0zR5bIEL53VM9+ClkCTuAyyps37U78MS5'
		. 'K+qXn/eZASsA7HNTp/2+rnwxGp+3jTFucJ6nI1/sKMuF0ZWYin4qkfIndfcZwAaYTZj92K/VkA/E'
		. 'JVP49ZE2MQWlsO5vwXBNRwzRkRGqrWAdus6xJxhcWYbpZlJYvul2rFnk5VsFCPnH2q38+1IrdM0e'
		. 'WyBC+d1TPfgpZAk7gMsqbN+1O/DEvS5fqg8+S2ZCkBMO/xCT+HF4WfgLzf+VKEYPqfx83vuaMC5W'
		. 'wI+F84OBwGXEJVP49ZE2MQWlsO5vwXBNRwzRkRGqrWAdus6xJxhcWYbpZlJYvul2rFnk5VsFCPnH'
		. '2q38+1IrdM0eWyBC+d1TPfgpZAk7gMsqbN+1O/DEvS5fqg8+S2ZCkBMO/xCT+HF4WfgLzf+VKEYP'
		. 'qfx83vuaMC5WwI+F84OBwGXEJUb8HlKf95kBKwDsc1On/b6ufDEan7eNMW5wnqcjX+woy4XRlZiK'
		. 'fiqR8id19xnABphNmP3Yr9WQD8QlU/j1kTYxBaWw7m/BcE1HDNGREaqtYB26zrEnGFxZhulmUli+'
		. '6XasWeTlWwUI+cfarfz7Uit0zR5bIEL53VM9+ClkCTuAyyps37U78MS9Ll+qDz5LZkKQEw7/EJP4'
		. 'cXhZ+AvN/5UoRg+p/Hze+5owLlbAj4Xzg4HAZcQlU/j1kTYxBaWw7m/BcE1HDNGREaqtYB26zrEn'
		. 'GFxZhulmUli+6XasWeTlWwUI+cfarfz7Uit0zR5bIEL53VM9+ClkCTuAyyps37U78MS9Ll+qDz5L'
		. 'ZkKQEw7/EJP4cXhZ+AvN/5UoRg+p/Hze+5owLlbAj4Xzg4HAZcQlRvweUp/3mQErAOxzU6f9vq58'
		. 'MRqft40xbnCepyNf7CjLhdGVmIp+KpHyJ3X3GcAGmE2Y/div1ZAPxCVT+PWRNjEFpbDub8FwTUcM'
		. '0ZERqq1gHbrOsScYXFmG6WZSWL7pdqxZ5OVbBQj5x9qt/PtSK3TNHlsgQvndUz34KWQJO4DLKmzf'
		. 'tTvwxL0uX6oPPktmQpATDv8Qk/hxeFn4C83/lShGD6n8fN77mjAuVsCPhfODgcBlxCVT+PWRNjEF'
		. 'pbDub8FwTUcM0ZERqq1gHbrOsScYXFmG6WZSWL7pdqxZ5OVbBQj5x9qt/PtSK3TNHlsgQvndUz34'
		. 'KWQJO4DLKmzftTvwxL0uX6oPPktmQpATDv8Qk/hxeFn4C83/lShGD6n8fN77mjAuVsCPhfODgcBl'
		. 'xCVG/B5SgCGWAAYA7CoSW0gAAAEEBgABCYUSAAcLAQABISEBFgzgAACAAAgKAUW80hoAAAUBGQgA'
		. 'AAAAAAAAABEXAHoAZQByAG8AcwA4AC4AeABtAGwAAAAZBAAAAAAUCgEAz5eRoox33QEVBgEAIICk'
		. 'gQAA';

	public static function decode( string $encoded ): string {
		return (string)base64_decode( $encoded, true );
	}
}
