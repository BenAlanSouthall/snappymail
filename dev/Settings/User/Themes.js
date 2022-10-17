import { addObservablesTo } from 'External/ko';

import { SaveSettingStatus, UploadErrorCode } from 'Common/Enums';
import { changeTheme, convertThemeName } from 'Common/Utils';
import { themePreviewLink, serverRequest } from 'Common/Links';
import { i18n } from 'Common/Translator';
import { SettingsCapa } from 'Common/Globals';

import { ThemeStore } from 'Stores/Theme';

import Remote from 'Remote/User/Fetch';

const themeBackground = {
	name: ThemeStore.userBackgroundName,
	hash: ThemeStore.userBackgroundHash
};
addObservablesTo(themeBackground, {
	uploaderButton: null,
	loading: false,
	error: ''
});

export class UserSettingsThemes /*extends AbstractViewSettings*/ {
	constructor() {
		this.theme = ThemeStore.theme;
		this.themes = ThemeStore.themes;
		this.themesObjects = ko.observableArray();

		themeBackground.enabled = SettingsCapa('UserBackground');
		this.background = themeBackground;

		this.themeTrigger = ko.observable(SaveSettingStatus.Idle).extend({ debounce: 100 });

		ThemeStore.theme.subscribe(value => {
			this.themesObjects.forEach(theme => theme.selected(value === theme.name));

			changeTheme(value, this.themeTrigger);

			Remote.saveSettings(null, {
				Theme: value
			});
		});
	}

	onBuild() {
		const currentTheme = ThemeStore.theme();

		this.themesObjects(
			ThemeStore.themes.map(theme => ({
				name: theme,
				nameDisplay: convertThemeName(theme),
				selected: ko.observable(theme === currentTheme),
				themePreviewSrc: themePreviewLink(theme)
			}))
		);

		// initUploader

		if (themeBackground.uploaderButton() && themeBackground.enabled) {
			const oJua = new Jua({
				action: serverRequest('UploadBackground'),
				limit: 1,
				clickElement: themeBackground.uploaderButton()
			});

			oJua
				.on('onStart', () => {
					themeBackground.loading(true);
					themeBackground.error('');
				})
				.on('onComplete', (id, result, data) => {
					themeBackground.loading(false);
					themeBackground.name(data?.Result?.Name || '');
					themeBackground.hash(data?.Result?.Hash || '');
					if (!themeBackground.name() || !themeBackground.hash()) {
						let errorMsg = '';
						if (data.ErrorCode) {
							switch (data.ErrorCode) {
								case UploadErrorCode.FileIsTooBig:
									errorMsg = i18n('SETTINGS_THEMES/ERROR_FILE_IS_TOO_BIG');
									break;
								case UploadErrorCode.FileType:
									errorMsg = i18n('SETTINGS_THEMES/ERROR_FILE_TYPE_ERROR');
									break;
								// no default
							}
						}

						themeBackground.error(errorMsg || data.ErrorMessage || i18n('SETTINGS_THEMES/ERROR_UNKNOWN'));
					}
				});
		}
	}

	onShow() {
		themeBackground.error('');
	}

	clearBackground() {
		if (themeBackground.enabled) {
			Remote.request('ClearUserBackground', () => {
				themeBackground.name('');
				themeBackground.hash('');
			});
		}
	}
}
