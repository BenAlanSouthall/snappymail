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

namespace MailSo\Imap\Commands;

use MailSo\Imap\Folder;
use MailSo\Imap\FolderInformation;
use MailSo\Imap\SequenceSet;
use MailSo\Imap\Enumerations\FolderStatus;
use MailSo\Imap\Enumerations\FolderResponseStatus;

/**
 * @category MailSo
 * @package Imap
 */
trait Folders
{
	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderCreate(string $sFolderName) : self
	{
		$this->SendRequestGetResponse('CREATE', array(
			$this->EscapeFolderName($sFolderName)
//			, ['(USE (\Drafts \Sent))'] RFC 6154
		));
		return $this;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderDelete(string $sFolderName) : self
	{
		// Uncomment will work issue #124 ?
//		$this->selectOrExamineFolder($sFolderName, true);
		$this->SendRequestGetResponse('DELETE',
			array($this->EscapeFolderName($sFolderName)));
//		$this->FolderCheck();
//		$this->FolderUnselect();

		// Will this workaround solve Dovecot issue #124 ?
		try {
			$this->FolderRename($sFolderName, "{$sFolderName}-dummy");
			$this->FolderRename("{$sFolderName}-dummy", $sFolderName);
		} catch (\Throwable $e) {
		}

		return $this;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderSubscribe(string $sFolderName) : self
	{
		$this->SendRequestGetResponse('SUBSCRIBE',
			array($this->EscapeFolderName($sFolderName)));
		return $this;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderUnsubscribe(string $sFolderName) : self
	{
		$this->SendRequestGetResponse('UNSUBSCRIBE',
			array($this->EscapeFolderName($sFolderName)));
		return $this;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderRename(string $sOldFolderName, string $sNewFolderName) : self
	{
		$this->SendRequestGetResponse('RENAME', array(
			$this->EscapeFolderName($sOldFolderName),
			$this->EscapeFolderName($sNewFolderName)));
		return $this;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 *
	 * https://datatracker.ietf.org/doc/html/rfc9051#section-6.3.11
	 */
	public function FolderStatus(string $sFolderName) : FolderInformation
	{
		$aStatusItems = array(
			FolderResponseStatus::MESSAGES,
			FolderResponseStatus::UNSEEN,
			FolderResponseStatus::UIDNEXT,
			FolderResponseStatus::UIDVALIDITY
		);
		if ($this->IsSupported('CONDSTORE')) {
			$aStatusItems[] = FolderResponseStatus::HIGHESTMODSEQ;
		}
		if ($this->IsSupported('APPENDLIMIT')) {
			$aStatusItems[] = FolderResponseStatus::APPENDLIMIT;
		}
		if ($this->IsSupported('OBJECTID')) {
			$aStatusItems[] = FolderResponseStatus::MAILBOXID;
/*
		} else if ($this->IsSupported('X-DOVECOT')) {
			$aStatusItems[] = 'X-GUID';
*/
		}
/*		// STATUS SIZE can take a significant amount of time, therefore not active
		if ($this->IsSupported('IMAP4rev2')) {
			$aStatusItems[] = FolderResponseStatus::SIZE;
		}
*/
		$oFolderInfo = $this->oCurrentFolderInfo;
		$bReselect = false;
		$bWritable = false;
		if ($oFolderInfo && $sFolderName === $oFolderInfo->FolderName) {
			/**
			 * There's a long standing IMAP CLIENTBUG where STATUS command is executed
			 * after SELECT/EXAMINE on same folder (it should not).
			 * So we must unselect the folder to be able to get the APPENDLIMIT and UNSEEN.
			 */
/*
			if ($this->IsSupported('ESEARCH')) {
				$aResult = $oFolderInfo->getStatusItems();
				// SELECT or EXAMINE command then UNSEEN is the message sequence number of the first unseen message
				$aResult['UNSEEN'] = $this->MessageSimpleESearch('UNSEEN', ['COUNT'])['COUNT'];
				return $aResult;
			}
*/
			$bWritable = $oFolderInfo->IsWritable;
			$bReselect = true;
			$this->FolderUnselect();
		}

		$oInfo = new FolderInformation($sFolderName, false);
		$this->SendRequest('STATUS', array($this->EscapeFolderName($sFolderName), $aStatusItems));
		foreach ($this->yieldUntaggedResponses() as $oResponse) {
			$oInfo->setStatusFromResponse($oResponse);
		}

		if ($bReselect) {
			$this->selectOrExamineFolder($sFolderName, $bWritable, false);
//			$this->oCurrentFolderInfo->UNSEEN = $oInfo->UNSEEN;
		}

		return $oInfo;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderCheck() : self
	{
		if ($this->IsSelected()) {
			$this->SendRequestGetResponse('CHECK');
		}
		return $this;
	}

	/**
	 * This also expunge the mailbox
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderClose() : int
	{
		if ($this->IsSelected()) {
			$this->SendRequestGetResponse('CLOSE');
			$this->oCurrentFolderInfo = null;
			// https://datatracker.ietf.org/doc/html/rfc5162#section-3.4
			// return HIGHESTMODSEQ ?
		}
		return 0;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderUnselect() : self
	{
		if ($this->IsSelected()) {
			if ($this->IsSupported('UNSELECT')) {
				$this->SendRequestGetResponse('UNSELECT');
				$this->oCurrentFolderInfo = null;
			} else {
				try {
					$this->SendRequestGetResponse('SELECT', ['""']);
					// * OK [CLOSED] Previous mailbox closed.
					// 3 NO [CANNOT] Invalid mailbox name: Name is empty
				} catch (\MailSo\Imap\Exceptions\NegativeResponseException $e) {
					if ('NO' === $e->GetResponseStatus()) {
						$this->oCurrentFolderInfo = null;
					}
				}
			}
		}
		return $this;
	}

	/**
	 * The EXPUNGE command permanently removes all messages that have the
	 * \Deleted flag set from the currently selected mailbox.
	 *
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderExpunge(SequenceSet $oUidRange = null) : void
	{
		$sCmd = 'EXPUNGE';
		$aArguments = array();

		if ($oUidRange && \count($oUidRange) && $oUidRange->UID && $this->IsSupported('UIDPLUS')) {
			$sCmd = 'UID '.$sCmd;
			$aArguments = array((string) $oUidRange);
		}

		// https://datatracker.ietf.org/doc/html/rfc5162#section-3.5
		// Before returning an OK to the client, those messages that are removed
		// are reported using a VANISHED response or EXPUNGE responses.

		$this->SendRequestGetResponse($sCmd, $aArguments);
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderHierarchyDelimiter(string $sFolderName = '') : ?string
	{
		$oResponse = $this->SendRequestGetResponse('LIST', ['""', $this->EscapeFolderName($sFolderName)]);
		return ('LIST' === $oResponse[0]->ResponseList[1]) ? $oResponse[0]->ResponseList[3] : null;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderSelect(string $sFolderName, bool $bReSelectSameFolders = false) : FolderInformation
	{
		return $this->selectOrExamineFolder($sFolderName, true, $bReSelectSameFolders);
	}

	/**
	 * The EXAMINE command is identical to SELECT and returns the same output;
	 * however, the selected mailbox is identified as read-only.
	 * No changes to the permanent state of the mailbox, including per-user state,
	 * are permitted; in particular, EXAMINE MUST NOT cause messages to lose the \Recent flag.
	 *
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderExamine(string $sFolderName, bool $bReSelectSameFolders = false) : FolderInformation
	{
		return $this->selectOrExamineFolder($sFolderName, $this->__FORCE_SELECT_ON_EXAMINE__, $bReSelectSameFolders);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 *
	 * REQUIRED IMAP4rev2 untagged responses:  FLAGS, EXISTS, LIST
	 * REQUIRED IMAP4rev2 OK untagged responses:  PERMANENTFLAGS, UIDNEXT, UIDVALIDITY
	 */
	protected function selectOrExamineFolder(string $sFolderName, bool $bIsWritable, bool $bReSelectSameFolders) : FolderInformation
	{
		if (!$bReSelectSameFolders
		  && $this->oCurrentFolderInfo
		  && $sFolderName === $this->oCurrentFolderInfo->FolderName
		  && $bIsWritable === $this->oCurrentFolderInfo->IsWritable
		) {
			return $this->oCurrentFolderInfo;
		}

		if (!\strlen(\trim($sFolderName)))
		{
			throw new \MailSo\Base\Exceptions\InvalidArgumentException;
		}

		$aSelectParams = array();

/*
		// RFC 5162
		if ($this->IsSupported('QRESYNC')) {
			$this->Enable(['QRESYNC', 'CONDSTORE']);
			- the last known UIDVALIDITY,
			- the last known modification sequence,
			- the optional set of known UIDs,
			- and an optional parenthesized list of known sequence ranges and their corresponding UIDs.
			QRESYNC (UIDVALIDITY HIGHESTMODSEQ 41,43:211,214:541)
			QRESYNC (67890007 20050715194045000 41,43:211,214:541)
		}

		// RFC 4551
		if ($this->IsSupported('CONDSTORE')) {
			$aSelectParams[] = 'CONDSTORE';
		}

		// RFC 5738
		if ($this->UTF8) {
			$aSelectParams[] = 'UTF8';
		}
*/

		$aParams = array(
			$this->EscapeFolderName($sFolderName)
		);
		if ($aSelectParams) {
			$aParams[] = $aSelectParams;
		}

		$oResult = new FolderInformation($sFolderName, $bIsWritable);

		/**
		 * IMAP4rev2 SELECT/EXAMINE are now required to return an untagged LIST response.
		 */
		$this->SendRequest($bIsWritable ? 'SELECT' : 'EXAMINE', $aParams);
		foreach ($this->yieldUntaggedResponses() as $oResponse) {
			if (!$oResult->setStatusFromResponse($oResponse)) {
				// OK untagged responses
				if (\is_array($oResponse->OptionalResponse)) {
					$key = $oResponse->OptionalResponse[0];
					if (\count($oResponse->OptionalResponse) > 1) {
						if ('PERMANENTFLAGS' === $key && \is_array($oResponse->OptionalResponse[1])) {
							$oResult->PermanentFlags = \array_map('\\MailSo\\Base\\Utils::Utf7ModifiedToUtf8', $oResponse->OptionalResponse[1]);
						}
					} else if ('READ-ONLY' === $key) {
//						$oResult->IsWritable = false;
					} else if ('READ-WRITE' === $key) {
//						$oResult->IsWritable = true;
					} else if ('NOMODSEQ' === $key) {
						// https://datatracker.ietf.org/doc/html/rfc4551#section-3.1.2
					}
				}

				// untagged responses
				else if (\count($oResponse->ResponseList) > 2
				 && 'FLAGS' === $oResponse->ResponseList[1]
				 && \is_array($oResponse->ResponseList[2])) {
					$oResult->Flags = \array_map('\\MailSo\\Base\\Utils::Utf7ModifiedToUtf8', $oResponse->ResponseList[2]);
				}
			}
		}

		$this->oCurrentFolderInfo = $oResult;

		return $oResult;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderList(string $sParentFolderName, string $sListPattern, bool $bIsSubscribeList = false, bool $bUseListStatus = false) : array
	{
		$sCmd = 'LIST';

		$aParameters = array();
		$aReturnParams = array();

		if ($bIsSubscribeList) {
			// IMAP4rev2 deprecated
			$sCmd = 'LSUB';
		} else if ($this->IsSupported('LIST-EXTENDED')) {
			// RFC 5258
			$aReturnParams[] = 'SUBSCRIBED';
//			$aReturnParams[] = 'CHILDREN';
			if ($bIsSubscribeList) {
				$aParameters[] = ['SUBSCRIBED'/*,'REMOTE','RECURSIVEMATCH'*/];
			} else {
//				$aParameters[0] = '()';
			}
			// RFC 6154
			if ($this->IsSupported('SPECIAL-USE')) {
				$aReturnParams[] = 'SPECIAL-USE';
			}
		}

		$aParameters[] = $this->EscapeFolderName($sParentFolderName);
		$aParameters[] = $this->EscapeString(\trim($sListPattern));
//		$aParameters[] = $this->EscapeString(\strlen(\trim($sListPattern)) ? $sListPattern : '*');

		// RFC 5819
		if ($bUseListStatus && !$bIsSubscribeList && $this->IsSupported('LIST-STATUS'))
		{
			$aL = array(
				FolderStatus::MESSAGES,
				FolderStatus::UNSEEN,
				FolderStatus::UIDNEXT
			);
			// RFC 4551
			if ($this->IsSupported('CONDSTORE')) {
				$aL[] = FolderStatus::HIGHESTMODSEQ;
			}
			// RFC 7889
			if ($this->IsSupported('APPENDLIMIT')) {
				$aL[] = FolderStatus::APPENDLIMIT;
			}
			// RFC 8474
			if ($this->IsSupported('OBJECTID')) {
				$aL[] = FolderStatus::MAILBOXID;
/*
			} else if ($this->IsSupported('X-DOVECOT')) {
				$aL[] = 'X-GUID';
*/
			}

			$aReturnParams[] = 'STATUS';
			$aReturnParams[] = $aL;
		}
/*
		// RFC 5738
		if ($this->UTF8) {
			$aReturnParams[] = 'UTF8'; // 'UTF8ONLY';
		}
*/
		if ($aReturnParams) {
			$aParameters[] = 'RETURN';
			$aParameters[] = $aReturnParams;
		}

		$bPassthru = false;
		$aReturn = array();

		// RFC 5464
		$bMetadata = !$bIsSubscribeList && $this->IsSupported('METADATA');
		$aMetadata = null;
		if ($bMetadata) {
			// Dovecot supports fetching all METADATA at once
			$aMetadata = $this->getAllMetadata();
		}

		$this->SendRequest($sCmd, $aParameters);
		if ($bPassthru) {
			$this->streamResponse();
		} else {
			$sDelimiter = '';
			$bInbox = false;
			foreach ($this->yieldUntaggedResponses() as $oResponse) {
				if ('STATUS' === $oResponse->StatusOrIndex && isset($oResponse->ResponseList[2])) {
					$sFullName = $this->toUTF8($oResponse->ResponseList[2]);
					if (!isset($aReturn[$sFullName])) {
						$aReturn[$sFullName] = new Folder($sFullName);
					}
					$aReturn[$sFullName]->setStatusFromResponse($oResponse);
				}
				else if ($sCmd === $oResponse->StatusOrIndex && 5 === \count($oResponse->ResponseList)) {
					try
					{
						$sFullName = $this->toUTF8($oResponse->ResponseList[4]);

						/**
						 * $oResponse->ResponseList[0] = *
						 * $oResponse->ResponseList[1] = LIST (all) | LSUB (subscribed)
						 * $oResponse->ResponseList[2] = Flags
						 * $oResponse->ResponseList[3] = Delimiter
						 * $oResponse->ResponseList[4] = FullName
						 */
						if (!isset($aReturn[$sFullName])) {
							$oFolder = new Folder($sFullName,
								$oResponse->ResponseList[3], $oResponse->ResponseList[2]);
							$aReturn[$sFullName] = $oFolder;
						} else {
							$oFolder = $aReturn[$sFullName];
							$oFolder->setDelimiter($oResponse->ResponseList[3]);
							$oFolder->setFlags($oResponse->ResponseList[2]);
						}

						if ($oFolder->IsInbox()) {
							$bInbox = true;
						}

						if (!$sDelimiter) {
							$sDelimiter = $oFolder->Delimiter();
						}

						if (isset($aMetadata[$oResponse->ResponseList[4]])) {
							$oFolder->SetAllMetadata($aMetadata[$oResponse->ResponseList[4]]);
						}

						$aReturn[$sFullName] = $oFolder;
					}
					catch (\MailSo\Base\Exceptions\InvalidArgumentException $oException)
					{
						$this->writeLogException($oException, \LOG_WARNING, false);
					}
					catch (\Throwable $oException)
					{
						$this->writeLogException($oException, \LOG_WARNING, false);
					}
				}
			}

			if (!$bInbox && !$sParentFolderName && !isset($aReturn['INBOX'])) {
				$aReturn['INBOX'] = new Folder('INBOX', $sDelimiter);
			}
		}

		// RFC 5464
		if ($bMetadata && !$aMetadata /*&& 50 < \count($aReturn)*/) {
			foreach ($aReturn as $oFolder) {
//				if (2 > \substr_count($oFolder->FullName(), $oFolder->Delimiter()))
				try {
					$oFolder->SetAllMetadata(
						$this->getMetadata($oFolder->FullName(), ['/shared', '/private'], ['DEPTH'=>'infinity'])
					);
				} catch (\Throwable $e) {
					// Ignore error
				}
			}
		}

		return $aReturn;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderSubscribeList(string $sParentFolderName, string $sListPattern) : array
	{
		return $this->FolderList($sParentFolderName, $sListPattern, true);
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderStatusList(string $sParentFolderName, string $sListPattern) : array
	{
		return $this->FolderList($sParentFolderName, $sListPattern, false, true);
	}

}
