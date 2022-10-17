import { Notification } from 'Common/Enums';
import { isArray, pInt, pString } from 'Common/Utils';
import { serverRequest } from 'Common/Links';
import { getNotification } from 'Common/Translator';

let iJsonErrorCount = 0;

const getURL = (add = '') => serverRequest('Json') + pString(add),

checkResponseError = data => {
	const err = data ? data.ErrorCode : null;
	if (Notification.InvalidToken === err) {
		alert(getNotification(err));
		rl.logoutReload();
	} else if ([
			Notification.AuthError,
			Notification.ConnectionError,
			Notification.DomainNotAllowed,
			Notification.AccountNotAllowed,
			Notification.MailServerError,
			Notification.UnknownNotification,
			Notification.UnknownError
		].includes(err)
	) {
		if (7 < ++iJsonErrorCount) {
			rl.logoutReload();
		}
	}
},

oRequests = {},

abort = (sAction, sReason, bClearOnly) => {
	if (oRequests[sAction]) {
		if (!bClearOnly && oRequests[sAction].abort) {
//			oRequests[sAction].__aborted = true;
			oRequests[sAction].abort(sReason || 'AbortError');
		}

		oRequests[sAction] = null;
		delete oRequests[sAction];
	}
},

fetchJSON = (action, sGetAdd, params, timeout, jsonCallback) => {
	params = params || {};
	if (params instanceof FormData) {
		params.set('Action', action);
	} else {
		params.Action = action;
	}
	// Don't abort, read https://github.com/the-djmaze/snappymail/issues/487
//	abort(action);
	const controller = new AbortController(),
		signal = controller.signal;
	oRequests[action] = controller;
	// Currently there is no way to combine multiple signals, so AbortSignal.timeout() not possible
	timeout && setTimeout(() => abort(action, 'TimeoutError'), timeout);
	return rl.fetchJSON(getURL(sGetAdd), {signal: signal}, sGetAdd ? null : params).then(jsonCallback).catch(err => {
		err.aborted = signal.aborted;
		err.reason = signal.reason;
		return Promise.reject(err);
	});
};

class FetchError extends Error
{
	constructor(code, message) {
		super(message);
		this.code = code || Notification.JsonFalse;
	}
}

export class AbstractFetchRemote
{
	abort(sAction) {
		abort(sAction);
		return this;
	}

	/**
	 * Allows quicker visual responses to the user.
	 * Can be used to stream lines of json encoded data, but does not work on all servers.
	 * Apache needs 'flushpackets' like in <Proxy "fcgi://...." flushpackets=on></Proxy>
	 */
	streamPerLine(fCallback, sGetAdd, postData) {
		rl.fetch(getURL(sGetAdd), {}, postData)
		.then(response => response.body)
		.then(body => {
			let buffer = '';
			const
				// Firefox TextDecoderStream is not defined
//				reader = body.pipeThrough(new TextDecoderStream()).getReader();
				reader = body.getReader(),
				re = /\r\n|\n|\r/gm,
				utf8decoder = new TextDecoder(),
				processText = ({ done, value }) => {
					buffer += value ? utf8decoder.decode(value, {stream: true}) : '';
					for (;;) {
						let result = re.exec(buffer);
						if (!result) {
							if (done) {
								break;
							}
							reader.read().then(processText);
							return;
						}
						fCallback(buffer.slice(0, result.index));
						buffer = buffer.slice(result.index + 1);
						re.lastIndex = 0;
					}
					// last line didn't end in a newline char
					buffer.length && fCallback(buffer);
				};
			reader.read().then(processText);
		})
	}

	/**
	 * @param {?Function} fCallback
	 * @param {string} sAction
	 * @param {Object=} oParameters
	 * @param {?number=} iTimeout
	 * @param {string=} sGetAdd = ''
	 */
	request(sAction, fCallback, params, iTimeout, sGetAdd, abortActions) {
		params = params || {};

		const start = Date.now();

		abortActions && console.error('abortActions is obsolete');

		fetchJSON(sAction, sGetAdd,
			params,
			undefined === iTimeout ? 30000 : pInt(iTimeout),
			data => {
				let cached = false;
				if (data?.Time) {
					cached = pInt(data.Time) > Date.now() - start;
				}

				let iError = 0;
				if (sAction && oRequests[sAction]) {
					if (oRequests[sAction].__aborted) {
						iError = 2;
					}
					abort(sAction, 0, 1);
				}

				if (!iError && data) {
/*
					if (sAction !== data.Action) {
						console.log(sAction + ' !== ' + data.Action);
					}
*/
					if (data.Result) {
						iJsonErrorCount = 0;
					} else {
						checkResponseError(data);
						iError = data.ErrorCode || Notification.UnknownError
					}
				}

				fCallback && fCallback(
					iError,
					data,
					cached,
					sAction,
					params
				);
			}
		)
		.catch(err => {
			console.error({fetchError:err});
			fCallback && fCallback(
				'TimeoutError' == err.reason ? 3 : (err.name == 'AbortError' ? 2 : 1),
				err
			);
		});
	}

	/**
	 * @param {?Function} fCallback
	 */
	getPublicKey(fCallback) {
		this.request('GetPublicKey', fCallback);
	}

	setTrigger(trigger, value) {
		if (trigger) {
			value = !!value;
			(isArray(trigger) ? trigger : [trigger]).forEach(fTrigger => {
				fTrigger?.(value);
			});
		}
	}

	post(action, fTrigger, params, timeOut) {
		this.setTrigger(fTrigger, true);
		return fetchJSON(action, '', params, pInt(timeOut, 30000),
			data => {
				abort(action, 0, 1);

				if (!data) {
					return Promise.reject(new FetchError(Notification.JsonParse));
				}
/*
				let isCached = false, type = '';
				if (data?.Time) {
					isCached = pInt(data.Time) > microtime() - start;
				}
				// backward capability
				switch (true) {
					case 'success' === textStatus && data?.Result && action === data.Action:
						type = AbstractFetchRemote.SUCCESS;
						break;
					case 'abort' === textStatus && (!data || !data.__aborted__):
						type = AbstractFetchRemote.ABORT;
						break;
					default:
						type = AbstractFetchRemote.ERROR;
						break;
				}
*/
				this.setTrigger(fTrigger, false);

				if (!data.Result || action !== data.Action) {
					checkResponseError(data);
					return Promise.reject(new FetchError(
						data ? data.ErrorCode : 0,
						data ? (data.ErrorMessageAdditional || data.ErrorMessage) : ''
					));
				}

				return data;
			}
		);
	}
}

Object.assign(AbstractFetchRemote.prototype, {
	SUCCESS : 0,
	ERROR : 1,
	ABORT : 2
});
