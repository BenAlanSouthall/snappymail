<?php

class OverrideSmtpCredentialsPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'Override SMTP Credentials',
		VERSION = '2.2',
		RELEASE = '2022-04-13',
		REQUIRED = '2.5.0',
		CATEGORY = 'Filters',
		DESCRIPTION = 'Override SMTP credentials for specific users.';

	public function Init() : void
	{
		$this->addHook('smtp.before-connect', 'FilterSmtpCredentials');
		$this->addHook('smtp.before-login', 'FilterSmtpCredentials');
	}

	/**
	 * @param \RainLoop\Model\Account $oAccount
	 * @param \MailSo\Smtp\SmtpClient $oSmtpClient
	 * @param array $aSmtpCredentials
	 */
	public function FilterSmtpCredentials($oAccount, $oSmtpClient, &$aSmtpCredentials)
	{
		if ($oAccount instanceof \RainLoop\Model\Account && \is_array($aSmtpCredentials))
		{
			$sEmail = $oAccount->Email();

			$sHost = \trim($this->Config()->Get('plugin', 'smtp_host', ''));
			$sWhiteList = \trim($this->Config()->Get('plugin', 'override_users', ''));

			$sFoundValue = '';
			if (0 < strlen($sWhiteList) && 0 < \strlen($sHost) && \RainLoop\Plugins\Helper::ValidateWildcardValues($sEmail, $sWhiteList, $sFoundValue))
			{
				\SnappyMail\LOG::debug('SMTP Override', "{$sEmail} matched {$sFoundValue}");
				$aSmtpCredentials['Host'] = $sHost;
				$aSmtpCredentials['Port'] = (int) $this->Config()->Get('plugin', 'smtp_port', 25);

				$sSecure = \trim($this->Config()->Get('plugin', 'smtp_secure', 'None'));
				switch ($sSecure)
				{
					case 'SSL':
						$aSmtpCredentials['Secure'] = MailSo\Net\Enumerations\ConnectionSecurityType::SSL;
						break;
					case 'TLS':
						$aSmtpCredentials['Secure'] = MailSo\Net\Enumerations\ConnectionSecurityType::STARTTLS;
						break;
					default:
						$aSmtpCredentials['Secure'] = MailSo\Net\Enumerations\ConnectionSecurityType::NONE;
						break;
				}

				$aSmtpCredentials['UseAuth'] = (bool) $this->Config()->Get('plugin', 'smtp_auth', true);
				$aSmtpCredentials['Login'] = \trim($this->Config()->Get('plugin', 'smtp_user', ''));
				$aSmtpCredentials['Password'] = (string) $this->Config()->Get('plugin', 'smtp_password', '');
			}
			else
			{
				\SnappyMail\LOG::debug('SMTP Override', "{$sEmail} no match");
			}
		}
	}

	/**
	 * @return array
	 */
	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('smtp_host')->SetLabel('SMTP Host')
				->SetDefaultValue(''),
			\RainLoop\Plugins\Property::NewInstance('smtp_port')->SetLabel('SMTP Port')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::INT)
				->SetDefaultValue(25),
			\RainLoop\Plugins\Property::NewInstance('smtp_secure')->SetLabel('SMTP Secure')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::SELECTION)
				->SetDefaultValue(array('None', 'SSL', 'TLS')),
			\RainLoop\Plugins\Property::NewInstance('smtp_auth')->SetLabel('Use auth')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true),
			\RainLoop\Plugins\Property::NewInstance('smtp_user')->SetLabel('SMTP User')
				->SetDefaultValue(''),
			\RainLoop\Plugins\Property::NewInstance('smtp_password')->SetLabel('SMTP Password')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::PASSWORD)
				->SetDefaultValue(''),
			\RainLoop\Plugins\Property::NewInstance('override_users')->SetLabel('Override users')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('space as delimiter, wildcard supported.')
				->SetDefaultValue('user@example.com *@example2.com')
		);
	}
}
