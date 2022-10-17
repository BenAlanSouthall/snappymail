import ko from 'ko';

import { MessagePriority } from 'Common/EnumsUser';
import { i18n } from 'Common/Translator';

import { doc, SettingsGet } from 'Common/Globals';
import { encodeHtml, plainToHtml, htmlToPlain, cleanHtml } from 'Common/Html';
import { arrayLength, forEachObjectEntry } from 'Common/Utils';
import { serverRequestRaw, proxy } from 'Common/Links';

import { FolderUserStore, isAllowedKeyword } from 'Stores/User/Folder';
import { SettingsUserStore } from 'Stores/User/Settings';

import { FileInfo } from 'Common/File';
import { AttachmentCollectionModel } from 'Model/AttachmentCollection';
import { EmailCollectionModel } from 'Model/EmailCollection';
import { AbstractModel } from 'Knoin/AbstractModel';

import PreviewHTML from 'Html/PreviewMessage.html';

//import { MessageFlagsCache } from 'Common/Cache';
import Remote from 'Remote/User/Fetch';

const
	hcont = Element.fromHTML('<div area="hidden" style="position:absolute;left:-5000px"></div>'),
	getRealHeight = el => {
		hcont.innerHTML = el.outerHTML;
		const result = hcont.clientHeight;
		hcont.innerHTML = '';
		return result;
	},

	toggleTag = (message, keyword) => {
		const lower = keyword.toLowerCase(),
			flags = message.flags,
			isSet = flags.includes(lower);
		Remote.request('MessageSetKeyword', iError => {
			if (!iError) {
				isSet ? flags.remove(lower) : flags.push(lower);
//				MessageFlagsCache.setFor(message.folder, message.uid, flags());
			}
		}, {
			Folder: message.folder,
			Uids: message.uid,
			Keyword: keyword,
			SetAction: isSet ? 0 : 1
		})
	},

	/**
	 * @param {EmailCollectionModel} emails
	 * @param {Object} unic
	 * @param {Map} localEmails
	 */
	replyHelper = (emails, unic, localEmails) =>
		emails.forEach(email =>
			unic[email.email] || localEmails.has(email.email) || localEmails.set(email.email, email)
		);

doc.body.append(hcont);

export class MessageModel extends AbstractModel {
	constructor() {
		super();

		this._reset();

		this.addObservables({
			subject: '',
			plain: '',
			html: '',
			size: 0,
			spamScore: 0,
			spamResult: '',
			isSpam: false,
			hasVirus: null, // or boolean when scanned
			dateTimeStampInUTC: 0,
			priority: MessagePriority.Normal,

			senderEmailsString: '',
			senderClearEmailsString: '',

			deleted: false,

			// Also used by Selector
			focused: false,
			selected: false,
			checked: false,

			isHtml: false,
			hasImages: false,
			hasExternals: false,

			pgpSigned: null,
			pgpVerified: null,

			pgpEncrypted: null,
			pgpDecrypted: false,

			readReceipt: '',

			hasUnseenSubMessage: false,
			hasFlaggedSubMessage: false
		});

		this.attachments = ko.observableArray(new AttachmentCollectionModel);
		this.threads = ko.observableArray();
		this.unsubsribeLinks = ko.observableArray();
		this.flags = ko.observableArray();

		this.addComputables({
			attachmentIconClass: () => FileInfo.getAttachmentsIconClass(this.attachments()),
			threadsLen: () => this.threads().length,
			listAttachments: () => this.attachments()
				.filter(item => SettingsUserStore.listInlineAttachments() || !item.isLinked()),
			hasAttachments: () => this.listAttachments().length,

			isUnseen: () => !this.flags().includes('\\seen'),
			isFlagged: () => this.flags().includes('\\flagged'),
			isReadReceipt: () => this.flags().includes('$mdnsent'),
//			isJunk: () => this.flags().includes('$junk') && !this.flags().includes('$nonjunk'),
//			isPhishing: () => this.flags().includes('$phishing'),

			tagsToHTML: () => this.flags().map(value =>
					isAllowedKeyword(value)
					? '<span class="focused msgflag-'+value+'">' + i18n('MESSAGE_TAGS/'+value,0,value) + '</span>'
					: ''
				).join(' '),

			tagOptions: () => {
				const tagOptions = [];
				FolderUserStore.currentFolder().permanentFlags.forEach(value => {
					if (isAllowedKeyword(value)) {
						let lower = value.toLowerCase();
						tagOptions.push({
							css: 'msgflag-' + lower,
							value: value,
							checked: this.flags().includes(lower),
							label: i18n('MESSAGE_TAGS/'+lower, 0, value),
							toggle: (/*obj*/) => toggleTag(this, value)
						});
					}
				});
				return tagOptions
			}

		});
	}

	toggleTag(keyword) {
		toggleTag(this, keyword);
	}

	_reset() {
		this.folder = '';
		this.uid = 0;
		this.hash = '';
		this.requestHash = '';
		this.emails = [];
		this.from = new EmailCollectionModel;
		this.to = new EmailCollectionModel;
		this.cc = new EmailCollectionModel;
		this.bcc = new EmailCollectionModel;
		this.replyTo = new EmailCollectionModel;
		this.deliveredTo = new EmailCollectionModel;
		this.body = null;
		this.draftInfo = [];
		this.messageId = '';
		this.inReplyTo = '';
		this.references = '';
	}

	clear() {
		this._reset();
		this.subject('');
		this.html('');
		this.plain('');
		this.size(0);
		this.spamScore(0);
		this.spamResult('');
		this.isSpam(false);
		this.hasVirus(null);
		this.dateTimeStampInUTC(0);
		this.priority(MessagePriority.Normal);

		this.senderEmailsString('');
		this.senderClearEmailsString('');

		this.deleted(false);

		this.selected(false);
		this.checked(false);

		this.isHtml(false);
		this.hasImages(false);
		this.hasExternals(false);
		this.attachments(new AttachmentCollectionModel);

		this.pgpSigned(null);
		this.pgpVerified(null);

		this.pgpEncrypted(null);
		this.pgpDecrypted(false);

		this.priority(MessagePriority.Normal);
		this.readReceipt('');

		this.threads([]);
		this.unsubsribeLinks([]);

		this.hasUnseenSubMessage(false);
		this.hasFlaggedSubMessage(false);
	}

	spamStatus() {
		let spam = this.spamResult();
		return spam ? i18n(this.isSpam() ? 'GLOBAL/SPAM' : 'GLOBAL/NOT_SPAM') + ': ' + spam : '';
	}

	/**
	 * @returns {string}
	 */
	friendlySize() {
		return FileInfo.friendlySize(this.size());
	}

	computeSenderEmail() {
		const list = this[
			[FolderUserStore.sentFolder(), FolderUserStore.draftsFolder()].includes(this.folder) ? 'to' : 'from'
		];
		this.senderEmailsString(list.toString(true));
		this.senderClearEmailsString(list.map(email => email?.email).filter(email => email).join(', '));
	}

	/**
	 * @param {FetchJsonMessage} json
	 * @returns {boolean}
	 */
	revivePropertiesFromJson(json) {
		if ('Priority' in json && ![MessagePriority.High, MessagePriority.Low].includes(json.Priority)) {
			json.Priority = MessagePriority.Normal;
		}
		if (super.revivePropertiesFromJson(json)) {
//			this.foundCIDs = isArray(json.FoundCIDs) ? json.FoundCIDs : [];
//			this.attachments(AttachmentCollectionModel.reviveFromJson(json.Attachments, this.foundCIDs));

			this.computeSenderEmail();
		}
	}

	/**
	 * @returns {boolean}
	 */
	hasUnsubsribeLinks() {
		return this.unsubsribeLinks().length;
	}

	/**
	 * @returns {string}
	 */
	getFirstUnsubsribeLink() {
		return this.unsubsribeLinks()[0] || '';
	}

	/**
	 * @param {boolean} friendlyView
	 * @param {boolean=} wrapWithLink
	 * @returns {string}
	 */
	fromToLine(friendlyView, wrapWithLink) {
		return this.from.toString(friendlyView, wrapWithLink);
	}

	/**
	 * @returns {string}
	 */
	fromDkimData() {
		let result = ['none', ''];
		if (1 === arrayLength(this.from) && this.from[0]?.dkimStatus) {
			result = [this.from[0].dkimStatus, this.from[0].dkimValue || ''];
		}

		return result;
	}

	/**
	 * @param {boolean} friendlyView
	 * @param {boolean=} wrapWithLink
	 * @returns {string}
	 */
	toToLine(friendlyView, wrapWithLink) {
		return this.to.toString(friendlyView, wrapWithLink);
	}

	/**
	 * @param {boolean} friendlyView
	 * @param {boolean=} wrapWithLink
	 * @returns {string}
	 */
	ccToLine(friendlyView, wrapWithLink) {
		return this.cc.toString(friendlyView, wrapWithLink);
	}

	/**
	 * @returns {string}
	 */
	bccToLine() {
		return this.bcc.toString();
	}

	/**
	 * @returns {string}
	 */
	replyToToLine() {
		return this.replyTo.toString();
	}

	/**
	 * @return string
	 */
	lineAsCss(flags=1) {
		let classes = [];
		forEachObjectEntry({
			deleted: this.deleted(),
			selected: this.selected(),
			checked: this.checked(),
			unseen: this.isUnseen(),
			focused: this.focused(),
			priorityHigh: this.priority() === MessagePriority.High,
			withAttachments: !!this.attachments().length,
			// hasChildrenMessage: 1 < this.threadsLen(),
			hasUnseenSubMessage: this.hasUnseenSubMessage(),
			hasFlaggedSubMessage: this.hasFlaggedSubMessage()
		}, (key, value) => value && classes.push(key));
		flags && this.flags().forEach(value => classes.push('msgflag-'+value));
		return classes.join(' ');
	}

	/**
	 * @returns {string}
	 */
	viewLink() {
		return serverRequestRaw('ViewAsPlain', this.requestHash);
	}

	/**
	 * @returns {string}
	 */
	downloadLink() {
		return serverRequestRaw('Download', this.requestHash);
	}

	/**
	 * @param {Object} excludeEmails
	 * @returns {Array}
	 */
	replyEmails(excludeEmails) {
		const
			result = new Map(),
			unic = excludeEmails || {};
		replyHelper(this.replyTo, unic, result);
		result.size || replyHelper(this.from, unic, result);
		return result.size ? [...result.values()] : [this.to[0]];
	}

	/**
	 * @param {Object} excludeEmails
	 * @returns {Array.<Array>}
	 */
	replyAllEmails(excludeEmails) {
		const
			toResult = new Map(),
			ccResult = new Map(),
			unic = excludeEmails || {};

		replyHelper(this.replyTo, unic, toResult);
		toResult.size || replyHelper(this.from, unic, toResult);

		replyHelper(this.to, unic, toResult);

		replyHelper(this.cc, unic, ccResult);

		return [[...toResult.values()], [...ccResult.values()]];
	}

	viewHtml() {
		const body = this.body;
		if (body && this.html()) {
			let result = cleanHtml(this.html(), this.attachments());
			this.hasExternals(result.hasExternals);
			this.hasImages(body.rlHasImages = !!result.hasExternals);

			body.innerHTML = result.html;

			body.classList.toggle('html', 1);
			body.classList.toggle('plain', 0);

			if (SettingsUserStore.showImages()) {
				this.showExternalImages();
			}

			this.isHtml(true);
			this.initView();
			return true;
		}
	}

	viewPlain() {
		const body = this.body;
		if (body) {
			body.classList.toggle('html', 0);
			body.classList.toggle('plain', 1);
			body.innerHTML = plainToHtml(
				(this.plain()
					? this.plain()
						.replace(/-----BEGIN PGP (SIGNED MESSAGE-----(\r?\n[a-z][^\r\n]+)+|SIGNATURE-----[\s\S]*)/, '')
						.trim()
					: htmlToPlain(body.innerHTML)
				)
			);
			this.isHtml(false);
			this.hasImages(false);
			this.initView();
			return true;
		}
	}

	initView() {
		// init BlockquoteSwitcher
		this.body.querySelectorAll('blockquote').forEach(node => {
			if (node.textContent.trim()) {
				let h = node.clientHeight || getRealHeight(node);
				if (0 === h || 100 < h) {
					const el = Element.fromHTML('<details class="sm-bq-switcher"><summary>•••</summary></details>');
					node.replaceWith(el);
					el.append(node);
				}
			}
		});
	}

	viewPopupMessage(print) {
		const timeStampInUTC = this.dateTimeStampInUTC() || 0,
			ccLine = this.ccToLine(),
			m = 0 < timeStampInUTC ? new Date(timeStampInUTC * 1000) : null,
			win = open(''),
			sdoc = win.document;
		let subject = encodeHtml(this.subject()),
			mode = this.isHtml() ? 'div' : 'pre',
			cc = ccLine ? `<div>${encodeHtml(i18n('GLOBAL/CC'))}: ${encodeHtml(ccLine)}</div>` : '',
			style = getComputedStyle(doc.querySelector('.messageView')),
			prop = property => style.getPropertyValue(property);
		sdoc.write(PreviewHTML
			.replace('<title>', '<title>'+subject)
			// eslint-disable-next-line max-len
			.replace('<body>', `<body style="background-color:${prop('background-color')};color:${prop('color')}"><header><h1>${subject}</h1><time>${encodeHtml(m ? m.format('LLL') : '')}</time><div>${encodeHtml(this.fromToLine())}</div><div>${encodeHtml(i18n('GLOBAL/TO'))}: ${encodeHtml(this.toToLine())}</div>${cc}</header><${mode}>${this.bodyAsHTML()}</${mode}>`)
		);
		sdoc.close();

		if (print) {
			setTimeout(() => win.print(), 100);
		}
	}

	/**
	 * @param {boolean=} print = false
	 */
	popupMessage() {
		this.viewPopupMessage();
	}

	printMessage() {
		this.viewPopupMessage(true);
	}

	/**
	 * @returns {string}
	 */
	generateUid() {
		return this.folder + '/' + this.uid;
	}

	/**
	 * @param {MessageModel} message
	 * @returns {MessageModel}
	 */
	static fromMessageListItem(message) {
		let self = new MessageModel();

		if (message) {
			self.folder = message.folder;
			self.uid = message.uid;
			self.hash = message.hash;
			self.requestHash = message.requestHash;
			self.subject(message.subject());
			self.plain(message.plain());
			self.html(message.html());

			self.size(message.size());
			self.spamScore(message.spamScore());
			self.spamResult(message.spamResult());
			self.isSpam(message.isSpam());
			self.hasVirus(message.hasVirus());
			self.dateTimeStampInUTC(message.dateTimeStampInUTC());
			self.priority(message.priority());

			self.hasExternals(message.hasExternals());

			self.emails = message.emails;

			self.from = message.from;
			self.to = message.to;
			self.cc = message.cc;
			self.bcc = message.bcc;
			self.replyTo = message.replyTo;
			self.deliveredTo = message.deliveredTo;
			self.unsubsribeLinks(message.unsubsribeLinks);

			self.flags(message.flags());

			self.priority(message.priority());

			self.selected(message.selected());
			self.checked(message.checked());
			self.attachments(message.attachments());

			self.threads(message.threads());
		}

		self.computeSenderEmail();

		return self;
	}

	showExternalImages() {
		const body = this.body;
		if (body && this.hasImages()) {
			this.hasImages(false);
			body.rlHasImages = false;

			let attr = 'data-x-src',
				src, useProxy = !!SettingsGet('UseLocalProxyForExternalImages');
			body.querySelectorAll('img[' + attr + ']').forEach(node => {
				src = node.getAttribute(attr);
				node.src = useProxy ? proxy(src) : src;
			});

			body.querySelectorAll('[data-x-style-url]').forEach(node => {
				JSON.parse(node.dataset.xStyleUrl).forEach(data =>
					node.style[data[0]] = "url('" + (useProxy ? proxy(data[1]) : data[1]) + "')"
				);
			});
		}
	}

	/**
	 * @returns {string}
	 */
	bodyAsHTML() {
		if (this.body) {
			let clone = this.body.cloneNode(true);
			clone.querySelectorAll('.sm-bq-switcher').forEach(
				node => node.replaceWith(node.lastElementChild)
			);
			return clone.innerHTML;
		}
		let result = cleanHtml(this.html(), this.attachments())
		return result.html || plainToHtml(this.plain());
	}

}
