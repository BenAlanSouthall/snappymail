import ko from 'ko';

import { SettingsGet } from 'Common/Globals';
import { defaultOptionsAfterRender } from 'Common/Utils';
import { addObservablesTo } from 'External/ko';

import Remote from 'Remote/Admin/Fetch';
import { decorateKoCommands } from 'Knoin/Knoin';
import { AbstractViewSettings } from 'Knoin/AbstractViews';

export class AdminSettingsContacts extends AbstractViewSettings {
	constructor() {
		super();
		this.defaultOptionsAfterRender = defaultOptionsAfterRender;

		this.addSetting('ContactsPdoDsn');
		this.addSetting('ContactsPdoUser');
		this.addSetting('ContactsPdoPassword');
		this.addSetting('ContactsPdoType', () => {
			this.testContactsSuccess(false);
			this.testContactsError(false);
			this.testContactsErrorMessage('');
		});

		this.addSettings(['ContactsEnable','ContactsSync']);

		addObservablesTo(this, {
			testing: false,
			testContactsSuccess: false,
			testContactsError: false,
			testContactsErrorMessage: ''
		});

		const supportedTypes = SettingsGet('supportedPdoDrivers') || [],
			types = [{
				id:'sqlite',
				name:'SQLite'
			},{
				id:'mysql',
				name:'MySQL'
			},{
				id:'pgsql',
				name:'PostgreSQL'
			}].filter(type => supportedTypes.includes(type.id));

		this.contactsSupported = 0 < types.length;

		this.contactsTypesOptions = types;

		this.mainContactsType = ko
			.computed({
				read: this.contactsPdoType,
				write: value => {
					if (value !== this.contactsPdoType()) {
						if (supportedTypes.includes(value)) {
							this.contactsPdoType(value);
						} else if (types.length) {
							this.contactsPdoType('');
						}
					} else {
						this.contactsPdoType.valueHasMutated();
					}
				}
			})
			.extend({ notify: 'always' });

		decorateKoCommands(this, {
			testContactsCommand: self => self.contactsPdoDsn() && self.contactsPdoUser()
		});
	}

	testContactsCommand() {
		this.testContactsSuccess(false);
		this.testContactsError(false);
		this.testContactsErrorMessage('');
		this.testing(true);

		Remote.request('AdminContactsTest',
			(iError, data) => {
				this.testContactsSuccess(false);
				this.testContactsError(false);
				this.testContactsErrorMessage('');

				if (!iError && data.Result.Result) {
					this.testContactsSuccess(true);
				} else {
					this.testContactsError(true);
					this.testContactsErrorMessage(data?.Result?.Message || '');
				}

				this.testing(false);
			}, {
				ContactsPdoType: this.contactsPdoType(),
				ContactsPdoDsn: this.contactsPdoDsn(),
				ContactsPdoUser: this.contactsPdoUser(),
				ContactsPdoPassword: this.contactsPdoPassword()
			}
		);
	}

	onShow() {
		this.testContactsSuccess(false);
		this.testContactsError(false);
		this.testContactsErrorMessage('');
	}
}
