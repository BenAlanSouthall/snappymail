<?php

class RecaptchaPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'reCaptcha',
		AUTHOR   = 'SnappyMail',
		URL      = 'https://snappymail.eu/',
		VERSION  = '2.12.1',
		RELEASE  = '2022-02-14',
		REQUIRED = '2.12.1',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'A CAPTCHA (v2) is a program that can generate and grade tests that humans can pass but current computer programs cannot. For example, humans can read distorted text as the one shown below, but current computer programs can\'t. More info at https://developers.google.com/recaptcha';

	/**
	 * @return void
	 */
	public function Init() : void
	{
		$this->UseLangs(true);

		$this->addJs('js/recaptcha.js');

		$this->addHook('json.action-pre-call', 'AjaxActionPreCall');
		$this->addHook('filter.json-response', 'FilterAjaxResponse');
		$this->addHook('main.content-security-policy', 'ContentSecurityPolicy');
	}

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('public_key')->SetLabel('Site key')
				->SetAllowedInJs(true)
				->SetDefaultValue(''),
			\RainLoop\Plugins\Property::NewInstance('private_key')->SetLabel('Secret key')
				->SetDefaultValue(''),
			\RainLoop\Plugins\Property::NewInstance('theme')->SetLabel('Theme')
				->SetAllowedInJs(true)
				->SetType(\RainLoop\Enumerations\PluginPropertyType::SELECTION)
				->SetDefaultValue(array('light', 'dark')),
			\RainLoop\Plugins\Property::NewInstance('error_limit')->SetLabel('Limit')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::SELECTION)
				->SetDefaultValue(array('0', 1, 2, 3, 4, 5))
				->SetDescription('')
		);
	}

	/**
	 * @return string
	 */
	private function getCaptchaCacherKey()
	{
		return 'CaptchaNew/Login/'.\RainLoop\Utils::GetConnectionToken();
	}

	/**
	 * @return int
	 */
	private function getLimit()
	{
		$iConfigLimit = $this->Config()->Get('plugin', 'error_limit', 0);
		if (0 < $iConfigLimit) {
			$oCacher = $this->Manager()->Actions()->Cacher();
			$sLimit = $oCacher && $oCacher->IsInited() ? $oCacher->Get($this->getCaptchaCacherKey()) : '0';

			if (\strlen($sLimit) && \is_numeric($sLimit)) {
				$iConfigLimit -= (int) $sLimit;
			}
		}

		return $iConfigLimit;
	}

	/**
	 * @return void
	 */
	public function FilterAppDataPluginSection(bool $bAdmin, bool $bAuth, array &$aConfig) : void
	{
		if (!$bAdmin && !$bAuth) {
			$aConfig['show_captcha_on_login'] = 1 > $this->getLimit();;
		}
	}

	/**
	 * @param string $sAction
	 */
	public function AjaxActionPreCall(string $sAction)
	{
		if ('Login' === $sAction && 0 >= $this->getLimit()) {
			$bResult = false;

			$HTTP = \SnappyMail\HTTP\Request::factory();
			$oResponse = $HTTP->doRequest('POST', 'https://www.google.com/recaptcha/api/siteverify', array(
				'secret' => $this->Config()->Get('plugin', 'private_key', ''),
				'response' => $this->Manager()->Actions()->GetActionParam('RecaptchaResponse', '')
			));

			if ($oResponse) {
				$aResp = \json_decode($oResponse->body, true);
				if (\is_array($aResp) && isset($aResp['success']) && $aResp['success']) {
					$bResult = true;
				}
			}

			if (!$bResult) {
				$this->Manager()->Actions()->Logger()->Write('RecaptchaResponse:'.$sResult);
				throw new \RainLoop\Exceptions\ClientException(105);
			}
		}
	}

	/**
	 * @param string $sAction
	 * @param array $aResponseItem
	 */
	public function FilterAjaxResponse(string $sAction, array &$aResponseItem)
	{
		if ('Login' === $sAction && $aResponseItem && isset($aResponseItem['Result'])) {
			$oCacher = $this->Manager()->Actions()->Cacher();
			$iConfigLimit = (int) $this->Config()->Get('plugin', 'error_limit', 0);

			$sKey = $this->getCaptchaCacherKey();

			if (0 < $iConfigLimit && $oCacher && $oCacher->IsInited()) {
				if (false === $aResponseItem['Result']) {
					$iLimit = 0;
					$sLimut = $oCacher->Get($sKey);
					if (\strlen($sLimut) && \is_numeric($sLimut)) {
						$iLimit = (int) $sLimut;
					}

					$oCacher->Set($sKey, ++$iLimit);

					if ($iConfigLimit <= $iLimit) {
						$aResponseItem['Captcha'] = true;
					}
				} else {
					$oCacher->Delete($sKey);
				}
			}
		}
	}

	public function ContentSecurityPolicy(\SnappyMail\HTTP\CSP $CSP)
	{
		$CSP->script[] = 'https://www.google.com/recaptcha/';
		$CSP->script[] = 'https://www.gstatic.com/recaptcha/';
		$CSP->frame[] = 'https://www.google.com/recaptcha/';
		$CSP->frame[] = 'https://recaptcha.google.com/recaptcha/';
	}

}
