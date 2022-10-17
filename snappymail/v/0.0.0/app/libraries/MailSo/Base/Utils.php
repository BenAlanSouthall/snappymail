<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Base;

/**
 * @category MailSo
 * @package Base
 */
abstract class Utils
{

	public static function NormalizeCharset(string $sEncoding, bool $bAsciAsUtf8 = false) : string
	{
		$sEncoding = \preg_replace('/^iso8/', 'iso-8', \strtolower($sEncoding));

		switch ($sEncoding)
		{
			case 'asci':
			case 'ascii':
			case 'us-asci':
			case 'us-ascii':
				return $bAsciAsUtf8 ? Enumerations\Charset::UTF_8 :
					Enumerations\Charset::ISO_8859_1;

			case 'unicode-1-1-utf-7':
			case 'unicode-1-utf-7':
			case 'unicode-utf-7':
				return 'utf-7';

			case 'utf8':
			case 'utf-8':
				return Enumerations\Charset::UTF_8;

			case 'utf7imap':
			case 'utf-7imap':
			case 'utf7-imap':
			case 'utf-7-imap':
				return 'utf7-imap';

			case 'ks-c-5601-1987':
			case 'ks_c_5601-1987':
			case 'ks_c_5601_1987':
				return 'euc-kr';

			case 'x-gbk':
				return 'gb2312';

			case 'iso-8859-i':
			case 'iso-8859-8-i':
				return 'iso-8859-8';
		}

		return \preg_replace('/^(cp-?|windows?)(12[\d])/', 'windows-$1', $sEncoding);
	}

	public static function NormalizeCharsetByValue(string $sCharset, string $sValue) : string
	{
		$sCharset = static::NormalizeCharset($sCharset);

		if (Enumerations\Charset::UTF_8 !== $sCharset &&
			static::IsUtf8($sValue) &&
			!\str_contains($sCharset, Enumerations\Charset::ISO_2022_JP)
		)
		{
			$sCharset = Enumerations\Charset::UTF_8;
		}

		return $sCharset;
	}

	public static function MbSupportedEncoding(string $sEncoding) : bool
	{
		static $aSupportedEncodings = null;
		if (null === $aSupportedEncodings) {
			$aSupportedEncodings = \array_diff(\mb_list_encodings(), ['BASE64','UUENCODE','HTML-ENTITIES','Quoted-Printable']);
			$aSupportedEncodings = \array_map('strtoupper', \array_unique(
				\array_merge(
					$aSupportedEncodings,
					\array_merge(
						...\array_map(
							'mb_encoding_aliases',
							$aSupportedEncodings
						)
					)
				)
			));
		}
		return \in_array(\strtoupper($sEncoding), $aSupportedEncodings);
	}

	private static $RenameEncoding = [
		'SHIFT_JIS' => 'SJIS'
	];

	public static function MbConvertEncoding(string $sInputString, ?string $sFromEncoding, string $sToEncoding) : string
	{
		$sToEncoding = \strtoupper($sToEncoding);
		if ($sFromEncoding) {
			$sFromEncoding = \strtoupper($sFromEncoding);
			if (isset(static::$RenameEncoding[$sFromEncoding])) {
				$sFromEncoding = static::$RenameEncoding[$sFromEncoding];
			}
/*
			if ('UTF-8' === $sFromEncoding && $sToEncoding === 'UTF7-IMAP' && \is_callable('imap_utf8_to_mutf7')) {
				$sResult = \imap_utf8_to_mutf7($sInputString);
				if (false !== $sResult) {
					return $sResult;
				}
			}

			if ('BASE64' === $sFromEncoding) {
				static::Base64Decode($sFromEncoding);
				$sFromEncoding = null;
			} else if ('UUENCODE' === $sFromEncoding) {
				\convert_uudecode($sFromEncoding);
				$sFromEncoding = null;
			} else if ('QUOTED-PRINTABLE' === $sFromEncoding) {
				\quoted_printable_decode($sFromEncoding);
				$sFromEncoding = null;
			} else
*/
			if (!static::MbSupportedEncoding($sFromEncoding)) {
				if (\function_exists('iconv')) {
					$sResult = \iconv($sFromEncoding, "{$sToEncoding}//IGNORE", $sInputString);
					return (false !== $sResult) ? $sResult : $sInputString;
				}
				\error_log("Unsupported encoding {$sFromEncoding}");
				$sFromEncoding = null;
//				return $sInputString;
			}
		}

		\mb_substitute_character('none');
		$sResult = \mb_convert_encoding($sInputString, $sToEncoding, $sFromEncoding);
		\mb_substitute_character(0xFFFD);

		return (false !== $sResult) ? $sResult : $sInputString;
	}

	public static function ConvertEncoding(string $sInputString, string $sFromEncoding, string $sToEncoding) : string
	{
		$sFromEncoding = static::NormalizeCharset($sFromEncoding);
		$sToEncoding = static::NormalizeCharset($sToEncoding);

		if ('' === \trim($sInputString) || ($sFromEncoding === $sToEncoding && Enumerations\Charset::UTF_8 !== $sFromEncoding))
		{
			return $sInputString;
		}

		return static::MbConvertEncoding($sInputString, $sFromEncoding, $sToEncoding);
	}

	public static function IsAscii(string $sValue) : bool
	{
		return '' === \trim($sValue)
			|| !\preg_match('/[^\x09\x10\x13\x0A\x0D\x20-\x7E]/', $sValue);
	}

	public static function StrMailDomainToLower(string $sValue) : string
	{
		$aParts = \explode('@', $sValue);
		$iLast = \count($aParts) - 1;
		if ($iLast) {
			$aParts[$iLast] = \mb_strtolower($aParts[$iLast]);
		}

		return \implode('@', $aParts);
	}

	public static function StripSpaces(string $sValue) : string
	{
		return static::Trim(
			\preg_replace('/[\s]+/u', ' ', $sValue));
	}

	public static function IsUtf8(string $sValue) : bool
	{
		return \mb_check_encoding($sValue, 'UTF-8');
	}

	public static function FormatFileSize(int $iSize, int $iRound = 0) : string
	{
		$aSizes = array('B', 'KB', 'MB');
		for ($iIndex = 0; $iSize > 1024 && isset($aSizes[$iIndex + 1]); $iIndex++)
		{
			$iSize /= 1024;
		}
		return \round($iSize, $iRound).$aSizes[$iIndex];
	}

	public static function DecodeEncodingValue(string $sEncodedValue, string $sEncodeingType) : string
	{
		$sResult = $sEncodedValue;
		switch (\strtolower($sEncodeingType))
		{
			case 'q':
			case 'quoted_printable':
			case 'quoted-printable':
				$sResult = \quoted_printable_decode($sResult);
				break;
			case 'b':
			case 'base64':
				$sResult = static::Base64Decode($sResult);
				break;
		}
		return $sResult;
	}

	public static function DecodeFlowedFormat(string $sInputValue) : string
	{
		return \preg_replace('/ ([\r]?[\n])/m', ' ', $sInputValue);
	}

	public static function DecodeHeaderValue(string $sEncodedValue, string $sIncomingCharset = '', string $sForcedIncomingCharset = '') : string
	{
		$sValue = $sEncodedValue;
		if (\strlen($sIncomingCharset))
		{
			$sIncomingCharset = static::NormalizeCharsetByValue($sIncomingCharset, $sValue);

			$sValue = static::ConvertEncoding($sValue, $sIncomingCharset,
				Enumerations\Charset::UTF_8);
		}

		$sValue = \preg_replace('/\?=[\n\r\t\s]{1,5}=\?/m', '?==?', $sValue);
		$sValue = \preg_replace('/[\r\n\t]+/m', ' ', $sValue);

		$aEncodeArray = array('');
		$aMatch = array();
//		\preg_match_all('/=\?[^\?]+\?[q|b|Q|B]\?[^\?]*?\?=/', $sValue, $aMatch);
		\preg_match_all('/=\?[^\?]+\?[q|b|Q|B]\?.*?\?=/', $sValue, $aMatch);

		if (isset($aMatch[0]) && \is_array($aMatch[0]))
		{
			for ($iIndex = 0, $iLen = \count($aMatch[0]); $iIndex < $iLen; $iIndex++)
			{
				if (isset($aMatch[0][$iIndex]))
				{
					$iPos = \strpos($aMatch[0][$iIndex], '*');
					if (false !== $iPos)
					{
						$aMatch[0][$iIndex][0] = \substr($aMatch[0][$iIndex][0], 0, $iPos);
					}
				}
			}

			$aEncodeArray = $aMatch[0];
		}

		$aParts = array();

		$sMainCharset = '';
		$bOneCharset = true;

		for ($iIndex = 0, $iLen = \count($aEncodeArray); $iIndex < $iLen; $iIndex++)
		{
			$aTempArr = array('', $aEncodeArray[$iIndex]);
			if ('=?' === \substr(\trim($aTempArr[1]), 0, 2))
			{
				$iPos = \strpos($aTempArr[1], '?', 2);
				$aTempArr[0] = \substr($aTempArr[1], 2, $iPos - 2);
				$sEncType = \strtoupper($aTempArr[1][$iPos + 1]);
				switch ($sEncType)
				{
					case 'Q':
						$sHeaderValuePart = \str_replace('_', ' ', $aTempArr[1]);
						$aTempArr[1] = \quoted_printable_decode(\substr(
							$sHeaderValuePart, $iPos + 3, \strlen($sHeaderValuePart) - $iPos - 5));
						break;
					case 'B':
						$sHeaderValuePart = $aTempArr[1];
						$aTempArr[1] = static::Base64Decode(\substr(
							$sHeaderValuePart, $iPos + 3, \strlen($sHeaderValuePart) - $iPos - 5));
						break;
				}
			}

			if (\strlen($aTempArr[0]))
			{
				$sCharset = \strlen($sForcedIncomingCharset) ? $sForcedIncomingCharset : $aTempArr[0];
				$sCharset = static::NormalizeCharset($sCharset, true);

				if ('' === $sMainCharset)
				{
					$sMainCharset = $sCharset;
				}
				else if ($sMainCharset !== $sCharset)
				{
					$bOneCharset = false;
				}
			}

			$aParts[] = array(
				$aEncodeArray[$iIndex],
				$aTempArr[1],
				$sCharset
			);

			unset($aTempArr);
		}

		for ($iIndex = 0, $iLen = \count($aParts); $iIndex < $iLen; $iIndex++)
		{
			if ($bOneCharset)
			{
				$sValue = \str_replace($aParts[$iIndex][0], $aParts[$iIndex][1], $sValue);
			}
			else
			{
				$aParts[$iIndex][2] = static::NormalizeCharsetByValue($aParts[$iIndex][2], $aParts[$iIndex][1]);

				$sValue = \str_replace($aParts[$iIndex][0],
					static::ConvertEncoding($aParts[$iIndex][1], $aParts[$iIndex][2], Enumerations\Charset::UTF_8),
					$sValue);
			}
		}

		if ($bOneCharset && \strlen($sMainCharset))
		{
			$sMainCharset = static::NormalizeCharsetByValue($sMainCharset, $sValue);
			$sValue = static::ConvertEncoding($sValue, $sMainCharset, Enumerations\Charset::UTF_8);
		}

		return $sValue;
	}

	public static function RemoveHeaderFromHeaders(string $sIncHeaders, array $aHeadersToRemove = array()) : string
	{
		$sResultHeaders = $sIncHeaders;

		if ($aHeadersToRemove)
		{
			$aHeadersToRemove = \array_map('strtolower', $aHeadersToRemove);

			$sIncHeaders = \preg_replace('/[\r\n]+/', "\n", $sIncHeaders);
			$aHeaders = \explode("\n", $sIncHeaders);

			$bSkip = false;
			$aResult = array();

			foreach ($aHeaders as $sLine)
			{
				if (\strlen($sLine))
				{
					$sFirst = \substr($sLine,0,1);
					if (' ' === $sFirst || "\t" === $sFirst)
					{
						if (!$bSkip)
						{
							$aResult[] = $sLine;
						}
					}
					else
					{
						$bSkip = false;
						$aParts = \explode(':', $sLine, 2);

						if (!empty($aParts) && !empty($aParts[0]))
						{
							if (\in_array(\strtolower(\trim($aParts[0])), $aHeadersToRemove))
							{
								$bSkip = true;
							}
							else
							{
								$aResult[] = $sLine;
							}
						}
					}
				}
			}

			$sResultHeaders = \implode("\r\n", $aResult);
		}

		return $sResultHeaders;
	}

	public static function EncodeUnencodedValue(string $sEncodeType, string $sValue) : string
	{
		$sValue = \trim($sValue);
		if (\strlen($sValue) && !static::IsAscii($sValue))
		{
			switch (\strtoupper($sEncodeType))
			{
				case 'B':
					$sValue = '=?'.\strtolower(Enumerations\Charset::UTF_8).
						'?B?'.\base64_encode($sValue).'?=';
					break;

				case 'Q':
					$sValue = '=?'.\strtolower(Enumerations\Charset::UTF_8).
						'?Q?'.\str_replace(array('?', ' ', '_'), array('=3F', '_', '=5F'),
							\quoted_printable_encode($sValue)).'?=';
					break;
			}
		}

		return $sValue;
	}

	public static function AttributeRfc2231Encode(string $sAttrName, string $sValue, string $sCharset = 'utf-8', string $sLang = '', int $iLen = 1000) : string
	{
		$sValue = \strtoupper($sCharset).'\''.$sLang.'\''.
			\preg_replace_callback('/[\x00-\x20*\'%()<>@,;:\\\\"\/[\]?=\x80-\xFF]/', function ($match) {
				return \rawurlencode($match[0]);
			}, $sValue);

		$iNlen = \strlen($sAttrName);
		$iVlen = \strlen($sValue);

		if (\strlen($sAttrName) + $iVlen > $iLen - 3)
		{
			$sections = array();
			$section = 0;

			for ($i = 0, $j = 0; $i < $iVlen; $i += $j)
			{
				$j = $iLen - $iNlen - \strlen($section) - 4;
				$sections[$section++] = \substr($sValue, $i, $j);
			}

			for ($i = 0, $n = $section; $i < $n; $i++)
			{
				$sections[$i] = ' '.$sAttrName.'*'.$i.'*='.$sections[$i];
			}

			return \implode(";\r\n", $sections);
		}
		else
		{
			return $sAttrName.'*='.$sValue;
		}
	}

	public static function EncodeHeaderUtf8AttributeValue(string $sAttrName, string $sValue) : string
	{
		$sAttrName = \trim($sAttrName);
		$sValue = \trim($sValue);

		if (\strlen($sValue) && !static::IsAscii($sValue))
		{
			$sValue = static::AttributeRfc2231Encode($sAttrName, $sValue);
		}
		else
		{
			$sValue = $sAttrName.'="'.\str_replace('"', '\\"', $sValue).'"';
		}

		return \trim($sValue);
	}

	public static function GetAccountNameFromEmail(string $sEmail) : string
	{
		$sResult = '';
		if (\strlen($sEmail))
		{
			$iPos = \strrpos($sEmail, '@');
			$sResult = (false === $iPos) ? $sEmail : \substr($sEmail, 0, $iPos);
		}

		return $sResult;
	}

	public static function GetDomainFromEmail(string $sEmail) : string
	{
		$sResult = '';
		if (\strlen($sEmail))
		{
			$iPos = \strrpos($sEmail, '@');
			if (false !== $iPos && 0 < $iPos)
			{
				$sResult = \substr($sEmail, $iPos + 1);
			}
		}

		return $sResult;
	}

	public static function GetClearDomainName(string $sDomain) : string
	{
		$sResultDomain = \preg_replace(
			'/^(webmail|email|mail|www|imap4|imap|demo|client|ssl|secure|test|cloud|box|m)\./i',
				'', $sDomain);

		return false === \strpos($sResultDomain, '.') ? $sDomain : $sResultDomain;
	}

	public static function GetFileExtension(string $sFileName) : string
	{
		$iLast = \strrpos($sFileName, '.');
		return false === $iLast ? '' : \strtolower(\substr($sFileName, $iLast + 1));
	}

	public static function MimeContentType(string $sFileName) : string
	{
		$sResult = 'application/octet-stream';
		$sFileName = \trim(\strtolower($sFileName));

		if ('winmail.dat' === $sFileName)
		{
			return 'application/ms-tnef';
		}

		$aMimeTypes = array(

			'eml'	=> 'message/rfc822',
			'mime'	=> 'message/rfc822',
			'txt'	=> 'text/plain',
			'text'	=> 'text/plain',
			'def'	=> 'text/plain',
			'list'	=> 'text/plain',
			'in'	=> 'text/plain',
			'ini'	=> 'text/plain',
			'log'	=> 'text/plain',
			'sql'	=> 'text/plain',
			'cfg'	=> 'text/plain',
			'conf'	=> 'text/plain',
			'rtx'	=> 'text/richtext',
			'vcard'	=> 'text/vcard',
			'vcf'	=> 'text/vcard',
			'htm'	=> 'text/html',
			'html'	=> 'text/html',
			'csv'	=> 'text/csv',
			'ics'	=> 'text/calendar',
			'ifb'	=> 'text/calendar',
			'xml'	=> 'text/xml',
			'json'	=> 'application/json',
			'swf'	=> 'application/x-shockwave-flash',
			'hlp'	=> 'application/winhlp',
			'wgt'	=> 'application/widget',
			'chm'	=> 'application/vnd.ms-htmlhelp',
			'asc'   => 'application/pgp-signature',
			'p10'	=> 'application/pkcs10',
			'p7c'	=> 'application/pkcs7-mime',
			'p7m'	=> 'application/pkcs7-mime',
			'p7s'	=> 'application/pkcs7-signature',
			'torrent'	=> 'application/x-bittorrent',

			// scripts
			'js'	=> 'application/javascript',
			'pl'	=> 'text/perl',
			'css'	=> 'text/css',
			'asp'	=> 'text/asp',
			'php'	=> 'application/x-php',

			// images
			'png'	=> 'image/png',
			'jpg'	=> 'image/jpeg',
			'jpeg'	=> 'image/jpeg',
			'jpe'	=> 'image/jpeg',
			'jfif'	=> 'image/jpeg',
			'gif'	=> 'image/gif',
			'bmp'	=> 'image/bmp',
			'cgm'	=> 'image/cgm',
			'ief'	=> 'image/ief',
			'ico'	=> 'image/x-icon',
			'tif'	=> 'image/tiff',
			'tiff'	=> 'image/tiff',
			'svg'	=> 'image/svg+xml',
			'svgz'	=> 'image/svg+xml',
			'djv'	=> 'image/vnd.djvu',
			'djvu'	=> 'image/vnd.djvu',
			'webp'	=> 'image/webp',

			// archives
			'zip'	=> 'application/zip',
			'7z'	=> 'application/x-7z-compressed',
			'rar'	=> 'application/x-rar-compressed',
			'exe'	=> 'application/x-msdownload',
			'dll'	=> 'application/x-msdownload',
			'scr'	=> 'application/x-msdownload',
			'com'	=> 'application/x-msdownload',
			'bat'	=> 'application/x-msdownload',
			'msi'	=> 'application/x-msdownload',
			'cab'	=> 'application/vnd.ms-cab-compressed',
			'gz'	=> 'application/x-gzip',
			'tgz'	=> 'application/x-gzip',
			'bz'	=> 'application/x-bzip',
			'bz2'	=> 'application/x-bzip2',
			'deb'	=> 'application/x-debian-package',
			'tar'	=> 'application/x-tar',

			// fonts
			'psf'	=> 'application/x-font-linux-psf',
			'otf'	=> 'application/x-font-otf',
			'pcf'	=> 'application/x-font-pcf',
			'snf'	=> 'application/x-font-snf',
			'ttf'	=> 'application/x-font-ttf',
			'ttc'	=> 'application/x-font-ttf',

			// audio
			'aac'	=> 'audio/aac',
			'flac'	=> 'audio/flac',
			'mp3'	=> 'audio/mpeg',
			'aif'	=> 'audio/aiff',
			'aifc'	=> 'audio/aiff',
			'aiff'	=> 'audio/aiff',
			'wav'	=> 'audio/x-wav',
			'midi'	=> 'audio/midi',
			'mp4a'	=> 'audio/mp4',
			'ogg'	=> 'audio/ogg',
			'weba'	=> 'audio/webm',
			'm3u'	=> 'audio/x-mpegurl',

			// video
			'qt'	=> 'video/quicktime',
			'mov'	=> 'video/quicktime',
			'avi'	=> 'video/x-msvideo',
			'mpg'	=> 'video/mpeg',
			'mpeg'	=> 'video/mpeg',
			'mpe'	=> 'video/mpeg',
			'm1v'	=> 'video/mpeg',
			'm2v'	=> 'video/mpeg',
			'3gp'	=> 'video/3gpp',
			'3g2'	=> 'video/3gpp2',
			'h261'	=> 'video/h261',
			'h263'	=> 'video/h263',
			'h264'	=> 'video/h264',
			'jpgv'	=> 'video/jpgv',
			'mp4'	=> 'video/mp4',
			'mp4v'	=> 'video/mp4',
			'mpg4'	=> 'video/mp4',
			'ogv'	=> 'video/ogg',
			'webm'	=> 'video/webm',
			'm4v'	=> 'video/x-m4v',
			'asf'	=> 'video/x-ms-asf',
			'asx'	=> 'video/x-ms-asf',
			'wm'	=> 'video/x-ms-wm',
			'wmv'	=> 'video/x-ms-wmv',
			'wmx'	=> 'video/x-ms-wmx',
			'wvx'	=> 'video/x-ms-wvx',
			'movie'	=> 'video/x-sgi-movie',

			// adobe
			'pdf'	=> 'application/pdf',
			'psd'	=> 'image/vnd.adobe.photoshop',
			'ai'	=> 'application/postscript',
			'eps'	=> 'application/postscript',
			'ps'	=> 'application/postscript',

			// ms office
			'doc'	=> 'application/msword',
			'dot'	=> 'application/msword',
			'rtf'	=> 'application/rtf',
			'xls'	=> 'application/vnd.ms-excel',
			'ppt'	=> 'application/vnd.ms-powerpoint',
			'docx'	=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xlsx'	=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'dotx'	=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
			'pptx'	=> 'application/vnd.openxmlformats-officedocument.presentationml.presentation',

			// open office
			'odt'	=> 'application/vnd.oasis.opendocument.text',
			'ods'	=> 'application/vnd.oasis.opendocument.spreadsheet',
			'odp'	=> 'application/vnd.oasis.opendocument.presentation'

		);

		$sExt = static::GetFileExtension($sFileName);
		if (\strlen($sExt) && isset($aMimeTypes[$sExt]))
		{
			$sResult = $aMimeTypes[$sExt];
		}

		return $sResult;
	}

	public static function ContentTypeType(string $sContentType, string $sFileName) : string
	{
		$sContentType = \strtolower($sContentType);
		if (\str_starts_with($sContentType, 'image/')) {
			return 'image';
		}

		switch ($sContentType)
		{
			case 'application/zip':
			case 'application/x-7z-compressed':
			case 'application/x-rar-compressed':
			case 'application/x-msdownload':
			case 'application/vnd.ms-cab-compressed':
			case 'application/gzip':
			case 'application/x-gzip':
			case 'application/x-bzip':
			case 'application/x-bzip2':
			case 'application/x-debian-package':
			case 'application/x-tar':
				return 'archive';

			case 'application/msword':
			case 'application/rtf':
			case 'application/vnd.ms-excel':
			case 'application/vnd.ms-powerpoint':
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
			case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.template':
			case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
			case 'application/vnd.oasis.opendocument.text':
			case 'application/vnd.oasis.opendocument.spreadsheet':
				return 'doc';

			case 'application/pdf':
			case 'application/x-pdf':
				return 'pdf';
		}

		switch (\strtolower(static::GetFileExtension($sFileName)))
		{
			case 'zip':
			case '7z':
			case 'rar':
			case 'tar':
			case 'tgz':
				return 'archive';

			case 'pdf':
				return 'pdf';
		}

		return '';
	}

	/**
	 * @staticvar bool $bValidateAction
	 */
	public static function ResetTimeLimit(int $iTimeToReset = 15, int $iTimeToAdd = 120) : bool
	{
		$iTime = \time();
		if ($iTime < $_SERVER['REQUEST_TIME_FLOAT'] + 5)
		{
			// do nothing first 5s
			return true;
		}

		static $bValidateAction = null;
		static $iResetTimer = null;

		if (null === $bValidateAction)
		{
			$iResetTimer = 0;

			$bValidateAction = static::FunctionCallable('set_time_limit');
		}

		if ($bValidateAction && $iTimeToReset < $iTime - $iResetTimer)
		{
			$iResetTimer = $iTime;
			if (!\set_time_limit($iTimeToAdd))
			{
				$bValidateAction = false;
				return false;
			}

			return true;
		}

		return false;
	}

	# Replace ampersand, spaces and reserved characters (based on Win95 VFAT)
	# en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
	public static function SecureFileName(string $sFileName) : string
	{
		return \preg_replace('#[|\\\\?*<":>+\\[\\]/&\\s\\pC]#su', '-', $sFileName);
	}

	public static function Trim(string $sValue) : string
	{
		return \trim(\preg_replace('/^[\x00-\x1F]+|[\x00-\x1F]+$/Du', '', \trim($sValue)));
	}

	public static function RecRmDir(string $sDir) : bool
	{
		\clearstatcache();
		if (\is_dir($sDir)) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($sDir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($iterator as $path) {
				if ($path->isDir()) {
					\rmdir($path);
				} else {
					\unlink($path);
				}
			}
			\clearstatcache();
//			\realpath_cache_size() && \clearstatcache(true);
			return \rmdir($sDir);
		}

		return false;
	}

	public static function RecTimeDirRemove(string $sDir, int $iTime2Kill) : bool
	{
		\clearstatcache();
		if (\is_dir($sDir)) {
			$iTime = \time() - $iTime2Kill;
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($sDir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($iterator as $path) {
				if ($path->isFile() && $path->getMTime() < $iTime) {
					\unlink($path);
				} else if ($path->isDir() && !(new \FilesystemIterator($path))->valid()) {
					\rmdir($path);
				}
			}
			\clearstatcache();
//			\realpath_cache_size() && \clearstatcache(true);
			return !(new \FilesystemIterator($sDir))->valid() && \rmdir($sDir);
		}

		return false;
	}

	public static function Utf8Clear(?string $sUtfString) : string
	{
		if (!\strlen($sUtfString)) {
			return '';
		}

		$sSubstitute = ''; // '�' 0xFFFD
/*
		$converter = new \UConverter('UTF-8', 'UTF-8');
		$converter->setSubstChars($sSubstitute);
		$sNewUtfString = $converter->->convert($sUtfString);
//		$sNewUtfString = \UConverter::transcode($str, 'UTF-8', 'UTF-8', [????]);
*/
		\mb_substitute_character($sSubstitute ?: 'none');
		$sNewUtfString = \mb_convert_encoding($sUtfString, 'UTF-8', 'UTF-8');
		\mb_substitute_character(0xFFFD);

		return (false !== $sNewUtfString) ? $sNewUtfString : $sUtfString;
//		return (false !== $sNewUtfString) ? \preg_replace('/\\p{Cc}/u', '', $sNewUtfString) : $sUtfString;
	}

	public static function Base64Decode(string $sString) : string
	{
		$sResultString = \base64_decode($sString, true);
		if (false === $sResultString)
		{
			$sString = \str_replace(array(' ', "\r", "\n", "\t"), '', $sString);
			$sString = \preg_replace('/[^a-zA-Z0-9=+\/](.*)$/', '', $sString);

			if (false !== \strpos(\trim(\trim($sString), '='), '='))
			{
				$sString = \preg_replace('/=([^=])/', '= $1', $sString);
				$aStrings = \explode(' ', $sString);
				foreach ($aStrings as $iIndex => $sParts)
				{
					$aStrings[$iIndex] = \base64_decode($sParts);
				}

				$sResultString = \implode('', $aStrings);
			}
			else
			{
				$sResultString = \base64_decode($sString);
			}
		}

		return $sResultString;
	}

	public static function UrlSafeBase64Encode(string $sValue) : string
	{
		return \rtrim(\strtr(\base64_encode($sValue), '+/', '-_'), '=');
	}

	public static function UrlSafeBase64Decode(string $sValue) : string
	{
		return \base64_decode(\strtr($sValue, '-_', '+/'), '=');
	}

	/**
	 * @param resource $fResource
	 */
	public static function FpassthruWithTimeLimitReset($fResource, int $iBufferLen = 8192) : bool
	{
		$bResult = false;
		if (\is_resource($fResource))
		{
			while (!\feof($fResource))
			{
				$sBuffer = \fread($fResource, $iBufferLen);
				if (false !== $sBuffer)
				{
					echo $sBuffer;
					static::ResetTimeLimit();
					continue;
				}

				break;
			}

			$bResult = true;
		}

		return $bResult;
	}

	/**
	 * @param resource $rRead
	 */
	public static function MultipleStreamWriter($rRead, array $aWrite, int $iBufferLen = 8192, bool $bResetTimeLimit = true, bool $bFixCrLf = false, bool $bRewindOnComplete = false) : int
	{
		$mResult = false;
		if (\is_resource($rRead) && \count($aWrite))
		{
			$mResult = 0;
			while (!\feof($rRead))
			{
				$sBuffer = \fread($rRead, $iBufferLen);
				if (false === $sBuffer)
				{
					$mResult = false;
					break;
				}

				if ('' === $sBuffer)
				{
					break;
				}

				if ($bFixCrLf)
				{
					$sBuffer = \str_replace("\n", "\r\n", \str_replace("\r", '', $sBuffer));
				}

				$mResult += \strlen($sBuffer);

				foreach ($aWrite as $rWriteStream)
				{
					$mWriteResult = \fwrite($rWriteStream, $sBuffer);
					if (false === $mWriteResult)
					{
						$mResult = false;
						break 2;
					}
				}

				if ($bResetTimeLimit)
				{
					static::ResetTimeLimit();
				}
			}
		}

		if ($mResult && $bRewindOnComplete)
		{
			foreach ($aWrite as $rWriteStream)
			{
				if (\is_resource($rWriteStream))
				{
					\rewind($rWriteStream);
				}
			}
		}

		return $mResult;
	}

	public static function Utf7ModifiedToUtf8(string $sStr) : string
	{
		// imap_mutf7_to_utf8() doesn't support U+10000 and up,
		// thats why mb_convert_encoding is used
		return static::MbConvertEncoding($sStr, 'UTF7-IMAP', 'UTF-8');
	}

	public static function Utf8ToUtf7Modified(string $sStr) : string
	{
		return static::MbConvertEncoding($sStr, 'UTF-8', 'UTF7-IMAP');
	}

	public static function FunctionsCallable(array $aFunctionNames) : bool
	{
		foreach ($aFunctionNames as $sFunctionName) {
			if (!static::FunctionCallable($sFunctionName)) {
				return false;
			}
		}
		return true;
	}

	private static $disabled_functions = null;
	public static function FunctionCallable(string $sFunctionName) : bool
	{
		if (null === static::$disabled_functions) {
			static::$disabled_functions = \array_map('trim', \explode(',', \ini_get('disable_functions')));
		}
/*
		$disabled_classes = \explode(',', \ini_get('disable_classes'));
		\in_array($function, $disabled_classes);
*/
		return \function_exists($sFunctionName)
			&& !\in_array($sFunctionName, static::$disabled_functions);
//			&& \is_callable($mFunctionNameOrNames);
	}

	public static function Sha1Rand(string $sAdditionalSalt = '') : string
	{
		return \sha1($sAdditionalSalt . \random_bytes(16));
	}

	public static function ValidateDomain(string $sDomain, bool $bSimple = false) : bool
	{
		$aMatch = array();
		if ($bSimple)
		{
			return \preg_match('/.+(\.[a-zA-Z]+)$/', $sDomain, $aMatch) && !empty($aMatch[1]);
		}

		return \preg_match('/.+(\.[a-zA-Z]+)$/', $sDomain, $aMatch) && !empty($aMatch[1]) && \in_array($aMatch[1], \explode(' ',
			'.academy .actor .agency .audio .bar .beer .bike .blue .boutique .cab .camera .camp .capital .cards .careers .cash .catering .center .cheap .city .cleaning .clinic .clothing .club .coffee .community .company .computer .construction .consulting .contractors .cool .credit .dance .dating .democrat .dental .diamonds .digital .direct .directory .discount .domains .education .email .energy .equipment .estate .events .expert .exposed .fail .farm .fish .fitness .florist .fund .futbol .gallery .gift .glass .graphics .guru .help .holdings .holiday .host .hosting .house .institute .international .kitchen .land .life .lighting .limo .link .management .market .marketing .media .menu .moda .partners .parts .photo .photography .photos .pics .pink .press .productions .pub .red .rentals .repair .report .rest .sexy .shoes .social .solar .solutions .space .support .systems .tattoo .tax .technology .tips .today .tools .town .toys .trade .training .university .uno .vacations .vision .vodka .voyage .watch .webcam .wiki .work .works .wtf .zone .aero .asia .biz .cat .com .coop .edu .gov .info .int .jobs .mil .mobi .museum .name .net .org .pro .tel .travel .xxx .xyz '.
			'.ac .ad .ae .af .ag .ai .al .am .an .ao .aq .ar .as .at .au .aw .ax .az .ba .bb .bd .be .bf .bg .bh .bi .bj .bm .bn .bo .br .bs .bt .bv .bw .by .bz .ca .cc .cd .cf .cg .ch .ci .ck .cl .cm .cn .co .cr .cs .cu .cv .cx .cy .cz .dd .de .dj .dk .dm .do .dz .ec .ee .eg .er .es .et .eu .fi .fj .fk .fm .fo .fr .ga .gb .gd .ge .gf .gg .gh .gi .gl .gm .gn .gp .gq .gr .gs .gt .gu .gw .gy .hk .hm .hn .hr .ht .hu .id .ie .il .im .in .io .iq .ir .is .it .je .jm .jo .jp .ke .kg .kh .ki .km .kn .kp .kr .kw .ky .kz .la .lb .lc .li .lk .lr .ls .lt .lu .lv .ly .ma .mc .md .me .mg .mh .mk .ml .mm .mn .mo .mp .mq .mr .ms .mt .mu .mv .mw .mx .my .mz .na .nc .ne .nf .ng .ni .nl .no .np .nr .nu .nz .om .pa .pe .pf .pg .ph .pk .pl .pm .pn .pr .ps .pt .pw .py .qa .re .ro .rs .ru . .rw .sa .sb .sc .sd .se .sg .sh .si .sj .sk .sl .sm .sn .so .sr .st .su .sv .sy .sz .tc .td .tf .tg .th .tj .tk .tl .tm .tn .to .tp .tr .tt .tv .tw .tz .ua .ug .uk .us .uy .uz .va .vc .ve .vg .vi .vn .vu .wf .ws .ye .yt .za .zm .zw'
		));
	}

	public static function ValidateIP(string $sIp) : bool
	{
		return !empty($sIp) && $sIp === \filter_var($sIp, FILTER_VALIDATE_IP);
	}

	public static function IdnToUtf8(string $sStr, bool $bLowerIfAscii = false) : string
	{
		if (\strlen($sStr) && \preg_match('/(^|\.|@)xn--/i', $sStr))
		{
			try
			{
				$sStr = \SnappyMail\IDN::anyToUtf8($sStr);
			}
			catch (\Throwable $oException) {}
		}

		return $bLowerIfAscii ? static::StrMailDomainToLower($sStr) : $sStr;
	}

	public static function IdnToAscii(string $sStr, bool $bLowerIfAscii = false) : string
	{
		$sStr = $bLowerIfAscii ? static::StrMailDomainToLower($sStr) : $sStr;

		$sUser = '';
		$sDomain = $sStr;
		if (false !== \strpos($sStr, '@'))
		{
			$sUser = static::GetAccountNameFromEmail($sStr);
			$sDomain = static::GetDomainFromEmail($sStr);
		}

		if (\strlen($sDomain) && \preg_match('/[^\x20-\x7E]/', $sDomain))
		{
			try
			{
				$sDomain = \SnappyMail\IDN::anyToAscii($sDomain);
			}
			catch (\Throwable $oException) {}
		}

		return ('' === $sUser ? '' : $sUser.'@').$sDomain;
	}
}
