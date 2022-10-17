<?php

class NextcloudPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'Nextcloud',
		VERSION = '2.2',
		RELEASE  = '2022-10-13',
		CATEGORY = 'Integrations',
		DESCRIPTION = 'Integrate with Nextcloud v20+',
		REQUIRED = '2.18.6';

	public function Init() : void
	{
		if (static::IsIntegrated()) {
			$this->UseLangs(true);

			$this->addHook('main.fabrica', 'MainFabrica');
			$this->addHook('filter.app-data', 'FilterAppData');

			$this->addJs('js/message.js');
			$this->addHook('json.attachments', 'DoAttachmentsActions');
			$this->addJsonHook('NextcloudSaveMsg', 'NextcloudSaveMsg');
		}
	}

	public function Supported() : string
	{
		return static::IsIntegrated() ? '' : 'Nextcloud not found to use this plugin';
	}

	public static function IsIntegrated()
	{
		return !empty($_ENV['SNAPPYMAIL_NEXTCLOUD']) && \class_exists('OC') && isset(\OC::$server);
	}

	public static function IsLoggedIn()
	{
		return static::IsIntegrated() && \OC::$server->getUserSession()->isLoggedIn();
	}

	/*
	\OC::$server->getCalendarManager();
	\OC::$server->getLDAPProvider();

	$oFiles = \OCP\Files::getStorage('files');
	if ($oFiles and $oFiles->is_dir('/')) {
		$dh = $oFiles->opendir('/');
		if (\is_resource($dh)) {
			while (($file = \readdir($dh)) !== false) {
				if ($file != '.' && $file != '..') {
					// DO THINGS
				}
			}
		}
	}
	*/

	public function NextcloudSaveMsg() : array
	{
		$aValues = \RainLoop\Utils::DecodeKeyValuesQ($this->jsonParam('msgHash', ''));
		if (!empty($aValues['Folder']) && !empty($aValues['Uid'])) {
			$oActions = \RainLoop\Api::Actions();
			$oMailClient = $oActions->MailClient();
			if (!$oMailClient->IsLoggined()) {
				$oAccount = $oActions->getAccountFromToken();
				$oAccount->ImapConnectAndLoginHelper($oActions->Plugins(), $oMailClient, $oActions->Config());
			}

			$sSaveFolder = $this->Config()->Get('plugin', 'save_folder', '') ?: 'Emails';
			$oFiles = \OCP\Files::getStorage('files');
			if ($oFiles && \method_exists($oFiles, 'file_put_contents')) {
				$oFiles->is_dir($sSaveFolder) || $oFiles->mkdir($sSaveFolder);
			}
			$sFilename = $sSaveFolder . '/' . ($this->jsonParam('filename', '') ?: \date('YmdHis')) . '.eml';

			$oMailClient->MessageMimeStream(
				function ($rResource) use ($oFiles, $sFilename) {
					if (\is_resource($rResource)) {
						$oFiles->file_put_contents($sFilename, $rResource);
					}
				},
				(string) $aValues['Folder'],
				(int) $aValues['Uid'],
				isset($aValues['MimeIndex']) ? (string) $aValues['MimeIndex'] : ''
			);
		}

		return $this->jsonResponse(__FUNCTION__, true);
	}

	public function DoAttachmentsActions(\SnappyMail\AttachmentsAction $data)
	{
		if (static::isLoggedIn() && 'nextcloud' === $data->action) {
			$oFiles = \OCP\Files::getStorage('files');
			if ($oFiles && \method_exists($oFiles, 'file_put_contents')) {
				$sSaveFolder = $this->Config()->Get('plugin', 'save_folder', '') ?: 'Attachments';
				$oFiles->is_dir($sSaveFolder) || $oFiles->mkdir($sSaveFolder);
				$data->result = true;
				foreach ($data->items as $aItem) {
					$sSavedFileName = isset($aItem['FileName']) ? $aItem['FileName'] : 'file.dat';
					$sSavedFileHash = !empty($aItem['FileHash']) ? $aItem['FileHash'] : '';
					if (!empty($sSavedFileHash)) {
						$fFile = $data->filesProvider->GetFile($data->account, $sSavedFileHash, 'rb');
						if (\is_resource($fFile)) {
							$sSavedFileNameFull = \MailSo\Base\Utils::SmartFileExists($sSaveFolder.'/'.$sSavedFileName, function ($sPath) use ($oFiles) {
								return $oFiles->file_exists($sPath);
							});

							if (!$oFiles->file_put_contents($sSavedFileNameFull, $fFile)) {
								$data->result = false;
							}

							if (\is_resource($fFile)) {
								\fclose($fFile);
							}
						}
					}
				}
			}

			foreach ($data->items as $aItem) {
				$sFileHash = (string) (isset($aItem['FileHash']) ? $aItem['FileHash'] : '');
				if (!empty($sFileHash)) {
					$data->filesProvider->Clear($data->account, $sFileHash);
				}
			}
		}
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult)) {
			$key = \array_search(\RainLoop\Enumerations\Capa::AUTOLOGOUT, $aResult['Capa']);
			if (false !== $key) {
				unset($aResult['Capa'][$key]);
			}
			$sUID = \OC::$server->getUserSession()->getUser()->getUID();
			$sWebDAV = \OC::$server->getURLGenerator()->linkTo('', 'remote.php') . '/dav/';
//			$sWebDAV = \OCP\Util::linkToRemote('dav');
			$aResult['Nextcloud'] = [
				'UID' => $sUID,
				'WebDAV' => $sWebDAV
//				'WebDAV_files' => $sWebDAV . '/files/' . $sUID
			];
			if (empty($aResult['Auth'])) {
				$config = \OC::$server->getConfig();
				// Only store the user's password in the current session if they have
				// enabled auto-login using Nextcloud username or email address.
				if ($config->getAppValue('snappymail', 'snappymail-autologin', false)) {
					$sEmail = $sUID;
				} else if ($config->getAppValue('snappymail', 'snappymail-autologin-with-email', false)) {
					$sEmail = $config->getUserValue($sUID, 'settings', 'email', '');
				}
				// If the user has set credentials for SnappyMail in their personal
				// settings, override everything before and use those instead.
				$sCustomEmail = $config->getUserValue($sUID, 'snappymail', 'snappymail-email', '');
				if ($sCustomEmail) {
					$sEmail = $sCustomEmail;
				}
				$aResult['DevEmail'] = $sEmail ?: '';
			}
		}
	}

	/**
	 * @param mixed $mResult
	 */
	public function MainFabrica(string $sName, &$mResult)
	{
		if (static::isLoggedIn()) {
			if ('suggestions' === $sName && $this->Config()->Get('plugin', 'suggestions', true)) {
				if (!\is_array($mResult)) {
					$mResult = array();
				}
				include_once __DIR__ . '/NextcloudContactsSuggestions.php';
				$mResult[] = new NextcloudContactsSuggestions();
			}
		}
	}

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('save_folder')->SetLabel('Save Folder')
				->SetDefaultValue('Attachments'),
			\RainLoop\Plugins\Property::NewInstance('suggestions')->SetLabel('Suggestions')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true)
		);
	}
}
