<?php

namespace RainLoop;

abstract class Api
{

	public static function Handle() : bool
	{
		static $bOne = false;
		if (!$bOne)
		{
			static::SetupDefaultMailSoConfig();
			$bOne = true;
		}
		return $bOne;
	}

	public static function Actions() : Actions
	{
		static $oActions = null;
		if (!$oActions) {
			$bAdmin = false;
			$sAdminPanelHost = static::Config()->Get('security', 'admin_panel_host', '');

			$aPaths = \explode('/', Utils::ParseQueryString());
			if (empty($sAdminPanelHost)) {
				$bAdmin = !empty($aPaths[0]) && \strtolower($aPaths[0]) === \strtolower(static::Config()->Get('security', 'admin_panel_key', 'admin'));
			}
			else if (empty($aPaths[0]) &&
				\MailSo\Base\Utils::StrToLowerIfAscii($sAdminPanelHost) === \MailSo\Base\Utils::StrToLowerIfAscii($this->oHttp->GetHost()))
			{
				$bAdmin = true;
			}
			if ($bAdmin) {
				$oActions = new Actions\Admin();
			} else {
				$oActions = new Actions\User();
			}
		}

		return $oActions;
	}

	public static function Config() : Config\Application
	{
		static $oConfig = null;
		if (!$oConfig) {
			$oConfig = new Config\Application();
			if (!$oConfig->Load()) {
				\usleep(10000);
				$oConfig->Load();
			}
//			\ini_set('display_errors', 0);
			if ($oConfig->Get('debug', 'enable', false)) {
				\error_reporting(E_ALL);
//				\ini_set('display_errors', 1);
				\ini_set('log_errors', 1);
			}
		}
		return $oConfig;
	}

	public static function Logger() : \MailSo\Log\Logger
	{
		static $oLogger = null;
		if (!$oLogger) {
			$oConfig = static::Config();
			$oLogger = new \MailSo\Log\Logger;
			if ($oConfig->Get('debug', 'enable', false)) {
				$oLogger->SetShowSecrets(!$oConfig->Get('logs', 'hide_passwords', true));
				$oLogger->SetLevel(\LOG_DEBUG);
			} else if ($oConfig->Get('logs', 'enable', false)) {
				$oLogger->SetShowSecrets(!$oConfig->Get('logs', 'hide_passwords', true));
				$oLogger->SetLevel(\max(3, \RainLoop\Api::Config()->Get('logs', 'level', \LOG_WARNING)));
			}
		}
		return $oLogger;
	}

	protected static function SetupDefaultMailSoConfig() : void
	{
		if (\class_exists('MailSo\Config'))
		{
			\MailSo\Config::$MessageListFastSimpleSearch =
				!!static::Config()->Get('labs', 'imap_message_list_fast_simple_search', true);

			\MailSo\Config::$MessageListCountLimitTrigger =
				(int) static::Config()->Get('labs', 'imap_message_list_count_limit_trigger', 0);

			\MailSo\Config::$MessageListDateFilter =
				(int) static::Config()->Get('labs', 'imap_message_list_date_filter', 0);

			\MailSo\Config::$MessageListPermanentFilter =
				\trim(static::Config()->Get('labs', 'imap_message_list_permanent_filter', ''));

			\MailSo\Config::$MessageAllHeaders =
				!!static::Config()->Get('labs', 'imap_message_all_headers', false);

			\MailSo\Config::$LargeThreadLimit =
				(int) static::Config()->Get('labs', 'imap_large_thread_limit', 50);

			\MailSo\Config::$ImapTimeout =
				(int) static::Config()->Get('labs', 'imap_timeout', 300);

			\MailSo\Config::$BoundaryPrefix =
				\trim(static::Config()->Get('labs', 'boundary_prefix', ''));

			$sSslCafile = static::Config()->Get('ssl', 'cafile', '');
			$sSslCapath = static::Config()->Get('ssl', 'capath', '');

			Utils::$CookieDefaultPath = static::Config()->Get('labs', 'cookie_default_path', '');
			Utils::$CookieSameSite = static::Config()->Get('security', 'cookie_samesite', 'Strict');
			Utils::$CookieSecure = isset($_SERVER['HTTPS'])
				|| 'None' == Utils::$CookieSameSite
				|| !!static::Config()->Get('labs', 'cookie_default_secure', false);

			if (!empty($sSslCafile) || !empty($sSslCapath))
			{
				\MailSo\Hooks::Add('Net.NetClient.StreamContextSettings/Filter', function ($aStreamContextSettings) use ($sSslCafile, $sSslCapath) {
					if (isset($aStreamContextSettings['ssl']) && \is_array($aStreamContextSettings['ssl']))
					{
						if (empty($aStreamContextSettings['ssl']['cafile']) && !empty($sSslCafile))
						{
							$aStreamContextSettings['ssl']['cafile'] = $sSslCafile;
						}

						if (empty($aStreamContextSettings['ssl']['capath']) && !empty($sSslCapath))
						{
							$aStreamContextSettings['ssl']['capath'] = $sSslCapath;
						}
					}
				});
			}

			\MailSo\Config::$CheckNewMessages = !!static::Config()->Get('labs', 'check_new_messages', true);
		}
	}

	public static function Version() : string
	{
		return APP_VERSION;
	}

	public static function CreateUserSsoHash(string $sEmail, string $sPassword, array $aAdditionalOptions = array(), bool $bUseTimeout = true) : ?string
	{
		$sSsoHash = \MailSo\Base\Utils::Sha1Rand(\sha1($sPassword.$sEmail));

		return static::Actions()->Cacher()->Set(
			KeyPathHelper::SsoCacherKey($sSsoHash),
			\SnappyMail\Crypt::EncryptToJSON(array(
				'Email' => $sEmail,
				'Password' => $sPassword,
				'AdditionalOptions' => $aAdditionalOptions,
				'Time' => $bUseTimeout ? \time() : 0
			), $sSsoHash)
		) ? $sSsoHash : null;
	}

	public static function ClearUserSsoHash(string $sSsoHash) : bool
	{
		return static::Actions()->Cacher()->Delete(KeyPathHelper::SsoCacherKey($sSsoHash));
	}

	public static function ClearUserData(string $sEmail) : bool
	{
		if (\strlen($sEmail))
		{
			$sEmail = \MailSo\Base\Utils::IdnToAscii($sEmail);

			$oStorageProvider = static::Actions()->StorageProvider();
			if ($oStorageProvider && $oStorageProvider->IsActive())
			{
				$oStorageProvider->DeleteStorage($sEmail);
			}

			$oAddressBookProvider = static::Actions()->AddressBookProvider();
			if ($oAddressBookProvider)
			{
				$oAddressBookProvider->DeleteAllContacts($sEmail);
			}

			return true;
		}

		return false;
	}

	public static function LogoutCurrentLogginedUser() : bool
	{
		// TODO: kill SignMe data to prevent automatic login?
		Utils::ClearCookie(Utils::SESSION_TOKEN);
		return true;
	}
}
