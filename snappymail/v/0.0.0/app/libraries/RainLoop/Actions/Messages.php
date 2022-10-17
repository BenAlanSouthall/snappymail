<?php

namespace RainLoop\Actions;

use RainLoop\Enumerations\Capa;
use RainLoop\Exceptions\ClientException;
use RainLoop\Model\Account;
use RainLoop\Notifications;
use MailSo\Imap\SequenceSet;
use MailSo\Imap\Enumerations\FetchType;
use MailSo\Imap\Enumerations\MessageFlag;
use MailSo\Mime\Part as MimePart;

trait Messages
{
	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoMessageList() : array
	{
//		\sleep(1);
//		throw new ClientException(Notifications::CantGetMessageList);
		$oParams = new \MailSo\Mail\MessageListParams;

		$sRawKey = $this->GetActionParam('RawKey', '');
		$aValues = \json_decode(\MailSo\Base\Utils::UrlSafeBase64Decode($sRawKey), true);
		if ($aValues && 6 < \count($aValues))
		{
			$this->verifyCacheByKey($sRawKey);

//			$oParams->sHash = (string) $aValues['Hash'];
			$oParams->sFolderName = (string) $aValues['Folder'];
			$oParams->iLimit = $aValues['Limit'];
			$oParams->iOffset = $aValues['Offset'];
			$oParams->sSearch = (string) $aValues['Search'];
			$oParams->sSort = (string) $aValues['Sort'];
			if (isset($aValues['UidNext'])) {
				$oParams->iPrevUidNext = $aValues['UidNext'];
			}
			$oParams->bUseThreads = !empty($aValues['UseThreads']);
			if ($oParams->bUseThreads && isset($aValues['ThreadUid'])) {
				$oParams->iThreadUid = $aValues['ThreadUid'];
			}
		}
		else
		{
			$oParams->sFolderName = $this->GetActionParam('Folder', '');
			$oParams->iOffset = $this->GetActionParam('Offset', 0);
			$oParams->iLimit = $this->GetActionParam('Limit', 10);
			$oParams->sSearch = $this->GetActionParam('Search', '');
			$oParams->sSort = $this->GetActionParam('Sort', '');
			$oParams->iPrevUidNext = $this->GetActionParam('UidNext', 0);
			$oParams->bUseThreads = !empty($this->GetActionParam('UseThreads', '0'));
			if ($oParams->bUseThreads) {
				$oParams->iThreadUid = $this->GetActionParam('ThreadUid', '');
			}
		}

		if (!\strlen($oParams->sFolderName))
		{
			throw new ClientException(Notifications::CantGetMessageList);
		}

		$oAccount = $this->initMailClientConnection();

		try
		{
			if (!$this->Config()->Get('labs', 'use_imap_thread', false)) {
				$oParams->bUseThreads = false;
			}

			$oParams->oCacher = $this->cacherForUids();
			$oParams->bUseSortIfSupported = !!$this->Config()->Get('labs', 'use_imap_sort', true);

			$oSettingsLocal = $this->SettingsProvider(true)->Load($oAccount);
			if ($oSettingsLocal instanceof \RainLoop\Settings) {
				$oParams->bHideDeleted = !empty($oSettingsLocal->GetConf('HideDeleted', 1));
			}

			$oMessageList = $this->MailClient()->MessageList($oParams);
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::CantGetMessageList, $oException);
		}

		if ($oMessageList)
		{
			$this->cacheByKey($sRawKey);
		}

		return $this->DefaultResponse(__FUNCTION__, $oMessageList);
	}

	public function DoSaveMessage() : array
	{
		$oAccount = $this->initMailClientConnection();

		$sDraftFolder = $this->GetActionParam('SaveFolder', '');
		if (!\strlen($sDraftFolder))
		{
			throw new ClientException(Notifications::UnknownError);
		}

		$oMessage = $this->buildMessage($oAccount, true);

		$this->Plugins()->RunHook('filter.save-message', array($oMessage));

		$mResult = false;
		if ($oMessage)
		{
			$rMessageStream = \MailSo\Base\ResourceRegistry::CreateMemoryResource();

			$iMessageStreamSize = \MailSo\Base\Utils::MultipleStreamWriter(
				$oMessage->ToStream(false), array($rMessageStream), 8192, true, true);

			if (false !== $iMessageStreamSize)
			{
				$sMessageId = $oMessage->MessageId();

				\rewind($rMessageStream);

				$iNewUid = 0;
				$this->MailClient()->MessageAppendStream(
					$rMessageStream, $iMessageStreamSize, $sDraftFolder, array(MessageFlag::SEEN), $iNewUid
				);

				if (!empty($sMessageId) && (null === $iNewUid || 0 === $iNewUid))
				{
					$iNewUid = $this->MailClient()->FindMessageUidByMessageId($sDraftFolder, $sMessageId);
				}

				$mResult = true;

				$sMessageFolder = $this->GetActionParam('MessageFolder', '');
				$iMessageUid = (int) $this->GetActionParam('MessageUid', 0);
				if (\strlen($sMessageFolder) && 0 < $iMessageUid)
				{
					$this->MailClient()->MessageDelete($sMessageFolder, new SequenceSet($iMessageUid));
				}

				if (null !== $iNewUid && 0 < $iNewUid)
				{
					$mResult = array(
						'NewFolder' => $sDraftFolder,
						'NewUid' => $iNewUid
					);
				}
			}
		}

		return $this->DefaultResponse(__FUNCTION__, $mResult);
	}

	public function DoSendMessage() : array
	{
		$oAccount = $this->initMailClientConnection();

		$oConfig = $this->Config();

		$sSentFolder = $this->GetActionParam('SaveFolder', '');
		$aDraftInfo = $this->GetActionParam('DraftInfo', null);

		$oMessage = $this->buildMessage($oAccount, false);

		$this->Plugins()->RunHook('filter.send-message', array($oMessage));

		$mResult = false;
		try
		{
			if ($oMessage)
			{
				$rMessageStream = \MailSo\Base\ResourceRegistry::CreateMemoryResource();

				$iMessageStreamSize = \MailSo\Base\Utils::MultipleStreamWriter(
					$oMessage->ToStream(true), array($rMessageStream), 8192, true, true, true);

				if (false !== $iMessageStreamSize)
				{
					$bDsn = !empty($this->GetActionParam('Dsn', 0));
					$this->smtpSendMessage($oAccount, $oMessage, $rMessageStream, $iMessageStreamSize, $bDsn, true);

					if (\is_array($aDraftInfo) && 3 === \count($aDraftInfo))
					{
						$sDraftInfoType = $aDraftInfo[0];
						$iDraftInfoUid = (int) $aDraftInfo[1];
						$sDraftInfoFolder = $aDraftInfo[2];

						try
						{
							switch (\strtolower($sDraftInfoType))
							{
								case 'reply':
								case 'reply-all':
									$this->MailClient()->MessageSetFlag($sDraftInfoFolder, new SequenceSet($iDraftInfoUid), MessageFlag::ANSWERED);
									break;
								case 'forward':
									$this->MailClient()->MessageSetFlag($sDraftInfoFolder, new SequenceSet($iDraftInfoUid), MessageFlag::FORWARDED);
									break;
							}
						}
						catch (\Throwable $oException)
						{
							$this->Logger()->WriteException($oException, \LOG_ERR);
						}
					}

					if (\strlen($sSentFolder))
					{
						try
						{
							if (!$oMessage->GetBcc())
							{
								if (\is_resource($rMessageStream))
								{
									\rewind($rMessageStream);
								}

								$this->Plugins()->RunHook('filter.send-message-stream',
									array($oAccount, &$rMessageStream, &$iMessageStreamSize));

								$this->MailClient()->MessageAppendStream(
									$rMessageStream, $iMessageStreamSize, $sSentFolder, array(MessageFlag::SEEN)
								);
							}
							else
							{
								$rAppendMessageStream = \MailSo\Base\ResourceRegistry::CreateMemoryResource();

								$iAppendMessageStreamSize = \MailSo\Base\Utils::MultipleStreamWriter(
									$oMessage->ToStream(false), array($rAppendMessageStream), 8192, true, true, true);

								$this->Plugins()->RunHook('filter.send-message-stream',
									array($oAccount, &$rAppendMessageStream, &$iAppendMessageStreamSize));

								$this->MailClient()->MessageAppendStream(
									$rAppendMessageStream, $iAppendMessageStreamSize, $sSentFolder, array(MessageFlag::SEEN)
								);

								if (\is_resource($rAppendMessageStream))
								{
									fclose($rAppendMessageStream);
								}
							}
						}
						catch (\Throwable $oException)
						{
							throw new ClientException(Notifications::CantSaveMessage, $oException);
						}
					}

					if (\is_resource($rMessageStream))
					{
						\fclose($rMessageStream);
					}

					$this->deleteMessageAttachments($oAccount);

					$sDraftFolder = $this->GetActionParam('MessageFolder', '');
					$iDraftUid = (int) $this->GetActionParam('MessageUid', 0);
					if (\strlen($sDraftFolder) && 0 < $iDraftUid)
					{
						try
						{
							$this->MailClient()->MessageDelete($sDraftFolder, new SequenceSet($iDraftUid));
						}
						catch (\Throwable $oException)
						{
							$this->Logger()->WriteException($oException, \LOG_ERR);
						}
					}

					$mResult = true;
				}
			}
		}
		catch (ClientException $oException)
		{
			throw $oException;
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::CantSendMessage, $oException);
		}

		if (false === $mResult)
		{
			throw new ClientException(Notifications::CantSendMessage);
		}

		try
		{
			if ($oMessage && $this->AddressBookProvider($oAccount)->IsActive())
			{
				$aArrayToFrec = array();
				$oToCollection = $oMessage->GetTo();
				if ($oToCollection)
				{
					foreach ($oToCollection as /* @var $oEmail \MailSo\Mime\Email */ $oEmail)
					{
						$aArrayToFrec[$oEmail->GetEmail(true)] = $oEmail->ToString(false, true);
					}
				}

				if (\count($aArrayToFrec))
				{
					$oSettings = $this->SettingsProvider()->Load($oAccount);

					$this->AddressBookProvider($oAccount)->IncFrec(
						\array_values($aArrayToFrec),
						!!$oSettings->GetConf('ContactsAutosave', !!$oConfig->Get('defaults', 'contacts_autosave', true))
					);
				}
			}
		}
		catch (\Throwable $oException)
		{
			$this->Logger()->WriteException($oException);
		}

		return $this->TrueResponse(__FUNCTION__);
	}

	public function DoSendReadReceiptMessage() : array
	{
		$oAccount = $this->initMailClientConnection();

		$oMessage = $this->buildReadReceiptMessage($oAccount);

		$this->Plugins()->RunHook('filter.send-read-receipt-message', array($oMessage, $oAccount));

		$mResult = false;
		try
		{
			if ($oMessage)
			{
				$rMessageStream = \MailSo\Base\ResourceRegistry::CreateMemoryResource();

				$iMessageStreamSize = \MailSo\Base\Utils::MultipleStreamWriter(
					$oMessage->ToStream(true), array($rMessageStream), 8192, true, true, true);

				if (false !== $iMessageStreamSize)
				{
					$this->smtpSendMessage($oAccount, $oMessage, $rMessageStream, $iMessageStreamSize, false, false);

					if (\is_resource($rMessageStream))
					{
						\fclose($rMessageStream);
					}

					$mResult = true;

					$sFolderFullName = $this->GetActionParam('MessageFolder', '');
					$iUid = (int) $this->GetActionParam('MessageUid', 0);

					$this->Cacher($oAccount)->Set(\RainLoop\KeyPathHelper::ReadReceiptCache($oAccount->Email(), $sFolderFullName, $iUid), '1');

					if (\strlen($sFolderFullName) && 0 < $iUid)
					{
						try
						{
							$this->MailClient()->MessageSetFlag($sFolderFullName, new SequenceSet($iUid), MessageFlag::MDNSENT, true, true);
						}
						catch (\Throwable $oException) {}
					}
				}
			}
		}
		catch (ClientException $oException)
		{
			throw $oException;
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::CantSendMessage, $oException);
		}

		if (false === $mResult)
		{
			throw new ClientException(Notifications::CantSendMessage);
		}

		return $this->TrueResponse(__FUNCTION__);
	}

	public function DoMessageSetSeen() : array
	{
		return $this->messageSetFlag(MessageFlag::SEEN, __FUNCTION__);
	}

	public function DoMessageSetSeenToAll() : array
	{
		$this->initMailClientConnection();

		$sThreadUids = \trim($this->GetActionParam('ThreadUids', ''));

		try
		{
			$this->MailClient()->MessageSetFlag(
				$this->GetActionParam('Folder', ''),
				empty($sThreadUids) ? new SequenceSet('1:*', false) : new SequenceSet(\explode(',', $sThreadUids)),
				MessageFlag::SEEN,
				!empty($this->GetActionParam('SetAction', '0'))
			);
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::MailServerError, $oException);
		}

		return $this->TrueResponse(__FUNCTION__);
	}

	public function DoMessageSetFlagged() : array
	{
		return $this->messageSetFlag(MessageFlag::FLAGGED, __FUNCTION__, true);
	}

	public function DoMessageSetKeyword() : array
	{
		return $this->messageSetFlag($this->GetActionParam('Keyword', ''), __FUNCTION__, true);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoMessage() : array
	{
		$sRawKey = (string) $this->GetActionParam('RawKey', '');

		$sFolder = '';
		$iUid = 0;

		$aValues = \json_decode(\MailSo\Base\Utils::UrlSafeBase64Decode($sRawKey), true);
		if ($aValues && 2 <= \count($aValues))
		{
			$sFolder = (string) $aValues[0];
			$iUid = (int) $aValues[1];

			$this->verifyCacheByKey($sRawKey);
		}
		else
		{
			$sFolder = $this->GetActionParam('Folder', '');
			$iUid = (int) $this->GetActionParam('Uid', 0);
		}

		$oAccount = $this->initMailClientConnection();

		try
		{
			$oMessage = $this->MailClient()->Message($sFolder, $iUid, true,
				$this->cacherForThreads(),
				(int) $this->Config()->Get('labs', 'imap_body_text_limit', 0)
			);
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::CantGetMessage, $oException);
		}

		if ($oMessage)
		{
			$this->Plugins()->RunHook('filter.result-message', array($oMessage));

			$this->cacheByKey($sRawKey);
		}

		return $this->DefaultResponse(__FUNCTION__, $oMessage);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoMessageDelete() : array
	{
		$this->initMailClientConnection();

		$sFolder = $this->GetActionParam('Folder', '');
		$aUids = \explode(',', (string) $this->GetActionParam('Uids', ''));

		try
		{
			$this->MailClient()->MessageDelete($sFolder, new SequenceSet($aUids),
				!!$this->Config()->Get('labs', 'use_imap_expunge_all_on_delete', false));
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::CantDeleteMessage, $oException);
		}

		$sHash = '';
		try
		{
			$sHash = $this->MailClient()->FolderHash($sFolder);
		}
		catch (\Throwable $oException)
		{
			\SnappyMail\Log::warning('IMAP', "FolderHash({$sFolder}) Exception: {$oException->getMessage()}");
		}

		return $this->DefaultResponse(__FUNCTION__, $sHash ? array($sFolder, $sHash) : array($sFromFolder));
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoMessageMove() : array
	{
		$this->initMailClientConnection();

		$sFromFolder = $this->GetActionParam('FromFolder', '');
		$sToFolder = $this->GetActionParam('ToFolder', '');

		$oUids = new SequenceSet(\explode(',', (string) $this->GetActionParam('Uids', '')));

		if (!empty($this->GetActionParam('MarkAsRead', '0')))
		{
			try
			{
				$this->MailClient()->MessageSetFlag($sFromFolder, $oUids, MessageFlag::SEEN);
			}
			catch (\Throwable $oException)
			{
				unset($oException);
			}
		}

		$sLearning = $this->GetActionParam('Learning', '');
		if ($sLearning)
		{
			try
			{
				if ('SPAM' === $sLearning) {
					$this->MailClient()->MessageSetFlag($sFromFolder, $oUids, MessageFlag::JUNK);
					$this->MailClient()->MessageSetFlag($sFromFolder, $oUids, MessageFlag::NOTJUNK, false);
				} else if ('HAM' === $sLearning) {
					$this->MailClient()->MessageSetFlag($sFromFolder, $oUids, MessageFlag::NOTJUNK);
					$this->MailClient()->MessageSetFlag($sFromFolder, $oUids, MessageFlag::JUNK, false);
				}
			}
			catch (\Throwable $oException)
			{
				unset($oException);
			}
		}

		try
		{
			$this->MailClient()->MessageMove($sFromFolder, $sToFolder, $oUids,
				!!$this->Config()->Get('labs', 'use_imap_move', true),
				!!$this->Config()->Get('labs', 'use_imap_expunge_all_on_delete', false)
			);
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::CantMoveMessage, $oException);
		}

		$sHash = '';
		try
		{
			$sHash = $this->MailClient()->FolderHash($sFromFolder);
		}
		catch (\Throwable $oException)
		{
			\SnappyMail\Log::warning('IMAP', "FolderHash({$sFromFolder}) Exception: {$oException->getMessage()}");
		}

		return $this->DefaultResponse(__FUNCTION__, $sHash ? array($sFromFolder, $sHash) : array($sFromFolder));
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoMessageCopy() : array
	{
		$this->initMailClientConnection();

		try
		{
			$this->MailClient()->MessageCopy(
				$this->GetActionParam('FromFolder', ''),
				$this->GetActionParam('ToFolder', ''),
				new SequenceSet(\explode(',', (string) $this->GetActionParam('Uids', '')))
			);
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::CantCopyMessage, $oException);
		}

		return $this->TrueResponse(__FUNCTION__);
	}

	public function DoMessageUploadAttachments() : array
	{
		$oAccount = $this->initMailClientConnection();

		$mResult = false;
		$self = $this;

		try
		{
			$aAttachments = $this->GetActionParam('Attachments', array());
			if (\is_array($aAttachments) && \count($aAttachments))
			{
				$mResult = array();
				foreach ($aAttachments as $sAttachment)
				{
					if ($aValues = \RainLoop\Utils::DecodeKeyValuesQ($sAttachment))
					{
						$sFolder = isset($aValues['Folder']) ? (string) $aValues['Folder'] : '';
						$iUid = isset($aValues['Uid']) ? (int) $aValues['Uid'] : 0;
						$sMimeIndex = isset($aValues['MimeIndex']) ? (string) $aValues['MimeIndex'] : '';

						$sTempName = \md5($sAttachment);
						if (!$this->FilesProvider()->FileExists($oAccount, $sTempName))
						{
							$this->MailClient()->MessageMimeStream(
								function($rResource, $sContentType, $sFileName, $sMimeIndex = '') use ($oAccount, &$mResult, $sTempName, $sAttachment, $self) {
									if (is_resource($rResource))
									{
										$sContentType = (empty($sFileName)) ? 'text/plain' : \MailSo\Base\Utils::MimeContentType($sFileName);
										$sFileName = $self->MainClearFileName($sFileName, $sContentType, $sMimeIndex);

										if ($self->FilesProvider()->PutFile($oAccount, $sTempName, $rResource))
										{
											$mResult[$sTempName] = $sAttachment;
										}
									}
								}, $sFolder, $iUid, $sMimeIndex);
						}
						else
						{
							$mResult[$sTempName] = $sAttachment;
						}
					}
				}
			}
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::MailServerError, $oException);
		}

		return $this->DefaultResponse(__FUNCTION__, $mResult);
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc3156#section-5
	 */
	public function DoMessagePgpVerify() : array
	{
		$sFolderName = $this->GetActionParam('Folder', '');
		$iUid = (int) $this->GetActionParam('Uid', 0);
		$sBodyPart = $this->GetActionParam('BodyPart', '');
		$sSigPart = $this->GetActionParam('SigPart', '');
		if ($sBodyPart) {
			$result = [
				'text' => \preg_replace('/\\r?\\n/su', "\r\n", $sBodyPart),
				'signature' => $this->GetActionParam('SigPart', '')
			];
		} else {
			$sBodyPartId = $this->GetActionParam('BodyPartId', '');
			$sSigPartId = $this->GetActionParam('SigPartId', '');
//			$sMicAlg = $this->GetActionParam('MicAlg', '');

			$oAccount = $this->initMailClientConnection();

			$oImapClient = $this->MailClient()->ImapClient();
			$oImapClient->FolderExamine($sFolderName);

			$aParts = [
				FetchType::BODY_PEEK.'['.$sBodyPartId.']',
				// An empty section specification refers to the entire message, including the header.
				// But Dovecot does not return it with BODY.PEEK[1], so we also use BODY.PEEK[1.MIME].
				FetchType::BODY_PEEK.'['.$sBodyPartId.'.MIME]'
			];
			if ($sSigPartId) {
				$aParts[] = FetchType::BODY_PEEK.'['.$sSigPartId.']';
			}

			$oFetchResponse = $oImapClient->Fetch($aParts, $iUid, true)[0];

			$sBodyMime = $oFetchResponse->GetFetchValue(FetchType::BODY.'['.$sBodyPartId.'.MIME]');
			if ($sSigPartId) {
				$result = [
					'text' => \preg_replace('/\\r?\\n/su', "\r\n",
						$sBodyMime . $oFetchResponse->GetFetchValue(FetchType::BODY.'['.$sBodyPartId.']')
					),
					'signature' => preg_replace('/[^\x00-\x7F]/', '',
						$oFetchResponse->GetFetchValue(FetchType::BODY.'['.$sSigPartId.']')
					)
				];
			} else {
				// clearsigned text
				$result = [
					'text' => $oFetchResponse->GetFetchValue(FetchType::BODY.'['.$sBodyPartId.']'),
					'signature' => ''
				];
				$decode = (new \MailSo\Mime\HeaderCollection($sBodyMime))->ValueByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING);
				if ('base64' === $decode) {
					$result['text'] = \base64_decode($result['text']);
				} else if ('quoted-printable' === $decode) {
					$result['text'] = \quoted_printable_decode($result['text']);
				}
			}
		}

		if ($this->GetActionParam('GnuPG', 1)) {
			$GPG = $this->GnuPG();
			if ($GPG) {
				$info = $this->GnuPG()->verify($result['text'], $result['signature']);
//				$info = $this->GnuPG()->verifyStream($fp, $result['signature']);
				if (empty($info[0])) {
					$result = false;
				} else {
					$info = $info[0];

					/**
					* https://code.woboq.org/qt5/include/gpg-error.h.html
					* status:
						0 = GPG_ERR_NO_ERROR
						9 = GPG_ERR_NO_PUBKEY
						117440513 = General error
						117440520 = Bad signature
					*/

					$summary = [
						GNUPG_SIGSUM_VALID => 'The signature is fully valid.',
						GNUPG_SIGSUM_GREEN => 'The signature is good but one might want to display some extra information. Check the other bits.',
						GNUPG_SIGSUM_RED => 'The signature is bad. It might be useful to check other bits and display more information, i.e. a revoked certificate might not render a signature invalid when the message was received prior to the cause for the revocation.',
						GNUPG_SIGSUM_KEY_REVOKED => 'The key or at least one certificate has been revoked.',
						GNUPG_SIGSUM_KEY_EXPIRED => 'The key or one of the certificates has expired. It is probably a good idea to display the date of the expiration.',
						GNUPG_SIGSUM_SIG_EXPIRED => 'The signature has expired.',
						GNUPG_SIGSUM_KEY_MISSING => 'Can’t verify due to a missing key or certificate.',
						GNUPG_SIGSUM_CRL_MISSING => 'The CRL (or an equivalent mechanism) is not available.',
						GNUPG_SIGSUM_CRL_TOO_OLD => 'Available CRL is too old.',
						GNUPG_SIGSUM_BAD_POLICY => 'A policy requirement was not met.',
						GNUPG_SIGSUM_SYS_ERROR => 'A system error occurred.',
//						GNUPG_SIGSUM_TOFU_CONFLICT = 'A TOFU conflict was detected.',
					];

					// Verified, so no need to return $result['text'] and $result['signature']
					$result = [
						'fingerprint' => $info['fingerprint'],
						'validity' => $info['validity'],
						'status' => $info['status'],
						'summary' => $info['summary'],
						'message' => \implode("\n", \array_filter($summary, function($k) use ($info) {
							return $info['summary'] & $k;
						}, ARRAY_FILTER_USE_KEY))
					];
				}
			} else {
				$result = false;
			}
		}

		return $this->DefaultResponse(__FUNCTION__, $result);
	}

	/**
	 * @throws \RainLoop\Exceptions\ClientException
	 * @throws \MailSo\Net\Exceptions\ConnectionException
	 */
	private function smtpSendMessage(Account $oAccount, \MailSo\Mime\Message $oMessage,
		/*resource*/ &$rMessageStream, int &$iMessageStreamSize, bool $bDsn = false, bool $bAddHiddenRcpt = true)
	{
		$oRcpt = $oMessage->GetRcpt();
		if ($oRcpt && 0 < $oRcpt->Count())
		{
			$this->Plugins()->RunHook('filter.smtp-message-stream',
				array($oAccount, &$rMessageStream, &$iMessageStreamSize));

			$this->Plugins()->RunHook('filter.message-rcpt', array($oAccount, $oRcpt));

			try
			{
				$oFrom = $oMessage->GetFrom();
				$sFrom = $oFrom instanceof \MailSo\Mime\Email ? $oFrom->GetEmail() : '';
				$sFrom = empty($sFrom) ? $oAccount->Email() : $sFrom;

				$this->Plugins()->RunHook('filter.smtp-from', array($oAccount, $oMessage, &$sFrom));

				$aHiddenRcpt = array();
				if ($bAddHiddenRcpt)
				{
					$this->Plugins()->RunHook('filter.smtp-hidden-rcpt', array($oAccount, $oMessage, &$aHiddenRcpt));
				}

				$oSmtpClient = new \MailSo\Smtp\SmtpClient();
				$oSmtpClient->SetLogger($this->Logger());
				$oSmtpClient->SetTimeOuts(10, (int) \RainLoop\Api::Config()->Get('labs', 'smtp_timeout', 60));

				$bUsePhpMail = false;
				$oAccount->SmtpConnectAndLoginHelper($this->Plugins(), $oSmtpClient, $this->Config(), $bUsePhpMail);

				if ($bUsePhpMail)
				{
					if (\MailSo\Base\Utils::FunctionCallable('mail'))
					{
						$aToCollection = $oMessage->GetTo();
						if ($aToCollection && $oFrom)
						{
							$sRawBody = \stream_get_contents($rMessageStream);
							if (!empty($sRawBody))
							{
								$sMailTo = \trim($aToCollection->ToString(true));
								$sMailSubject = \trim($oMessage->GetSubject());
								$sMailSubject = 0 === \strlen($sMailSubject) ? '' : \MailSo\Base\Utils::EncodeUnencodedValue(
									\MailSo\Base\Enumerations\Encoding::BASE64_SHORT, $sMailSubject);

								$sMailHeaders = $sMailBody = '';
								list($sMailHeaders, $sMailBody) = \explode("\r\n\r\n", $sRawBody, 2);
								unset($sRawBody);

								if ($this->Config()->Get('labs', 'mail_func_clear_headers', true))
								{
									$sMailHeaders = \MailSo\Base\Utils::RemoveHeaderFromHeaders($sMailHeaders, array(
										\MailSo\Mime\Enumerations\Header::TO_,
										\MailSo\Mime\Enumerations\Header::SUBJECT
									));
								}

								$this->Logger()->WriteDump(array(
									$sMailTo, $sMailSubject, $sMailBody, $sMailHeaders
								), \LOG_DEBUG);

								$bR = $this->Config()->Get('labs', 'mail_func_additional_parameters', false) ?
									\mail($sMailTo, $sMailSubject, $sMailBody, $sMailHeaders, '-f'.$oFrom->GetEmail()) :
									\mail($sMailTo, $sMailSubject, $sMailBody, $sMailHeaders);

								if (!$bR)
								{
									throw new ClientException(Notifications::CantSendMessage);
								}
							}
						}
					}
					else
					{
						throw new ClientException(Notifications::CantSendMessage);
					}
				}
				else if ($oSmtpClient->IsConnected())
				{
					if (!empty($sFrom))
					{
						$oSmtpClient->MailFrom($sFrom, '', $bDsn);
					}

					foreach ($oRcpt as /* @var $oEmail \MailSo\Mime\Email */ $oEmail)
					{
						$oSmtpClient->Rcpt($oEmail->GetEmail(), $bDsn);
					}

					if ($bAddHiddenRcpt && \is_array($aHiddenRcpt) && \count($aHiddenRcpt))
					{
						foreach ($aHiddenRcpt as $sEmail)
						{
							if (\preg_match('/^[^@\s]+@[^@\s]+$/', $sEmail))
							{
								$oSmtpClient->Rcpt($sEmail);
							}
						}
					}

					$oSmtpClient->DataWithStream($rMessageStream);

					$oSmtpClient->Disconnect();
				}
			}
			catch (\MailSo\Net\Exceptions\ConnectionException $oException)
			{
				if ($this->Config()->Get('labs', 'smtp_show_server_errors'))
				{
					throw new ClientException(Notifications::ClientViewError, $oException);
				}
				else
				{
					throw new ClientException(Notifications::ConnectionError, $oException);
				}
			}
			catch (\MailSo\Smtp\Exceptions\LoginException $oException)
			{
				throw new ClientException(Notifications::AuthError, $oException);
			}
			catch (\Throwable $oException)
			{
				if ($this->Config()->Get('labs', 'smtp_show_server_errors'))
				{
					throw new ClientException(Notifications::ClientViewError, $oException);
				}
				else
				{
					throw $oException;
				}
			}
		}
		else
		{
			throw new ClientException(Notifications::InvalidRecipients);
		}
	}

	private function messageSetFlag(string $sMessageFlag, string $sResponseFunction, bool $bSkipUnsupportedFlag = false) : array
	{
		$this->initMailClientConnection();

		try
		{
			$this->MailClient()->MessageSetFlag(
				$this->GetActionParam('Folder', ''),
				new SequenceSet(\explode(',', (string) $this->GetActionParam('Uids', ''))),
				$sMessageFlag,
				!empty($this->GetActionParam('SetAction', '0')),
				$bSkipUnsupportedFlag
			);
		}
		catch (\Throwable $oException)
		{
			throw new ClientException(Notifications::MailServerError, $oException);
		}

		return $this->TrueResponse($sResponseFunction);
	}

	private function deleteMessageAttachments(Account $oAccount) : void
	{
		$aAttachments = $this->GetActionParam('Attachments', null);

		if (\is_array($aAttachments))
		{
			foreach (\array_keys($aAttachments) as $sTempName)
			{
				if ($this->FilesProvider()->FileExists($oAccount, $sTempName))
				{
					$this->FilesProvider()->Clear($oAccount, $sTempName);
				}
			}
		}
	}

	/**
	 * @return MailSo\Cache\CacheClient|null
	 */
	private function cacherForUids()
	{
		$oAccount = $this->getAccountFromToken(false);
		return ($this->Config()->Get('cache', 'enable', true) &&
			$this->Config()->Get('cache', 'server_uids', false)) ? $this->Cacher($oAccount) : null;
	}

	/**
	 * @return MailSo\Cache\CacheClient|null
	 */
	private function cacherForThreads()
	{
		$oAccount = $this->getAccountFromToken(false);
		return !!$this->Config()->Get('labs', 'use_imap_thread', false) ? $this->Cacher($oAccount) : null;
	}

	private function buildReadReceiptMessage(Account $oAccount) : \MailSo\Mime\Message
	{
		$sReadReceipt = $this->GetActionParam('ReadReceipt', '');
		$sSubject = $this->GetActionParam('subject', '');
		$sText = $this->GetActionParam('Text', '');

		$oIdentity = $this->GetIdentityByID($oAccount, '', true);

		if (empty($sReadReceipt) || empty($sSubject) || empty($sText) || !$oIdentity)
		{
			throw new ClientException(Notifications::UnknownError);
		}

		$oMessage = new \MailSo\Mime\Message();

		if ($this->Config()->Get('security', 'hide_x_mailer_header', true)) {
			$oMessage->DoesNotAddDefaultXMailer();
		} else {
			$oMessage->SetXMailer('SnappyMail/'.APP_VERSION);
		}

		$oMessage->SetFrom(new \MailSo\Mime\Email($oIdentity->Email(), $oIdentity->Name()));

		$oFrom = $oMessage->GetFrom();
		$oMessage->RegenerateMessageId($oFrom ? $oFrom->GetDomain() : '');

		$sReplyTo = $oIdentity->ReplyTo();
		if (!empty($sReplyTo))
		{
			$oReplyTo = new \MailSo\Mime\EmailCollection($sReplyTo);
			if ($oReplyTo && $oReplyTo->Count())
			{
				$oMessage->SetReplyTo($oReplyTo);
			}
		}

		$oMessage->SetSubject($sSubject);

		$oToEmails = new \MailSo\Mime\EmailCollection($sReadReceipt);
		if ($oToEmails && $oToEmails->Count())
		{
			$oMessage->SetTo($oToEmails);
		}

		$this->Plugins()->RunHook('filter.read-receipt-message-plain', array($oAccount, $oMessage, &$sText));

		$oPart = new MimePart;
		$oPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'text/plain; charset="utf-8"');
		$oPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, 'quoted-printable');
		$oPart->Body = \MailSo\Base\StreamWrappers\Binary::CreateStream(
			\MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString(\preg_replace('/\\r?\\n/su', "\r\n", \trim($sText))),
			'convert.quoted-printable-encode'
		);
		$oMessage->SubParts->append($oPart);

		$this->Plugins()->RunHook('filter.build-read-receipt-message', array($oMessage, $oAccount));

		return $oMessage;
	}

	private function buildMessage(Account $oAccount, bool $bWithDraftInfo = true) : \MailSo\Mime\Message
	{
		$oMessage = new \MailSo\Mime\Message();

		if ($this->Config()->Get('security', 'hide_x_mailer_header', true)) {
			$oMessage->DoesNotAddDefaultXMailer();
		} else {
			$oMessage->SetXMailer('SnappyMail/'.APP_VERSION);
		}

		$sFrom = $this->GetActionParam('From', '');
		$oMessage->SetFrom(\MailSo\Mime\Email::Parse($sFrom));
/*
		$oFromIdentity = $this->GetIdentityByID($oAccount, $this->GetActionParam('IdentityID', ''));
		if ($oFromIdentity)
		{
			$oMessage->SetFrom(new \MailSo\Mime\Email(
				$oFromIdentity->Email(), $oFromIdentity->Name()));
			if ($oAccount->Domain()->OutSetSender()) {
				$oMessage->SetSender(\MailSo\Mime\Email::Parse($oAccount->Email()));
			}
		}
		else
		{
			$oMessage->SetFrom(\MailSo\Mime\Email::Parse($oAccount->Email()));
		}
*/
		$oFrom = $oMessage->GetFrom();
		$oMessage->RegenerateMessageId($oFrom ? $oFrom->GetDomain() : '');

		$oReplyTo = new \MailSo\Mime\EmailCollection($this->GetActionParam('ReplyTo', ''));
		if ($oReplyTo->count()) {
			$oMessage->SetReplyTo($oReplyTo);
		}

		if (!empty($this->GetActionParam('ReadReceiptRequest', 0))) {
			// Read Receipts Reference Main Account Email, Not Identities #147
//			$oMessage->SetReadReceipt(($oFromIdentity ?: $oAccount)->Email());
			$oMessage->SetReadReceipt($oFrom->GetEmail());
		}

		if (!empty($this->GetActionParam('MarkAsImportant', 0))) {
			$oMessage->SetPriority(\MailSo\Mime\Enumerations\MessagePriority::HIGH);
		}

		$oMessage->SetSubject($this->GetActionParam('subject', ''));

		$oToEmails = new \MailSo\Mime\EmailCollection($this->GetActionParam('To', ''));
		if ($oToEmails->count()) {
			$oMessage->SetTo($oToEmails);
		}

		$oCcEmails = new \MailSo\Mime\EmailCollection($this->GetActionParam('Cc', ''));
		if ($oCcEmails->count()) {
			$oMessage->SetCc($oCcEmails);
		}

		$oBccEmails = new \MailSo\Mime\EmailCollection($this->GetActionParam('Bcc', ''));
		if ($oBccEmails->count()) {
			$oMessage->SetBcc($oBccEmails);
		}

		$aDraftInfo = $this->GetActionParam('DraftInfo', null);
		if ($bWithDraftInfo && \is_array($aDraftInfo) && !empty($aDraftInfo[0]) && !empty($aDraftInfo[1]) && !empty($aDraftInfo[2]))
		{
			$oMessage->SetDraftInfo($aDraftInfo[0], $aDraftInfo[1], $aDraftInfo[2]);
		}

		$sInReplyTo = $this->GetActionParam('InReplyTo', '');
		if (\strlen($sInReplyTo)) {
			$oMessage->SetInReplyTo($sInReplyTo);
		}

		$sReferences = $this->GetActionParam('References', '');
		if (\strlen($sReferences)) {
			$oMessage->SetReferences($sReferences);
		}

		$aFoundCids = array();
		$aFoundDataURL = array();
		$aFoundContentLocationUrls = array();
		$oPart;

		if ($sSigned = $this->GetActionParam('Signed', '')) {
			$aSigned = \explode("\r\n\r\n", $sSigned, 2);
//			$sBoundary = \preg_replace('/^.+boundary="([^"]+)".+$/Dsi', '$1', $aSigned[0]);
			$sBoundary = $this->GetActionParam('Boundary', '');

			$oPart = new MimePart;
			$oPart->Headers->AddByName(
				\MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
				'multipart/signed; micalg="pgp-sha256"; protocol="application/pgp-signature"; boundary="'.$sBoundary.'"'
			);
			$oPart->Body = $aSigned[1];
			$oMessage->SubParts->append($oPart);
			$oMessage->SubParts->SetBoundary($sBoundary);

			unset($oAlternativePart);
			unset($sSigned);

		} else if ($sEncrypted = $this->GetActionParam('Encrypted', '')) {
			$oPart = new MimePart;
			$oPart->Headers->AddByName(
				\MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
				'multipart/encrypted; protocol="application/pgp-encrypted"'
			);
			$oMessage->SubParts->append($oPart);

			$oAlternativePart = new MimePart;
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'application/pgp-encrypted');
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_DISPOSITION, 'attachment');
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, '7Bit');
			$oAlternativePart->Body = \MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString('Version: 1');
			$oPart->SubParts->append($oAlternativePart);

			$oAlternativePart = new MimePart;
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'application/octet-stream');
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_DISPOSITION, 'inline; filename="msg.asc"');
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, '7Bit');
			$oAlternativePart->Body = \MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString(\preg_replace('/\\r?\\n/su', "\r\n", \trim($sEncrypted)));
			$oPart->SubParts->append($oAlternativePart);

			unset($oAlternativePart);
			unset($sEncrypted);

		} else {
			if ($sHtml = $this->GetActionParam('Html', '')) {
				$oPart = new MimePart;
				$oPart->Headers->AddByName(
					\MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
					\MailSo\Mime\Enumerations\MimeType::MULTIPART_ALTERNATIVE
				);
				$oMessage->SubParts->append($oPart);

				$sHtml = \MailSo\Base\HtmlUtils::BuildHtml($sHtml, $aFoundCids, $aFoundDataURL, $aFoundContentLocationUrls);
				$this->Plugins()->RunHook('filter.message-html', array($oAccount, $oMessage, &$sHtml));

				// First add plain
				$sPlain = $this->GetActionParam('Text', '') ?: \MailSo\Base\HtmlUtils::ConvertHtmlToPlain($sHtml);
				$this->Plugins()->RunHook('filter.message-plain', array($oAccount, $oMessage, &$sPlain));
				$oAlternativePart = new MimePart;
				$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'text/plain; charset=utf-8');
				$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, 'quoted-printable');
				$oAlternativePart->Body = \MailSo\Base\StreamWrappers\Binary::CreateStream(
					\MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString(\preg_replace('/\\r?\\n/su', "\r\n", \trim($sPlain))),
					'convert.quoted-printable-encode'
				);
				$oPart->SubParts->append($oAlternativePart);
				unset($sPlain);

				// Now add HTML
				$oAlternativePart = new MimePart;
				$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'text/html; charset=utf-8');
				$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, 'quoted-printable');
				$oAlternativePart->Body = \MailSo\Base\StreamWrappers\Binary::CreateStream(
					\MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString(\preg_replace('/\\r?\\n/su', "\r\n", \trim($sHtml))),
					'convert.quoted-printable-encode'
				);
				$oPart->SubParts->append($oAlternativePart);

				unset($oAlternativePart);
				unset($sHtml);

			} else {
				$sPlain = $this->GetActionParam('Text', '');
				if ($sSignature = $this->GetActionParam('Signature', null)) {
					$oPart = new MimePart;
					$oPart->Headers->AddByName(
						\MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
						'multipart/signed; micalg="pgp-sha256"; protocol="application/pgp-signature"'
					);
					$oMessage->SubParts->append($oPart);

					$oAlternativePart = new MimePart;
					$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'text/plain; charset="utf-8"; protected-headers="v1"');
					$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, 'base64');
					$oAlternativePart->Body = \MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString(\preg_replace('/\\r?\\n/su', "\r\n", \trim($sPlain)));
					$oPart->SubParts->append($oAlternativePart);

					$oAlternativePart = new MimePart;
					$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'application/pgp-signature; name="signature.asc"');
					$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, '7Bit');
					$oAlternativePart->Body = \MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString(\preg_replace('/\\r?\\n/su', "\r\n", \trim($sSignature)));
					$oPart->SubParts->append($oAlternativePart);
				} else {
					$this->Plugins()->RunHook('filter.message-plain', array($oAccount, $oMessage, &$sPlain));
					$oAlternativePart = new MimePart;
					$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'text/plain; charset="utf-8"');
					$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, 'quoted-printable');
					$oAlternativePart->Body = \MailSo\Base\StreamWrappers\Binary::CreateStream(
						\MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString(\preg_replace('/\\r?\\n/su', "\r\n", \trim($sPlain))),
						'convert.quoted-printable-encode'
					);
					$oMessage->SubParts->append($oAlternativePart);
				}
				unset($oAlternativePart);
				unset($sSignature);
				unset($sPlain);
			}
		}
		unset($oPart);

		$aAttachments = $this->GetActionParam('Attachments', null);
		if (\is_array($aAttachments))
		{
			foreach ($aAttachments as $sTempName => $aData)
			{
				$sFileName = (string) $aData[0];
				$bIsInline = (bool) $aData[1];
				$sCID = (string) $aData[2];
				$sContentLocation = isset($aData[3]) ? (string) $aData[3] : '';

				$rResource = $this->FilesProvider()->GetFile($oAccount, $sTempName);
				if (\is_resource($rResource))
				{
					$iFileSize = $this->FilesProvider()->FileSize($oAccount, $sTempName);

					$oMessage->Attachments()->append(
						new \MailSo\Mime\Attachment($rResource, $sFileName, $iFileSize, $bIsInline,
							\in_array(trim(trim($sCID), '<>'), $aFoundCids),
							$sCID, array(), $sContentLocation
						)
					);
				}
			}
		}

		foreach ($aFoundDataURL as $sCidHash => $sDataUrlString)
		{
			$aMatch = array();
			$sCID = '<'.$sCidHash.'>';
			if (\preg_match('/^data:(image\/[a-zA-Z0-9]+);base64,(.+)$/i', $sDataUrlString, $aMatch) &&
				!empty($aMatch[1]) && !empty($aMatch[2]))
			{
				$sRaw = \MailSo\Base\Utils::Base64Decode($aMatch[2]);
				$iFileSize = \strlen($sRaw);
				if (0 < $iFileSize)
				{
					$sFileName = \preg_replace('/[^a-z0-9]+/i', '.', $aMatch[1]);
					$rResource = \MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString($sRaw);

					$sRaw = '';
					unset($sRaw);
					unset($aMatch);

					$oMessage->Attachments()->append(
						new \MailSo\Mime\Attachment($rResource, $sFileName, $iFileSize, true, true, $sCID)
					);
				}
			}
		}

		$sFingerprint = $this->GetActionParam('SignFingerprint', '');
		$sPassphrase = $this->GetActionParam('SignPassphrase', '');
		if ($sFingerprint) {
			$GPG = $this->GnuPG();
			$oBody = $oMessage->GetRootPart();
			$resource = $oBody->ToStream();
			$oBody->Body = null;
			$oBody->SubParts->Clear();
			$oMessage->SubParts->Clear();
			$oMessage->Attachments()->Clear();

			\MailSo\Base\StreamFilters\LineEndings::appendTo($resource);
			$fp = \fopen('php://temp', 'r+b');
			\stream_copy_to_stream($resource, $fp);
			$GPG->addSignKey($sFingerprint, $sPassphrase);
			$GPG->setsignmode(GNUPG_SIG_MODE_DETACH);
			$sSignature = $GPG->signStream($fp);
			if (!$sSignature) {
				throw new \Exception('GnuPG sign() failed');
			}

			$oPart = new MimePart;
			$oPart->Headers->AddByName(
				\MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
				'multipart/signed; micalg="pgp-sha256"; protocol="application/pgp-signature"'
			);
			$oMessage->SubParts->append($oPart);

			\rewind($fp);
			$oBody->Raw = $fp;
			$oPart->SubParts->append($oBody);

			$oSignaturePart = new MimePart;
			$oSignaturePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'application/pgp-signature; name="signature.asc"');
			$oSignaturePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, '7Bit');
			$oSignaturePart->Body = $sSignature;
			$oPart->SubParts->append($oSignaturePart);
		}

		$aFingerprints = \json_decode($this->GetActionParam('EncryptFingerprints', ''), true);
		if ($aFingerprints) {
			$GPG = $this->GnuPG();
			$oBody = $oMessage->GetRootPart();
			$fp = \fopen('php://temp', 'r+b');
			$resource = $oBody->ToStream();
			\stream_copy_to_stream($resource, $fp);
			foreach ($aFingerprints as $sFingerprint) {
				$GPG->addEncryptKey($sFingerprint);
			}
			$sEncrypted = $GPG->encryptStream($fp);

			$oMessage->SubParts->Clear();
			$oMessage->Attachments()->Clear();

			$oPart = new MimePart;
			$oPart->Headers->AddByName(
				\MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
				'multipart/encrypted; protocol="application/pgp-encrypted"'
			);
			$oMessage->SubParts->append($oPart);

			$oAlternativePart = new MimePart;
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'application/pgp-encrypted');
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_DISPOSITION, 'attachment');
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, '7Bit');
			$oAlternativePart->Body = \MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString('Version: 1');
			$oPart->SubParts->append($oAlternativePart);

			$oAlternativePart = new MimePart;
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'application/octet-stream');
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_DISPOSITION, 'inline; filename="msg.asc"');
			$oAlternativePart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, '7Bit');
			$oAlternativePart->Body = \MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString($sEncrypted);
			$oPart->SubParts->append($oAlternativePart);
		}

		$this->Plugins()->RunHook('filter.build-message', array($oMessage));

		return $oMessage;
	}
}
