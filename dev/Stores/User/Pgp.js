import { SettingsCapa, SettingsGet } from 'Common/Globals';
//import { staticLink } from 'Common/Links';

//import { showScreenPopup } from 'Knoin/Knoin';

//import { EmailModel } from 'Model/Email';
//import { OpenPgpKeyModel } from 'Model/OpenPgpKey';

import { GnuPGUserStore } from 'Stores/User/GnuPG';
import { OpenPGPUserStore } from 'Stores/User/OpenPGP';

export const
	BEGIN_PGP_MESSAGE = '-----BEGIN PGP MESSAGE-----',
//	BEGIN_PGP_SIGNATURE = '-----BEGIN PGP SIGNATURE-----',
//	BEGIN_PGP_SIGNED = '-----BEGIN PGP SIGNED MESSAGE-----',

	PgpUserStore = new class {
		constructor() {
			// https://mailvelope.github.io/mailvelope/Keyring.html
			this.mailvelopeKeyring = null;
		}

		init() {
			if (SettingsCapa('OpenPGP') && window.crypto && crypto.getRandomValues) {
				rl.loadScript(SettingsGet('StaticLibsJs').replace('/libs.', '/openpgp.'))
//				rl.loadScript(staticLink('js/min/openpgp.min.js'))
					.then(() => this.loadKeyrings())
					.catch(e => {
						this.loadKeyrings();
						console.error(e);
					});
			} else {
				this.loadKeyrings();
			}
		}

		loadKeyrings(identifier) {
			identifier = identifier || SettingsGet('Email');
			if (window.mailvelope) {
				const fn = keyring => {
						this.mailvelopeKeyring = keyring;
						console.log('mailvelope ready');
					};
				mailvelope.getKeyring().then(fn, err => {
					if (identifier) {
						// attempt to create a new keyring for this app/user
						mailvelope.createKeyring(identifier).then(fn, err => console.error(err));
					} else {
						console.error(err);
					}
				});
				addEventListener('mailvelope-disconnect', event => {
					alert('Mailvelope is updated to version ' + event.detail.version + '. Reload page');
				}, false);
			} else {
				addEventListener('mailvelope', () => this.loadKeyrings(identifier));
			}

			if (OpenPGPUserStore.isSupported()) {
				OpenPGPUserStore.loadKeyrings();
			}

			if (SettingsCapa('GnuPG')) {
				GnuPGUserStore.loadKeyrings();
			}
		}

		/**
		 * @returns {boolean}
		 */
		isSupported() {
			return !!(OpenPGPUserStore.isSupported() || GnuPGUserStore.isSupported() || window.mailvelope);
		}

		/**
		 * @returns {boolean}
		 */
		isEncrypted(text) {
			return 0 === text.trim().indexOf(BEGIN_PGP_MESSAGE);
		}

		async mailvelopeHasPublicKeyForEmails(recipients) {
			const
				keyring = this.mailvelopeKeyring,
				mailvelope = keyring && await keyring.validKeyForAddress(recipients)
					/*.then(LookupResult => Object.entries(LookupResult))*/,
				entries = mailvelope && Object.entries(mailvelope);
			return entries && entries.filter(value => value[1]).length === recipients.length;
		}

		/**
		 * Checks if verifying/encrypting a message is possible with given email addresses.
		 * Returns the first library that can.
		 */
		async hasPublicKeyForEmails(recipients) {
			const count = recipients.length;
			if (count) {
				if (GnuPGUserStore.hasPublicKeyForEmails(recipients)) {
					return 'gnupg';
				}
				if (OpenPGPUserStore.hasPublicKeyForEmails(recipients)) {
					return 'openpgp';
				}
			}
			return false;
		}

		async getMailvelopePrivateKeyFor(email/*, sign*/) {
			let keyring = this.mailvelopeKeyring;
			if (keyring && await keyring.hasPrivateKey({email:email})) {
				return ['mailvelope', email];
			}
			return false;
		}

		/**
		 * Checks if signing a message is possible with given email address.
		 * Returns the first library that can.
		 */
		async getKeyForSigning(email) {
			let key = OpenPGPUserStore.getPrivateKeyFor(email, 1);
			if (key) {
				return ['openpgp', key];
			}

			key = GnuPGUserStore.getPrivateKeyFor(email, 1);
			if (key) {
				return ['gnupg', key];
			}

	//		return await this.getMailvelopePrivateKeyFor(email, 1);
		}

		async decrypt(message) {
			const sender = message.from[0].email,
				armoredText = message.plain();

			if (!this.isEncrypted(armoredText)) {
				return;
			}

			// Try OpenPGP.js
			let result = await OpenPGPUserStore.decrypt(armoredText, sender);
			if (result) {
				return result;
			}

			// Try Mailvelope (does not support inline images)
			try {
				let key = await this.getMailvelopePrivateKeyFor(message.to[0].email);
				if (key) {
					/**
					* https://mailvelope.github.io/mailvelope/Mailvelope.html#createEncryptedFormContainer
					* Creates an iframe to display an encrypted form
					*/
	//				mailvelope.createEncryptedFormContainer('#mailvelope-form');
					/**
					* https://mailvelope.github.io/mailvelope/Mailvelope.html#createDisplayContainer
					* Creates an iframe to display the decrypted content of the encrypted mail.
					*/
					const body = message.body;
					body.textContent = '';
					result = await mailvelope.createDisplayContainer(
						'#'+body.id,
						armoredText,
						this.mailvelopeKeyring,
						{
							senderAddress: sender
						}
					);
					if (result) {
						if (result.error?.message) {
							if ('PWD_DIALOG_CANCEL' !== result.error.code) {
								alert(result.error.code + ': ' + result.error.message);
							}
						} else {
							body.classList.add('mailvelope');
							return true;
						}
					}
				}
			} catch (err) {
				console.error(err);
			}

			// Now try GnuPG
			return GnuPGUserStore.decrypt(message);
		}

		async verify(message) {
			const signed = message.pgpSigned();
			if (signed) {
				const sender = message.from[0].email,
					gnupg = GnuPGUserStore.hasPublicKeyForEmails([sender]),
					openpgp = OpenPGPUserStore.hasPublicKeyForEmails([sender]);
				// Detached signature use GnuPG first, else we must download whole message
				if (gnupg && signed.SigPartId) {
					return GnuPGUserStore.verify(message);
				}
				if (openpgp) {
					return OpenPGPUserStore.verify(message);
				}
				if (gnupg) {
					return GnuPGUserStore.verify(message);
				}
				// Mailvelope can't
				// https://github.com/mailvelope/mailvelope/issues/434
			}
		}

		/**
		 * Returns headers that should be added to an outgoing email.
		 * So far this is only the autocrypt header.
		 */
	/*
		this.mailvelopeKeyring.additionalHeadersForOutgoingEmail(headers)
		this.mailvelopeKeyring.addSyncHandler(syncHandlerObj)
		this.mailvelopeKeyring.createKeyBackupContainer(selector, options)
		this.mailvelopeKeyring.createKeyGenContainer(selector, {
	//		userIds: [],
			keySize: 4096
		})

		this.mailvelopeKeyring.exportOwnPublicKey(emailAddr).then(<AsciiArmored, Error>)
		this.mailvelopeKeyring.importPublicKey(armored)

		// https://mailvelope.github.io/mailvelope/global.html#SyncHandlerObject
		this.mailvelopeKeyring.addSyncHandler({
			uploadSync
			downloadSync
			backup
			restore
		});
	*/

	};
