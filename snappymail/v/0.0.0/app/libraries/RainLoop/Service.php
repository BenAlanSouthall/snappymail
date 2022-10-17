<?php

namespace RainLoop;

abstract class Service
{
	/**
	 * @staticvar bool $bOne
	 */
	public static function Handle() : bool
	{
		static $bOne = null;
		if (null === $bOne)
		{
			$bOne = static::RunResult();
		}

		return $bOne;
	}

	private static function RunResult() : bool
	{
		$oConfig = Api::Config();

		$sServer = \trim($oConfig->Get('security', 'custom_server_signature', ''));
		if (\strlen($sServer))
		{
			\header('Server: '.$sServer);
		}

		\header('Referrer-Policy: no-referrer');
		\header('X-Content-Type-Options: nosniff');

		// Google FLoC, obsolete
//		\header('Permissions-Policy: interest-cohort=()');

		static::setCSP();

		$sXssProtectionOptionsHeader = \trim($oConfig->Get('security', 'x_xss_protection_header', '')) ?: '1; mode=block';
		\header('X-XSS-Protection: '.$sXssProtectionOptionsHeader);

		$oHttp = \MailSo\Base\Http::SingletonInstance();
		if ($oConfig->Get('labs', 'force_https', false) && !$oHttp->IsSecure())
		{
			\header('Location: https://'.$oHttp->GetHost(false, false).$oHttp->GetUrl());
			exit;
		}

		$sQuery = \trim($_SERVER['QUERY_STRING'] ?? '');
		$iPos = \strpos($sQuery, '&');
		if (0 < $iPos) {
			$sQuery = \substr($sQuery, 0, $iPos);
		}
		$sQuery = \trim(\trim($sQuery), ' /');
		$aSubQuery = $_GET['q'] ?? null;
		if (\is_array($aSubQuery)) {
			$aSubQuery = \array_map(function ($sS) {
				return \trim(\trim($sS), ' /');
			}, $aSubQuery);

			if (\count($aSubQuery)) {
				$sQuery .= '/' . \implode('/', $aSubQuery);
			}
		}

		$aPaths = \explode('/', $sQuery);

		$bAdmin = ($this->oActions instanceof Actions\Admin);
		$bAdmin || $this->oActions->getAuthAccountHash();

		$oActions->Plugins()->RunHook('filter.http-paths', array(&$aPaths));

		if ($oHttp->IsPost())
		{
			$oHttp->ServerNoCache();
		}

		$oServiceActions = new ServiceActions($oHttp, $oActions);

		if ($bAdmin && !$oConfig->Get('security', 'allow_admin_panel', true))
		{
			\MailSo\Base\Http::StatusHeader(403);
			echo $oServiceActions->ErrorTemplates('Access Denied.',
				'Access to the SnappyMail Admin Panel is not allowed!');

			return false;
		}

		$bIndex = true;
		$sResult = '';
		if (\count($aPaths) && !empty($aPaths[0]) && 'index' !== \strtolower($aPaths[0]))
		{
			if ('mailto' !== \strtolower($aPaths[0]) && !\SnappyMail\HTTP\SecFetch::isSameOrigin()) {
				\MailSo\Base\Http::StatusHeader(403);
				echo $oServiceActions->ErrorTemplates('Access Denied.',
					"Disallowed Sec-Fetch
					Dest: " . ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') . "
					Mode: " . ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') . "
					Site: " . ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') . "
					User: " . (\SnappyMail\HTTP\SecFetch::user() ? 'true' : 'false'));
				return false;
			}

			$bIndex = false;
			$sMethodName = 'Service'.\preg_replace('/@.+$/', '', $aPaths[0]);
			$sMethodExtra = \strpos($aPaths[0], '@') ? \preg_replace('/^[^@]+@/', '', $aPaths[0]) : '';

			if (\method_exists($oServiceActions, $sMethodName) &&
				\is_callable(array($oServiceActions, $sMethodName)))
			{
				$oServiceActions->SetQuery($sQuery)->SetPaths($aPaths);
				$sResult = $oServiceActions->{$sMethodName}($sMethodExtra);
			}
			else if (!$oActions->Plugins()->RunAdditionalPart($aPaths[0], $aPaths))
			{
				$bIndex = true;
			}
		}

		if ($bIndex)
		{
			if (!$bAdmin) {
				$login = $oConfig->Get('labs', 'custom_login_link', '');
				if ($login && !$oActions->getAccountFromToken(false)) {
					\header("Location: {$login}");
					exit;
				}
			}

//			if (!\SnappyMail\HTTP\SecFetch::isEntering()) {
			\header('Content-Type: text/html; charset=utf-8');
			$oHttp->ServerNoCache();

			if (!\is_dir(APP_DATA_FOLDER_PATH) || !\is_writable(APP_DATA_FOLDER_PATH))
			{
				echo $oServiceActions->ErrorTemplates(
					'Permission denied!',
					'SnappyMail can not access the data folder "'.APP_DATA_FOLDER_PATH.'"'
				);

				return false;
			}

			$sLanguage = $oActions->GetLanguage($bAdmin);

			$sAppJsMin = $oConfig->Get('labs', 'use_app_debug_js', false) ? '' : '.min';
			$sAppCssMin = $oConfig->Get('labs', 'use_app_debug_css', false) ? '' : '.min';

			$sFaviconUrl = (string) $oConfig->Get('webmail', 'favicon_url', '');

			$sFaviconPngLink = $sFaviconUrl ? $sFaviconUrl : Utils::WebStaticPath('apple-touch-icon.png');
			$sAppleTouchLink = $sFaviconUrl ? '' : Utils::WebStaticPath('apple-touch-icon.png');

			$aTemplateParameters = array(
				'{{BaseAppFaviconPngLinkTag}}' => $sFaviconPngLink ? '<link type="image/png" rel="shortcut icon" href="'.$sFaviconPngLink.'">' : '',
				'{{BaseAppFaviconTouchLinkTag}}' => $sAppleTouchLink ? '<link type="image/png" rel="apple-touch-icon" href="'.$sAppleTouchLink.'">' : '',
				'{{BaseAppMainCssLink}}' => Utils::WebStaticPath('css/'.($bAdmin ? 'admin' : 'app').$sAppCssMin.'.css'),
				'{{BaseAppThemeCssLink}}' => $oActions->ThemeLink($bAdmin),
				'{{BaseAppManifestLink}}' => Utils::WebStaticPath('manifest.json'),
				'{{LoadingDescriptionEsc}}' => \htmlspecialchars($oConfig->Get('webmail', 'loading_description', 'SnappyMail'), ENT_QUOTES|ENT_IGNORE, 'UTF-8'),
				'{{BaseAppAdmin}}' => $bAdmin ? 1 : 0
			);

			$sCacheFileName = '';
			if ($oConfig->Get('labs', 'cache_system_data', true))
			{
				$sCacheFileName = 'TMPL:' . $sLanguage . \md5(
					Utils::jsonEncode(array(
						$oConfig->Get('cache', 'index', ''),
						$oActions->Plugins()->Hash(),
						$sAppJsMin,
						$sAppCssMin,
						$aTemplateParameters,
						APP_VERSION
					))
				);
				$sResult = $oActions->Cacher()->Get($sCacheFileName);
			}

			if ($sResult) {
				$sResult .= '<!--cached-->';
			} else {
				$aTemplateParameters['{{BaseAppBootCss}}'] = \file_get_contents(APP_VERSION_ROOT_PATH.'static/css/boot'.$sAppCssMin.'.css');
				$aTemplateParameters['{{BaseAppBootScript}}'] = \file_get_contents(APP_VERSION_ROOT_PATH.'static/js'.($sAppJsMin ? '/min' : '').'/boot'.$sAppJsMin.'.js');
				$aTemplateParameters['{{BaseAppThemeCss}}'] = \preg_replace(
					'/\\s*([:;{},]+)\\s*/s',
					'$1',
					$oActions->compileCss($oActions->GetTheme($bAdmin), $bAdmin)
				);
				$aTemplateParameters['{{BaseLanguage}}'] = $oActions->compileLanguage($sLanguage, $bAdmin);
				$aTemplateParameters['{{BaseTemplates}}'] = Utils::ClearHtmlOutput($oServiceActions->compileTemplates($bAdmin));
				$sResult = Utils::ClearHtmlOutput(\file_get_contents(APP_VERSION_ROOT_PATH.'app/templates/Index.html'));
				$sResult = \strtr($sResult, $aTemplateParameters);
				if ($sCacheFileName) {
					$oActions->Cacher()->Set($sCacheFileName, $sResult);
				}
			}

			$sScriptNonce = \SnappyMail\UUID::generate();
			static::setCSP($sScriptNonce);
			$sResult = \str_replace('nonce=""', 'nonce="'.$sScriptNonce.'"', $sResult);
/*
			\preg_match('<script[^>]+>(.+)</script>', $sResult, $script);
			$sScriptHash = 'sha256-'.\base64_encode(\hash('sha256', $script[1], true));
			static::setCSP(null, $sScriptHash);
*/
		}
		else if (!\headers_sent())
		{
			\header('X-XSS-Protection: 1; mode=block');
		}

		// Output result
		echo $sResult;
		unset($sResult);

		$oActions->BootEnd();

		return true;
	}

	private static function setCSP(string $sScriptNonce = null) : void
	{
		$CSP = new \SnappyMail\HTTP\CSP(\trim(Api::Config()->Get('security', 'content_security_policy', '')));
		$CSP->report = Api::Config()->Get('security', 'csp_report', false);
		$CSP->report_only = Api::Config()->Get('debug', 'enable', false); // '0.0.0' === APP_VERSION
//		$CSP->frame = \explode(' ', Api::Config()->Get('security', 'csp_frame', ''));

		// Allow https: due to remote images in e-mails or use proxy
		if (!Api::Config()->Get('security', 'use_local_proxy_for_external_images', '')) {
			$CSP->img[] = 'https:';
			$CSP->img[] = 'http:';
		}
		if ($sScriptNonce) {
			$CSP->script[] = "'nonce-{$sScriptNonce}'";
		}

		Api::Actions()->Plugins()->RunHook('main.content-security-policy', array($CSP));

		$CSP->setHeaders();
	}
}
