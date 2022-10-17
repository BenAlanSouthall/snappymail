<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 * (c) 2021 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Imap\Requests;

use MailSo\Imap\Exceptions\RuntimeException;

/**
 * @category MailSo
 * @package Imap
 */
class ESEARCH extends Request
{
	public
		$sCriterias = 'ALL',
		$aReturn = [
		/**
		   ALL
			  Return all message numbers/UIDs which match the search criteria,
			  in the requested sort order, using a sequence-set.

		   COUNT
			  As in [ESEARCH].

		   MAX
			  Return the message number/UID of the highest sorted message
			  satisfying the search criteria.

		   MIN
			  Return the message number/UID of the lowest sorted message
			  satisfying the search criteria.

		   PARTIAL 1:500
			  Return all message numbers/UIDs which match the search criteria,
			  in the requested sort order, using a sequence-set.
		 */
		],
		$bUid = true,
		$sLimit = '',
		$sCharset = '',
		// https://datatracker.ietf.org/doc/html/rfc7377
		$aMailboxes = [],
		$aSubtrees = [],
		$aSubtreesOne = [];

	function __construct(\MailSo\Imap\ImapClient $oImapClient)
	{
		if (!$oImapClient->IsSupported('ESEARCH')) {
			$oImapClient->writeLogException(
				new RuntimeException('ESEARCH is not supported'),
				\LOG_ERR, true);
		}
		parent::__construct($oImapClient);
	}

	public function SendRequest() : string
	{
		$sCmd = 'SEARCH';
		$aRequest = array();

/*		// RFC 6203
		if (false !== \stripos($this->sCriterias, 'FUZZY') && !$this->oImapClient->IsSupported('SEARCH=FUZZY')) {
			$this->oImapClient->writeLogException(
				new RuntimeException('SEARCH=FUZZY is not supported'),
				\LOG_ERR, true);
		}
*/

		$aFolders = [];
		if ($this->aMailboxes) {
			$aFolders[] = 'mailboxes';
			$aFolders[] = $this->aMailboxes;
		}
		if ($this->aSubtrees) {
			$aFolders[] = 'subtree';
			$aFolders[] = $this->aSubtrees;
		}
		if ($this->aSubtreesOne) {
			$aFolders[] = 'subtree-one';
			$aFolders[] = $this->aSubtreesOne;
		}
		if ($aFolders) {
			if (!$this->oImapClient->IsSupported('MULTISEARCH')) {
				$this->oImapClient->writeLogException(
					new RuntimeException('MULTISEARCH is not supported'),
					\LOG_ERR, true);
			}
			$sCmd = 'ESEARCH';
			$aReques[] = 'IN';
			$aReques[] = $aFolders;
		}

		if (\strlen($this->sCharset)) {
			$aRequest[] = 'CHARSET';
			$aRequest[] = \strtoupper($this->sCharset);
		}

		$aRequest[] = 'RETURN';
		if ($this->aReturn) {
			// RFC 5267 checks
			if (!$this->oImapClient->IsSupported('CONTEXT=SEARCH')) {
				foreach ($this->aReturn as $sReturn) {
					if (\preg_match('/PARTIAL|UPDATE|CONTEXT/i', $sReturn)) {
						$this->oImapClient->writeLogException(
							new RuntimeException('CONTEXT=SEARCH is not supported'),
							\LOG_ERR, true);
					}
				}
			}
			$aRequest[] = $this->aReturn;
		} else {
			$aRequest[] = array('ALL');
		}

		$aRequest[] = (\strlen($this->sCriterias) && '*' !== $this->sCriterias) ? $this->sCriterias : 'ALL';

		if (\strlen($this->sLimit)) {
			$aRequest[] = $this->sLimit;
		}

		return $this->oImapClient->SendRequest(
			($this->bUid ? 'UID ' : '') . $sCmd,
			$aRequest
		);
	}
}
