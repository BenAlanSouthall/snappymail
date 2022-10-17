<?php

namespace RainLoop;

use RainLoop\Enumerations\PluginPropertyType;
use RainLoop\Exceptions\ClientException;

class ActionsAdmin extends Actions
{

	public function DoAdminClearCache() : array
	{
		$this->Cacher()->GC(0);
		if (\is_dir(APP_PRIVATE_DATA . 'cache')) {
			\MailSo\Base\Utils::RecRmDir(APP_PRIVATE_DATA.'cache');
		}
		return $this->TrueResponse(__FUNCTION__);
	}

	public function DoAdminSettingsGet() : array
	{
		$aConfig = $this->Config()->jsonSerialize();
		unset($aConfig['version']);
		return $this->DefaultResponse(__FUNCTION__, $aConfig);
	}

	public function DoAdminSettingsSet() : array
	{
		$oConfig = $this->Config();
		foreach ($this->GetActionParam('config', []) as $sSection => $aItems) {
			foreach ($aItems as $sKey => $mValue) {
				$oConfig->Set($sSection, $sKey, $mValue);
			}
		}
		return $this->DefaultResponse(__FUNCTION__, $oConfig->Save());
	}

	public function DoAdminSettingsUpdate() : array
	{
//		sleep(3);
//		return $this->DefaultResponse(__FUNCTION__, false);

		$this->IsAdminLoggined();

		$oConfig = $this->Config();

		$self = $this;

		$this->setConfigFromParams($oConfig, 'Language', 'webmail', 'language', 'string', function ($sLanguage) use ($self) {
			return $self->ValidateLanguage($sLanguage, '', false);
		});

		$this->setConfigFromParams($oConfig, 'LanguageAdmin', 'webmail', 'language_admin', 'string', function ($sLanguage) use ($self) {
			return $self->ValidateLanguage($sLanguage, '', true);
		});

		$this->setConfigFromParams($oConfig, 'Theme', 'webmail', 'theme', 'string', function ($sTheme) use ($self) {
			return $self->ValidateTheme($sTheme);
		});

		$this->setConfigFromParams($oConfig, 'VerifySslCertificate', 'ssl', 'verify_certificate', 'bool');
		$this->setConfigFromParams($oConfig, 'AllowSelfSigned', 'ssl', 'allow_self_signed', 'bool');

		$this->setConfigFromParams($oConfig, 'UseLocalProxyForExternalImages', 'labs', 'use_local_proxy_for_external_images', 'bool');

		$this->setConfigFromParams($oConfig, 'AllowLanguagesOnSettings', 'webmail', 'allow_languages_on_settings', 'bool');
		$this->setConfigFromParams($oConfig, 'AllowLanguagesOnLogin', 'login', 'allow_languages_on_login', 'bool');
		$this->setConfigFromParams($oConfig, 'AttachmentLimit', 'webmail', 'attachment_size_limit', 'int');

		$this->setConfigFromParams($oConfig, 'LoginDefaultDomain', 'login', 'default_domain', 'string');

		$this->setConfigFromParams($oConfig, 'ContactsEnable', 'contacts', 'enable', 'bool');
		$this->setConfigFromParams($oConfig, 'ContactsSync', 'contacts', 'allow_sync', 'bool');
		$this->setConfigFromParams($oConfig, 'ContactsPdoDsn', 'contacts', 'pdo_dsn', 'string');
		$this->setConfigFromParams($oConfig, 'ContactsPdoUser', 'contacts', 'pdo_user', 'string');
		$this->setConfigFromParams($oConfig, 'ContactsPdoPassword', 'contacts', 'pdo_password', 'dummy');

		$this->setConfigFromParams($oConfig, 'ContactsPdoType', 'contacts', 'type', 'string', function ($sType) use ($self) {
			return \RainLoop\Providers\AddressBook\PdoAddressBook::validPdoType($sType);
		});

		$this->setConfigFromParams($oConfig, 'CapaAdditionalAccounts', 'webmail', 'allow_additional_accounts', 'bool');
		$this->setConfigFromParams($oConfig, 'CapaIdentities', 'webmail', 'allow_additional_identities', 'bool');
		$this->setConfigFromParams($oConfig, 'CapaAttachmentThumbnails', 'interface', 'show_attachment_thumbnail', 'bool');
		$this->setConfigFromParams($oConfig, 'CapaThemes', 'webmail', 'allow_themes', 'bool');
		$this->setConfigFromParams($oConfig, 'CapaUserBackground', 'webmail', 'allow_user_background', 'bool');
		$this->setConfigFromParams($oConfig, 'CapaOpenPGP', 'security', 'openpgp', 'bool');

		$this->setConfigFromParams($oConfig, 'DetermineUserLanguage', 'login', 'determine_user_language', 'bool');
		$this->setConfigFromParams($oConfig, 'DetermineUserDomain', 'login', 'determine_user_domain', 'bool');

		$this->setConfigFromParams($oConfig, 'Title', 'webmail', 'title', 'string');
		$this->setConfigFromParams($oConfig, 'LoadingDescription', 'webmail', 'loading_description', 'string');
		$this->setConfigFromParams($oConfig, 'FaviconUrl', 'webmail', 'favicon_url', 'string');

		$this->setConfigFromParams($oConfig, 'TokenProtection', 'security', 'csrf_protection', 'bool');
		$this->setConfigFromParams($oConfig, 'EnabledPlugins', 'plugins', 'enable', 'bool');

		return $this->DefaultResponse(__FUNCTION__, $oConfig->Save());
	}

	/**
	 * @throws \MailSo\Base\Exceptions\Exception
	 */
	public function DoAdminLogin() : array
	{
		$sLogin = trim($this->GetActionParam('Login', ''));
		$sPassword = $this->GetActionParam('Password', '');

		$this->Logger()->AddSecret($sPassword);

		$totp = $this->Config()->Get('security', 'admin_totp', '');

		// \explode(':',`getent shadow root`)[1];
		if (!\strlen($sLogin) || !\strlen($sPassword) ||
			!$this->Config()->Get('security', 'allow_admin_panel', true) ||
			$sLogin !== $this->Config()->Get('security', 'admin_login', '') ||
			!$this->Config()->ValidatePassword($sPassword)
			|| ($totp && !\SnappyMail\TOTP::Verify($totp, $this->GetActionParam('TOTP', ''))))
		{
			$this->LoggerAuthHelper(null, $this->getAdditionalLogParamsByUserLogin($sLogin, true), true);
			$this->loginErrorDelay();
			throw new ClientException(Notifications::AuthError);
		}

		$sToken = $this->getAdminToken();
		$this->setAdminAuthToken($sToken);

		return $this->DefaultResponse(__FUNCTION__, $sToken ? $this->AppData(true) : false);
	}

	public function DoAdminLogout() : array
	{
		$sAdminKey = $this->getAdminAuthKey();
		if ($sAdminKey) {
			$this->Cacher(null, true)->Delete(KeyPathHelper::SessionAdminKey($sAdminKey));
		}
		Utils::ClearCookie(static::$AUTH_ADMIN_TOKEN_KEY);
		return $this->TrueResponse(__FUNCTION__);
	}

	public function DoAdminContactsTest() : array
	{
		$this->IsAdminLoggined();

		$oConfig = $this->Config();

		$this->setConfigFromParams($oConfig, 'ContactsPdoDsn', 'contacts', 'pdo_dsn', 'string');
		$this->setConfigFromParams($oConfig, 'ContactsPdoUser', 'contacts', 'pdo_user', 'string');
		$this->setConfigFromParams($oConfig, 'ContactsPdoPassword', 'contacts', 'pdo_password', 'dummy');

		$self = $this;
		$this->setConfigFromParams($oConfig, 'ContactsPdoType', 'contacts', 'type', 'string', function ($sType) use ($self) {
			return \RainLoop\Providers\AddressBook\PdoAddressBook::validPdoType($sType);
		});

		$sTestMessage = $this->AddressBookProvider(null, true)->Test();
		return $this->DefaultResponse(__FUNCTION__, array(
			'Result' => '' === $sTestMessage,
			'Message' => \MailSo\Base\Utils::Utf8Clear($sTestMessage)
		));
	}

	public function DoAdminPasswordUpdate() : array
	{
		$this->IsAdminLoggined();

		$bResult = false;
		$oConfig = $this->Config();

		$sPassword = $this->GetActionParam('Password', '');
		$this->Logger()->AddSecret($sPassword);

		$sNewPassword = $this->GetActionParam('NewPassword', '');
		if (\strlen($sNewPassword)) {
			$this->Logger()->AddSecret($sNewPassword);
		}

		$passfile = APP_PRIVATE_DATA.'admin_password.txt';

		if ($oConfig->ValidatePassword($sPassword)) {
			$sLogin = \trim($this->GetActionParam('Login', ''));
			if (\strlen($sLogin)) {
				$oConfig->Set('security', 'admin_login', $sLogin);
			}

			$oConfig->Set('security', 'admin_totp', $this->GetActionParam('TOTP', ''));

			if (\strlen($sNewPassword)) {
				$oConfig->SetPassword($sNewPassword);
				if (\is_file($passfile) && \trim(\file_get_contents($passfile)) !== $sNewPassword) {
					\unlink($passfile);
				}
			}

			$bResult = $oConfig->Save();
		}

		return $this->DefaultResponse(__FUNCTION__, $bResult
			? array('Weak' => \is_file($passfile))
			: false);
	}

	public function DoAdminDomainLoad() : array
	{
		$this->IsAdminLoggined();

		return $this->DefaultResponse(__FUNCTION__,
			$this->DomainProvider()->Load($this->GetActionParam('Name', ''), false, false));
	}

	public function DoAdminDomainList() : array
	{
		$this->IsAdminLoggined();
		$bIncludeAliases = !empty($this->GetActionParam('IncludeAliases', '1'));
		return $this->DefaultResponse(__FUNCTION__, $this->DomainProvider()->GetList($bIncludeAliases));
	}

	public function DoAdminDomainDelete() : array
	{
		$this->IsAdminLoggined();

		return $this->DefaultResponse(__FUNCTION__,
			$this->DomainProvider()->Delete((string) $this->GetActionParam('Name', '')));
	}

	public function DoAdminDomainDisable() : array
	{
		$this->IsAdminLoggined();

		return $this->DefaultResponse(__FUNCTION__, $this->DomainProvider()->Disable(
			(string) $this->GetActionParam('Name', ''),
			'1' === (string) $this->GetActionParam('Disabled', '0')
		));
	}

	public function DoAdminDomainSave() : array
	{
		$this->IsAdminLoggined();

		$oDomain = $this->DomainProvider()->LoadOrCreateNewFromAction($this);

		return $this->DefaultResponse(__FUNCTION__,
			$oDomain ? $this->DomainProvider()->Save($oDomain) : false);
	}

	public function DoAdminDomainAliasSave() : array
	{
		$this->IsAdminLoggined();

		return $this->DefaultResponse(__FUNCTION__, $this->DomainProvider()->SaveAlias(
			(string) $this->GetActionParam('Name', ''),
			(string) $this->GetActionParam('Alias', '')
		));
	}

	public function DoAdminDomainTest() : array
	{
		$this->IsAdminLoggined();

		$bImapResult = false;
		$sImapErrorDesc = '';
		$bSmtpResult = false;
		$sSmtpErrorDesc = '';
		$bSieveResult = false;
		$sSieveErrorDesc = '';

		$iConnectionTimeout = 5;

		$oDomain = $this->DomainProvider()->LoadOrCreateNewFromAction($this, 'test.example.com');
		if ($oDomain)
		{
			try
			{
				$oImapClient = new \MailSo\Imap\ImapClient();
				$oImapClient->SetLogger($this->Logger());
				$oImapClient->SetTimeOuts($iConnectionTimeout);

				$iTime = \microtime(true);
				$oSettings = new \MailSo\Net\ConnectSettings;
				$oSettings->host = $oDomain->IncHost();
				$oSettings->port = $oDomain->IncPort();
				$oSettings->type = $oDomain->IncSecure();
				$oImapClient->Connect($oSettings);

				$sUsername = $this->GetActionParam('username', '');
				if ($sUsername) {
					$aSASLMechanisms = [];
					$oConfig = $this->Config();
					if ($oConfig->Get('labs', 'sasl_allow_scram_sha', false)) {
						// https://github.com/the-djmaze/snappymail/issues/182
						\array_push($aSASLMechanisms, 'SCRAM-SHA3-512', 'SCRAM-SHA-512', 'SCRAM-SHA-256', 'SCRAM-SHA-1');
					}
					if ($oConfig->Get('labs', 'sasl_allow_cram_md5', false)) {
						$aSASLMechanisms[] = 'CRAM-MD5';
					}
					if ($oConfig->Get('labs', 'sasl_allow_plain', true)) {
						$aSASLMechanisms[] = 'PLAIN';
					}
					$oImapClient->Login([
						'Login' => $sUsername,
						'Password' => $this->GetActionParam('password', ''),
						'SASLMechanisms' => $aSASLMechanisms
					]);
				}

				$oImapClient->Disconnect();
				$bImapResult = true;
			}
			catch (\MailSo\Net\Exceptions\SocketCanNotConnectToHostException $oException)
			{
				$this->Logger()->WriteException($oException, \LOG_ERR);
				$sImapErrorDesc = $oException->getSocketMessage();
				if (empty($sImapErrorDesc))
				{
					$sImapErrorDesc = $oException->getMessage();
				}
			}
			catch (\Throwable $oException)
			{
				$this->Logger()->WriteException($oException, \LOG_ERR);
				$sImapErrorDesc = $oException->getMessage();
			}

			if ($oDomain->OutUsePhpMail())
			{
				$bSmtpResult = \MailSo\Base\Utils::FunctionCallable('mail');
				if (!$bSmtpResult)
				{
					$sSmtpErrorDesc = 'PHP: mail() function is undefined';
				}
			}
			else
			{
				try
				{
					$oSmtpClient = new \MailSo\Smtp\SmtpClient();
					$oSmtpClient->SetLogger($this->Logger());
					$oSmtpClient->SetTimeOuts($iConnectionTimeout);

					$iTime = \microtime(true);
					$oSettings = new \MailSo\Net\ConnectSettings;
					$oSettings->host = $oDomain->OutHost();
					$oSettings->port = $oDomain->OutPort();
					$oSettings->type = $oDomain->OutSecure();
					$oSmtpClient->Connect($oSettings, \MailSo\Smtp\SmtpClient::EhloHelper());

					$oSmtpClient->Disconnect();
					$bSmtpResult = true;
				}
				catch (\MailSo\Net\Exceptions\SocketCanNotConnectToHostException $oException)
				{
					$this->Logger()->WriteException($oException, \LOG_ERR);
					$sSmtpErrorDesc = $oException->getSocketMessage();
					if (empty($sSmtpErrorDesc))
					{
						$sSmtpErrorDesc = $oException->getMessage();
					}
				}
				catch (\Throwable $oException)
				{
					$this->Logger()->WriteException($oException, \LOG_ERR);
					$sSmtpErrorDesc = $oException->getMessage();
				}
			}

			if ($oDomain->UseSieve())
			{
				try
				{
					$oSieveClient = new \MailSo\Sieve\ManageSieveClient();
					$oSieveClient->SetLogger($this->Logger());
					$oSieveClient->SetTimeOuts($iConnectionTimeout);

					$iTime = \microtime(true);
					$oSettings = new \MailSo\Net\ConnectSettings;
					$oSettings->host = $oDomain->SieveHost();
					$oSettings->port = $oDomain->SievePort();
					$oSettings->type = $oDomain->SieveSecure();
					$oSieveClient->Connect($oSettings);

					$oSieveClient->Disconnect();
					$bSieveResult = true;
				}
				catch (\MailSo\Net\Exceptions\SocketCanNotConnectToHostException $oException)
				{
					$this->Logger()->WriteException($oException, \LOG_ERR);
					$sSieveErrorDesc = $oException->getSocketMessage();
					if (empty($sSieveErrorDesc))
					{
						$sSieveErrorDesc = $oException->getMessage();
					}
				}
				catch (\Throwable $oException)
				{
					$this->Logger()->WriteException($oException, \LOG_ERR);
					$sSieveErrorDesc = $oException->getMessage();
				}
			}
			else
			{
				$bSieveResult = true;
			}
		}

		return $this->DefaultResponse(__FUNCTION__, array(
			'Imap' => $bImapResult ? true : $sImapErrorDesc,
			'Smtp' => $bSmtpResult ? true : $sSmtpErrorDesc,
			'Sieve' => $bSieveResult ? true : $sSieveErrorDesc
		));
	}

	public function DoAdminPHPExtensions() : array
	{
		$aResult = [
			[
				'name' => 'PHP ' . PHP_VERSION,
				'loaded' => true
			]
		];
		foreach (['APCu', 'cURL','GnuPG','GD','Gmagick','Imagick','iconv','intl','LDAP','OpenSSL','pdo_mysql','pdo_pgsql','pdo_sqlite','redis','Sodium','Tidy','uuid','XXTEA','Zip'] as $name) {
			$aResult[] = [
				'name' => $name,
				'loaded' => \extension_loaded(\strtolower($name))
			];
		}
		return $this->DefaultResponse(__FUNCTION__, $aResult);
	}

	public function DoAdminPackagesList() : array
	{
		return $this->DefaultResponse(__FUNCTION__, \SnappyMail\Repository::getPackagesList());
	}

	public function DoAdminPackageDelete() : array
	{
		$sId = $this->GetActionParam('Id', '');
		$bResult = \SnappyMail\Repository::deletePackage($sId);
		static::pluginEnable($sId, false);
		return $this->DefaultResponse(__FUNCTION__, $bResult);
	}

	public function DoAdminPackageInstall() : array
	{
		$sType = $this->GetActionParam('Type', '');
		$bResult = \SnappyMail\Repository::installPackage(
			$sType,
			$this->GetActionParam('Id', ''),
			$this->GetActionParam('File', '')
		);
		return $this->DefaultResponse(__FUNCTION__, $bResult ?
			('plugin' !== $sType ? array('Reload' => true) : true) : false);
	}

	// /?admin/Backup
	public function DoAdminBackup() : void
	{
		try {
			$this->IsAdminLoggined();
			$file = \SnappyMail\Upgrade::backup();
			\header('Content-Type: application/gzip');
			\header('Content-Disposition: attachment; filename="' . \basename($file) . '"');
			\header('Content-Transfer-Encoding: binary');
			\header('Content-Length: ' . \filesize($file));
			$fp = \fopen($file, 'rb');
			\fpassthru($fp);
			\unlink($file);
		} catch (\Throwable $e) {
			if (102 == $e->getCode()) {
				\MailSo\Base\Http::StatusHeader(403);
			}
			echo $e->getMessage();
		}
		exit;
	}

	public function DoAdminUpdateInfo() : array
	{
		$this->IsAdminLoggined();

		$info = \SnappyMail\Repository::getLatestCoreInfo();

		$sVersion = empty($info->version) ? '' : $info->version;

		$bShowWarning = false;
		if (!empty($info->warnings) && APP_VERSION !== APP_DEV_VERSION) {
			foreach ($info->warnings as $sWarningVersion) {
				$sWarningVersion = \trim($sWarningVersion);

				if (\version_compare(APP_VERSION, $sWarningVersion, '<')
				 && \version_compare($sVersion, $sWarningVersion, '>='))
				{
					$bShowWarning = true;
					break;
				}
			}
		}

		$aWarnings = [];
		if (!\version_compare(APP_VERSION, '2.0', '>')) {
			$aWarnings[] = APP_VERSION;
		}
		if (!\is_writable(\dirname(APP_VERSION_ROOT_PATH))) {
			$aWarnings[] = 'Can not write into: ' . \dirname(APP_VERSION_ROOT_PATH);
		}
		if (!\is_writable(APP_INDEX_ROOT_PATH . 'index.php')) {
			$aWarnings[] = 'Can not edit: ' . APP_INDEX_ROOT_PATH . 'index.php';
		}

		return $this->DefaultResponse(__FUNCTION__, array(
			 'Updatable' => \SnappyMail\Repository::canUpdateCore(),
			 'Warning' => $bShowWarning,
			 'Version' => $sVersion,
			 'VersionCompare' => \version_compare(APP_VERSION, $sVersion),
			 'Warnings' => $aWarnings
		));
	}

	public function DoAdminUpgradeCore() : array
	{
		return $this->DefaultResponse(__FUNCTION__, \SnappyMail\Upgrade::core());
	}

	public function DoAdminPluginDisable() : array
	{
		$this->IsAdminLoggined();

		$sId = (string) $this->GetActionParam('Id', '');
		$bDisable = '1' === (string) $this->GetActionParam('Disabled', '1');

		if (!$bDisable)
		{
			$oPlugin = $this->Plugins()->CreatePluginByName($sId);
			if ($oPlugin)
			{
				$sValue = $oPlugin->Supported();
				if (\strlen($sValue))
				{
					return $this->FalseResponse(__FUNCTION__, Notifications::UnsupportedPluginPackage, $sValue);
				}
			}
			else
			{
				return $this->FalseResponse(__FUNCTION__, Notifications::InvalidPluginPackage);
			}
		}

		return $this->DefaultResponse(__FUNCTION__, $this->pluginEnable($sId, !$bDisable));
	}

	public function DoAdminPluginLoad() : array
	{
		$this->IsAdminLoggined();

		$mResult = false;
		$sId = (string) $this->GetActionParam('Id', '');

		if (!empty($sId)) {
			$oPlugin = $this->Plugins()->CreatePluginByName($sId);
			if ($oPlugin) {
				$mResult = array(
					'@Object' => 'Object/Plugin',
					'Id' => $sId,
					'Name' => $oPlugin->Name(),
					'Readme' => $oPlugin->Description(),
					'Config' => array()
				);

				$aMap = $oPlugin->ConfigMap();
				if (\is_array($aMap)) {
					$oConfig = $oPlugin->Config();
					foreach ($aMap as $oItem) {
						if ($oItem) {
							if ($oItem instanceof \RainLoop\Plugins\Property) {
								if (PluginPropertyType::PASSWORD === $oItem->Type()) {
									$oItem->SetValue(APP_DUMMY);
								} else {
									$oItem->SetValue($oConfig->Get('plugin', $oItem->Name(), ''));
								}
								$mResult['Config'][] = $oItem;
							} else if ($oItem instanceof \RainLoop\Plugins\PropertyCollection) {
								foreach ($oItem as $oSubItem) {
									if ($oSubItem && $oSubItem instanceof \RainLoop\Plugins\Property) {
										if (PluginPropertyType::PASSWORD === $oSubItem->Type()) {
											$oSubItem->SetValue(APP_DUMMY);
										} else {
											$oSubItem->SetValue($oConfig->Get('plugin', $oSubItem->Name(), ''));
										}
									}
								}
								$mResult['Config'][] = $oItem;
							}
						}
					}
				}
			}
		}

		return $this->DefaultResponse(__FUNCTION__, $mResult);
	}

	public function DoAdminPluginSettingsUpdate() : array
	{
		$this->IsAdminLoggined();

		$mResult = false;
		$sId = (string) $this->GetActionParam('Id', '');

		if (!empty($sId))
		{
			$oPlugin = $this->Plugins()->CreatePluginByName($sId);
			if ($oPlugin)
			{
				$oConfig = $oPlugin->Config();
				$aMap = $oPlugin->ConfigMap(true);
				if (\is_array($aMap))
				{
					$aSettings = (array) $this->GetActionParam('Settings', []);
					foreach ($aMap as $oItem)
					{
						$sKey = $oItem->Name();
						$sValue = $aSettings[$sKey] ?? $oConfig->Get('plugin', $sKey);
						if (PluginPropertyType::PASSWORD !== $oItem->Type() || APP_DUMMY !== $sValue)
						{
							$mResultValue = null;
							switch ($oItem->Type()) {
								case PluginPropertyType::INT:
									$mResultValue  = (int) $sValue;
									break;
								case PluginPropertyType::BOOL:
									$mResultValue  = '1' === (string) $sValue;
									break;
								case PluginPropertyType::SELECTION:
									if (is_array($oItem->DefaultValue()) && in_array($sValue, $oItem->DefaultValue()))
									{
										$mResultValue = (string) $sValue;
									}
									break;
								case PluginPropertyType::PASSWORD:
								case PluginPropertyType::STRING:
								case PluginPropertyType::STRING_TEXT:
								case PluginPropertyType::URL:
									$mResultValue = (string) $sValue;
									break;
							}

							if (null !== $mResultValue)
							{
								$oConfig->Set('plugin', $sKey, $mResultValue);
							}
						}
					}
				}

				$mResult = $oConfig->Save();
			}
		}

		if (!$mResult)
		{
			throw new ClientException(Notifications::CantSavePluginSettings);
		}

		return $this->DefaultResponse(__FUNCTION__, true);
	}

	public function DoAdminQRCode() : array
	{
		$user = (string) $this->GetActionParam('username', '');
		$secret = (string) $this->GetActionParam('TOTP', '');
		$issuer = \rawurlencode(\RainLoop\API::Config()->Get('webmail', 'title', 'SnappyMail'));
		$QR = \SnappyMail\QRCode::getMinimumQRCode(
			"otpauth://totp/{$issuer}:{$user}?secret={$secret}&issuer={$issuer}",
//			"otpauth://totp/{$user}?secret={$secret}",
			\SnappyMail\QRCode::ERROR_CORRECT_LEVEL_M
		);
		return $this->DefaultResponse(__FUNCTION__, $QR->__toString());
	}

	private function setAdminAuthToken(string $sToken) : void
	{
		Utils::SetCookie(static::$AUTH_ADMIN_TOKEN_KEY, $sToken);
	}

	private function getAdminToken() : string
	{
		$sRand = \MailSo\Base\Utils::Sha1Rand();
		if (!$this->Cacher(null, true)->Set(KeyPathHelper::SessionAdminKey($sRand), \time()))
		{
			$this->oLogger->Write('Cannot store an admin token',
				\LOG_WARNING);
			return '';
		}

		return Utils::EncodeKeyValuesQ(array('token', $sRand));
	}

	private function pluginEnable(string $sName, bool $bEnable = true) : bool
	{
		if (!\strlen($sName))
		{
			return false;
		}

		$oConfig = $this->Config();

		$aEnabledPlugins = \SnappyMail\Repository::getEnabledPackagesNames();

		$aNewEnabledPlugins = array();
		if ($bEnable)
		{
			$aNewEnabledPlugins = $aEnabledPlugins;
			$aNewEnabledPlugins[] = $sName;
		}
		else
		{
			foreach ($aEnabledPlugins as $sPlugin)
			{
				if ($sName !== $sPlugin && \strlen($sPlugin))
				{
					$aNewEnabledPlugins[] = $sPlugin;
				}
			}
		}

		$oConfig->Set('plugins', 'enabled_list', \trim(\implode(',', \array_unique($aNewEnabledPlugins)), ' ,'));

		return $oConfig->Save();
	}

	private function setConfigFromParams(\RainLoop\Config\Application $oConfig, string $sParamName, string $sConfigSector, string $sConfigName, string $sType = 'string', ?callable $mStringCallback = null): void
	{
		if ($this->HasActionParam($sParamName)) {
			$sValue = $this->GetActionParam($sParamName, '');
			switch ($sType) {
				default:
				case 'string':
					$sValue = (string)$sValue;
					if ($mStringCallback && is_callable($mStringCallback)) {
						$sValue = $mStringCallback($sValue);
					}

					$oConfig->Set($sConfigSector, $sConfigName, $sValue);
					break;

				case 'dummy':
					$sValue = (string) $this->GetActionParam($sParamName, APP_DUMMY);
					if (APP_DUMMY !== $sValue) {
						$oConfig->Set($sConfigSector, $sConfigName, $sValue);
					}
					break;

				case 'int':
					$iValue = (int)$sValue;
					$oConfig->Set($sConfigSector, $sConfigName, $iValue);
					break;

				case 'bool':
					$oConfig->Set($sConfigSector, $sConfigName, !empty($sValue) && 'false' !== $sValue);
					break;
			}
		}
	}

}
