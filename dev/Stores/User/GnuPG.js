import ko from 'ko';

import { SettingsCapa } from 'Common/Globals';

//import { EmailModel } from 'Model/Email';
//import { OpenPgpKeyModel } from 'Model/OpenPgpKey';

import Remote from 'Remote/User/Fetch';

import { showScreenPopup } from 'Knoin/Knoin';
import { OpenPgpKeyPopupView } from 'View/Popup/OpenPgpKey';
import { AskPopupView } from 'View/Popup/Ask';

const
	askPassphrase = async (privateKey, btnTxt = 'LABEL_SIGN') =>
		await AskPopupView.password('GnuPG key<br>' + privateKey.id + ' ' + privateKey.emails[0], 'OPENPGP/'+btnTxt),

	findGnuPGKey = (keys, query, sign) =>
		keys.find(key =>
			key[sign ? 'can_sign' : 'can_decrypt']
			&& (key.emails.includes(query) || key.subkeys.find(key => query == key.keyid || query == key.fingerprint))
		);

export const GnuPGUserStore = new class {
	constructor() {
		/**
		 * PECL gnupg / PEAR Crypt_GPG
		 * [ {email, can_encrypt, can_sign}, ... ]
		 */
		this.keyring;
		this.publicKeys = ko.observableArray();
		this.privateKeys = ko.observableArray();
	}

	loadKeyrings() {
		this.keyring = null;
		this.publicKeys([]);
		this.privateKeys([]);
		Remote.request('GnupgGetKeys',
			(iError, oData) => {
				if (oData?.Result) {
					this.keyring = oData.Result;
					const initKey = (key, isPrivate) => {
						const aEmails = [];
						key.id = key.subkeys[0].keyid;
						key.fingerprint = key.subkeys[0].fingerprint;
						key.uids.forEach(uid => uid.email && aEmails.push(uid.email));
						key.emails = aEmails;
						key.askDelete = ko.observable(false);
						key.openForDeletion = ko.observable(null).askDeleteHelper();
						key.remove = () => {
							if (key.askDelete()) {
								Remote.request('GnupgDeleteKey',
									(iError, oData) => {
										if (oData) {
											if (iError) {
												alert(oData.ErrorMessage);
											} else if (oData.Result) {
												isPrivate
													? this.privateKeys.remove(key)
													: this.publicKeys.remove(key);
											}
										}
									}, {
										KeyId: key.id,
										isPrivate: isPrivate
									}
								);
							}
						};
						key.view = () => {
							const fetch = pass => Remote.request('GnupgExportKey',
									(iError, oData) => {
										if (oData?.Result) {
											key.armor = oData.Result;
											showScreenPopup(OpenPgpKeyPopupView, [key]);
										}
									}, {
										KeyId: key.id,
										isPrivate: isPrivate,
										Passphrase: pass
									}
								);
							if (isPrivate) {
								askPassphrase(key, 'POPUP_VIEW_TITLE').then(passphrase => {
									(null !== passphrase) && fetch(passphrase);
								});
							} else {
								fetch('');
							}
						};
						return key;
					};
					this.publicKeys(oData.Result.public.map(key => initKey(key, 0)));
					this.privateKeys(oData.Result.private.map(key => initKey(key, 1)));
					console.log('gnupg ready');
				}
			}
		);
	}

	/**
	 * @returns {boolean}
	 */
	isSupported() {
		return SettingsCapa('GnuPG');
	}

	importKey(key, callback) {
		Remote.request('GnupgImportKey',
			(iError, oData) => {
				if (oData?.Result/* && (oData.Result.imported || oData.Result.secretimported)*/) {
					this.loadKeyrings();
				}
				callback?.(iError, oData);
			}, {
				Key: key
			}
		);
	}

	/**
		keyPair.privateKey
		keyPair.publicKey
		keyPair.revocationCertificate
		keyPair.onServer
		keyPair.inGnuPG
	 */
	storeKeyPair(keyPair, callback) {
		Remote.request('PgpStoreKeyPair',
			(iError, oData) => {
				if (oData?.Result) {
//					this.gnupgKeyring = oData.Result;
				}
				callback?.(iError, oData);
			}, keyPair
		);
	}

	/**
	 * Checks if verifying/encrypting a message is possible with given email addresses.
	 */
	hasPublicKeyForEmails(recipients) {
		const count = recipients.length,
			length = count ? recipients.filter(email =>
//				(key.can_verify || key.can_encrypt) &&
				this.publicKeys.find(key => key.emails.includes(email))
			).length : 0;
		return length && length === count;
	}

	getPublicKeyFingerprints(recipients) {
		const fingerprints = [];
		recipients.forEach(email => {
			fingerprints.push(this.publicKeys.find(key => key.emails.includes(email)).fingerprint);
		});
		return fingerprints;
	}

	getPrivateKeyFor(query, sign) {
		return findGnuPGKey(this.privateKeys, query, sign);
	}

	async decrypt(message) {
		const
			pgpInfo = message.pgpEncrypted();
		if (pgpInfo) {
			let ids = [message.to[0].email].concat(pgpInfo.KeyIds),
				i = ids.length, key;
			while (i--) {
				key = findGnuPGKey(this.privateKeys, ids[i]);
				if (key) {
					break;
				}
			}
			if (key) {
				// Also check message.from[0].email
				let params = {
					Folder: message.folder,
					Uid: message.uid,
					PartId: pgpInfo.PartId,
					KeyId: key.id,
					Passphrase: await askPassphrase(key, 'BUTTON_DECRYPT'),
					Data: '' // message.plain() optional
				}
				if (null !== params.Passphrase) {
					const result = await Remote.post('GnupgDecrypt', null, params);
					if (result?.Result) {
						return result.Result;
					}
				}
			}
		}
	}

	async verify(message) {
		let data = message.pgpSigned(); // { BodyPartId: "1", SigPartId: "2", MicAlg: "pgp-sha256" }
		if (data) {
			data = { ...data }; // clone
//			const sender = message.from[0].email;
//			let mode = await this.hasPublicKeyForEmails([sender]);
			data.Folder = message.folder;
			data.Uid = message.uid;
			if (data.BodyPart) {
				data.BodyPart = data.BodyPart.raw;
				data.SigPart = data.SigPart.body;
			}
			let response = await Remote.post('MessagePgpVerify', null, data);
			if (response?.Result) {
				return {
					fingerprint: response.Result.fingerprint,
					success: 0 == response.Result.status // GOODSIG
				};
			}
		}
	}

	async sign(privateKey) {
		return await askPassphrase(privateKey);
	}

};
