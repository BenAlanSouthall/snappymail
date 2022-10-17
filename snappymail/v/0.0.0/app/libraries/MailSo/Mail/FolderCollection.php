<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Mail;

/**
 * @category MailSo
 * @package Mail
 */
class FolderCollection extends \MailSo\Base\Collection
{
	/**
	 * @var bool
	 */
	public $Optimized = false;

	public $TotalCount = 0;

	public function append($oFolder, bool $bToTop = false) : void
	{
		assert($oFolder instanceof Folder);
		parent::append($oFolder, $bToTop);
	}

	public function FindDelimiter() : string
	{
		$oFolder = $this['INBOX'] ?? $this[0] ?? null;
		return $oFolder ? $oFolder->Delimiter() : '/';
	}
}
