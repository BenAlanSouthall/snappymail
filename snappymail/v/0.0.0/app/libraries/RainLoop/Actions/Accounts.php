<?php

namespace RainLoop\Actions;

use RainLoop\Enumerations\Capa;
use RainLoop\Exceptions\ClientException;
use RainLoop\Model\Account;
use RainLoop\Model\MainAccount;
use RainLoop\Model\AdditionalAccount;
use RainLoop\Model\Identity;
use RainLoop\Notifications;
use RainLoop\Providers\Identities;
use RainLoop\Providers\Storage\Enumerations\StorageType;
use RainLoop\Utils;

trait Accounts
{
	/**
	 * @var RainLoop\Providers\Identities
	 */
	private $oIdentitiesProvider;

	protected function GetMainEmail(Account $oAccount)
	{
		return ($oAccount instanceof AdditionalAccount ? $this->getMainAccountFromToken() : $oAccount)->Email();
	}

	public function IdentitiesProvider(): Identities
	{
		if (null === $this->oIdentitiesProvider) {
			$this->oIdentitiesProvider = new Identities($this->fabrica('identities'));
		}

		return $this->oIdentitiesProvider;
	}

	public function GetAccounts(MainAccount $oAccount): array
	{
		if ($this->GetCapa(Capa::ADDITIONAL_ACCOUNTS)) {
			$sAccounts = $this->StorageProvider()->Get($oAccount,
				StorageType::CONFIG,
				'additionalaccounts'
			);
			$aAccounts = $sAccounts ? \json_decode($sAccounts, true) : \SnappyMail\Upgrade::ConvertInsecureAccounts($this, $oAccount);
			if ($aAccounts && \is_array($aAccounts)) {
				return $aAccounts;
			}
		}

		return array();
	}

	public function SetAccounts(MainAccount $oAccount, array $aAccounts = array()): void
	{
		$sParentEmail = $oAccount->Email();
		if ($aAccounts) {
			$this->StorageProvider()->Put(
				$oAccount,
				StorageType::CONFIG,
				'additionalaccounts',
				\json_encode($aAccounts)
			);
		} else {
			$this->StorageProvider()->Clear(
				$oAccount,
				StorageType::CONFIG,
				'additionalaccounts'
			);
		}
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoAccountSetup(): array
	{
		$oMainAccount = $this->getMainAccountFromToken();

		if (!$this->GetCapa(Capa::ADDITIONAL_ACCOUNTS)) {
			return $this->FalseResponse(__FUNCTION__);
		}

		$aAccounts = $this->GetAccounts($oMainAccount);

		$sEmail = \trim($this->GetActionParam('Email', ''));
		$sPassword = $this->GetActionParam('Password', '');
		$bNew = '1' === (string)$this->GetActionParam('New', '1');

		$sEmail = \MailSo\Base\Utils::IdnToAscii($sEmail, true);
		if ($bNew && ($oMainAccount->Email() === $sEmail || isset($aAccounts[$sEmail]))) {
			throw new ClientException(Notifications::AccountAlreadyExists);
		} else if (!$bNew && !isset($aAccounts[$sEmail])) {
			throw new ClientException(Notifications::AccountDoesNotExist);
		}

		$oNewAccount = $this->LoginProcess($sEmail, $sPassword, false, false);

		$aAccounts[$oNewAccount->Email()] = $oNewAccount->asTokenArray($oMainAccount);
		$this->SetAccounts($oMainAccount, $aAccounts);

		return $this->TrueResponse(__FUNCTION__);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoAccountDelete(): array
	{
		$oMainAccount = $this->getMainAccountFromToken();

		if (!$this->GetCapa(Capa::ADDITIONAL_ACCOUNTS)) {
			return $this->FalseResponse(__FUNCTION__);
		}

		$sEmailToDelete = \trim($this->GetActionParam('EmailToDelete', ''));
		$sEmailToDelete = \MailSo\Base\Utils::IdnToAscii($sEmailToDelete, true);

		$aAccounts = $this->GetAccounts($oMainAccount);

		if (\strlen($sEmailToDelete) && isset($aAccounts[$sEmailToDelete])) {
			$bReload = false;
			$oAccount = $this->getAccountFromToken();
			if ($oAccount instanceof AdditionalAccount && $oAccount->Email() === $sEmailToDelete) {
				Utils::ClearCookie(self::AUTH_ADDITIONAL_TOKEN_KEY);
				$bReload = true;
			}

			unset($aAccounts[$sEmailToDelete]);
			$this->SetAccounts($oMainAccount, $aAccounts);

			return $this->TrueResponse(__FUNCTION__, array('Reload' => $bReload));
		}

		return $this->FalseResponse(__FUNCTION__);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoAccountSwitch(): array
	{
		if ($this->switchAccount(\trim($this->GetActionParam('Email', '')))) {
			$oAccount = $this->getAccountFromToken();
			$aResult['Email'] = $oAccount->Email();
			$aResult['IncLogin'] = $oAccount->IncLogin();
			$aResult['OutLogin'] = $oAccount->OutLogin();
			$aResult['AccountHash'] = $oAccount->Hash();
			$aResult['MainEmail'] = ($oAccount instanceof AdditionalAccount)
				? $oAccount->ParentEmail() : '';
			$aResult['ContactsIsAllowed'] = $this->AddressBookProvider($oAccount)->IsActive();
			$oSettingsLocal = $this->SettingsProvider(true)->Load($oAccount);
			if ($oSettingsLocal instanceof \RainLoop\Settings) {
				$oConfig = $this->Config();
				$aResult['SentFolder'] = (string) $oSettingsLocal->GetConf('SentFolder', '');
				$aResult['DraftsFolder'] = (string) $oSettingsLocal->GetConf('DraftFolder', '');
				$aResult['SpamFolder'] = (string) $oSettingsLocal->GetConf('SpamFolder', '');
				$aResult['TrashFolder'] = (string) $oSettingsLocal->GetConf('TrashFolder', '');
				$aResult['ArchiveFolder'] = (string) $oSettingsLocal->GetConf('ArchiveFolder', '');
				$aResult['HideUnsubscribed'] = (bool) $oSettingsLocal->GetConf('HideUnsubscribed', false);
				$aResult['UseThreads'] = (bool) $oSettingsLocal->GetConf('UseThreads', $oConfig->Get('defaults', 'mail_use_threads', false));
				$aResult['ReplySameFolder'] = (bool) $oSettingsLocal->GetConf('ReplySameFolder', $oConfig->Get('defaults', 'mail_reply_same_folder', false));
				$aResult['HideDeleted'] = (bool) $oSettingsLocal->GetConf('HideDeleted', true);
				$aResult['UnhideKolabFolders'] = (bool) $oSettingsLocal->GetConf('UnhideKolabFolders', false);
			}
//			$this->Plugins()->InitAppData($bAdmin, $aResult, $oAccount);

			return $this->DefaultResponse(__FUNCTION__, $aResult);
		}
		return $this->FalseResponse(__FUNCTION__);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoIdentityUpdate(): array
	{
		$oAccount = $this->getAccountFromToken();

		$oIdentity = new Identity();
		if (!$oIdentity->FromJSON($this->GetActionParams(), true)) {
			throw new ClientException(Notifications::InvalidInputArgument);
		}

		$this->IdentitiesProvider()->UpdateIdentity($oAccount, $oIdentity);
		return $this->DefaultResponse(__FUNCTION__, true);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoIdentityDelete(): array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::IDENTITIES)) {
			return $this->FalseResponse(__FUNCTION__);
		}

		$sId = \trim($this->GetActionParam('IdToDelete', ''));
		if (empty($sId)) {
			throw new ClientException(Notifications::UnknownError);
		}

		$this->IdentitiesProvider()->DeleteIdentity($oAccount, $sId);
		return $this->DefaultResponse(__FUNCTION__, true);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoAccountsAndIdentitiesSortOrder(): array
	{
		$aAccounts = $this->GetActionParam('Accounts', null);
		$aIdentities = $this->GetActionParam('Identities', null);

		if (!\is_array($aAccounts) && !\is_array($aIdentities)) {
			return $this->FalseResponse(__FUNCTION__);
		}

		if (\is_array($aAccounts) && 1 < \count($aAccounts)) {
			$oAccount = $this->getMainAccountFromToken();
			$aAccounts = \array_filter(\array_merge(
				\array_fill_keys($aAccounts, null),
				$this->GetAccounts($oAccount)
			));
			$this->SetAccounts($oAccount, $aAccounts);
		}

		return $this->DefaultResponse(__FUNCTION__, $this->LocalStorageProvider()->Put(
			$this->getAccountFromToken(),
			StorageType::CONFIG,
			'identities_order',
			\json_encode(array(
				'Identities' => \is_array($aIdentities) ? $aIdentities : array()
			))
		));
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoAccountsAndIdentities(): array
	{
		return $this->DefaultResponse(__FUNCTION__, array(
			'Accounts' => \array_map(
				'MailSo\\Base\\Utils::IdnToUtf8',
				\array_keys($this->GetAccounts($this->getMainAccountFromToken()))
			),
			'Identities' => $this->GetIdentities($this->getAccountFromToken())
		));
	}

	/**
	 * @return Identity[]
	 */
	public function GetIdentities(Account $oAccount): array
	{
		// A custom name for a single identity is also stored in this system
		$allowMultipleIdentities = $this->GetCapa(Capa::IDENTITIES);

		// Get all identities
		$identities = $this->IdentitiesProvider()->GetIdentities($oAccount, $allowMultipleIdentities);

		// Sort identities
		$orderString = $this->LocalStorageProvider()->Get($oAccount, StorageType::CONFIG, 'identities_order');
		$old = false;
		if (!$orderString) {
			$orderString = $this->StorageProvider()->Get($oAccount, StorageType::CONFIG, 'accounts_identities_order');
			$old = !!$orderString;
		}

		$order = \json_decode($orderString, true) ?? [];
		if (isset($order['Identities']) && \is_array($order['Identities']) && 1 < \count($order['Identities'])) {
			$list = \array_map(function ($item) {
				return ('' === $item) ? '---' : $item;
			}, $order['Identities']);

			\usort($identities, function ($a, $b) use ($list) {
				return \array_search($a->Id(true), $list) < \array_search($b->Id(true), $list) ? -1 : 1;
			});
		}

		if ($old) {
			$this->LocalStorageProvider()->Put(
				$oAccount,
				StorageType::CONFIG,
				'identities_order',
				\json_encode(array('Identities' => empty($order['Identities']) ? [] : $order['Identities']))
			);
			$this->StorageProvider()->Clear($oAccount, StorageType::CONFIG, 'accounts_identities_order');
		}

		return $identities;
	}

	public function GetIdentityByID(Account $oAccount, string $sID, bool $bFirstOnEmpty = false): ?Identity
	{
		$aIdentities = $this->GetIdentities($oAccount);

		foreach ($aIdentities as $oIdentity) {
			if ($oIdentity && $sID === $oIdentity->Id()) {
				return $oIdentity;
			}
		}

		return $bFirstOnEmpty && isset($aIdentities[0]) ? $aIdentities[0] : null;
	}

}
