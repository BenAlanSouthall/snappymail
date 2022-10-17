import { SettingsGet, SettingsCapa } from 'Common/Globals';
import { addObservablesTo, addSubscribablesTo } from 'External/ko';

import Remote from 'Remote/Admin/Fetch';

import { decorateKoCommands } from 'Knoin/Knoin';
import { AbstractViewSettings } from 'Knoin/AbstractViews';

export class AdminSettingsSecurity extends AbstractViewSettings {
	constructor() {
		super();

		this.addSettings(['UseLocalProxyForExternalImages','VerifySslCertificate','AllowSelfSigned']);

		this.weakPassword = rl.app.weakPassword;

		addObservablesTo(this, {
			adminLogin: SettingsGet('AdminLogin'),
			adminLoginError: false,
			adminPassword: '',
			adminPasswordNew: '',
			adminPasswordNew2: '',
			adminPasswordNewError: false,
			adminTOTP: '',

			saveError: false,
			saveSuccess: false,

			viewQRCode: '',

			capaOpenPGP: SettingsCapa('OpenPGP')
		});

		const reset = () => {
			this.saveError(false);
			this.saveSuccess(false);
			this.adminPasswordNewError(false);
		};

		addSubscribablesTo(this, {
			adminPassword: () => {
				this.saveError(false);
				this.saveSuccess(false);
			},

			adminLogin: () => this.adminLoginError(false),

			adminTOTP: value => {
				if (/[A-Z2-7]{16,}/.test(value) && 0 == value.length * 5 % 8) {
					Remote.request('AdminQRCode', (iError, data) => {
						if (!iError) {
							console.dir({data:data});
							this.viewQRCode(data.Result);
						}
					}, {
						'username': this.adminLogin(),
						'TOTP': this.adminTOTP()
					});
				} else {
					this.viewQRCode('');
				}
			},

			adminPasswordNew: reset,

			adminPasswordNew2: reset,

			capaOpenPGP: value => Remote.saveSetting('CapaOpenPGP', value)
		});

		this.adminTOTP(SettingsGet('AdminTOTP'));

		decorateKoCommands(this, {
			saveAdminUserCommand: self => self.adminLogin().trim() && self.adminPassword()
		});
	}

	saveAdminUserCommand() {
		if (!this.adminLogin().trim()) {
			this.adminLoginError(true);
			return false;
		}

		if (this.adminPasswordNew() !== this.adminPasswordNew2()) {
			this.adminPasswordNewError(true);
			return false;
		}

		this.saveError(false);
		this.saveSuccess(false);

		Remote.request('AdminPasswordUpdate', (iError, data) => {
			if (iError) {
				this.saveError(true);
			} else {
				this.adminPassword('');
				this.adminPasswordNew('');
				this.adminPasswordNew2('');

				this.saveSuccess(true);

				this.weakPassword(!!data.Result.Weak);
			}
		}, {
			'Login': this.adminLogin(),
			'Password': this.adminPassword(),
			'NewPassword': this.adminPasswordNew(),
			'TOTP': this.adminTOTP()
		});

		return true;
	}

	onHide() {
		this.adminPassword('');
		this.adminPasswordNew('');
		this.adminPasswordNew2('');
	}
}
