import { Settings } from 'Common/Globals';
import { i18n } from 'Common/Translator';

import { root } from 'Common/Links';

import { isArray } from 'Common/Utils';
const FormDataToObject = formData => {
	var object = {};
	formData.forEach((value, key) => {
		if (!Reflect.has(object, key)){
			object[key] = value;
		} else {
			isArray(object[key]) || (object[key] = [object[key]]);
			object[key].push(value);
		}
	});
	return object;
};

export default App => {

	rl.app = App;
	rl.logoutReload = App.logoutReload;

	rl.i18n = i18n;

	rl.Enums = {
		StorageResultType: {
			Success: 0,
			Error: 1,
			Abort: 2
		}
	};

	rl.route = {
		root: () => {
			rl.route.off();
			hasher.setHash(root());
		},
		reload: () => {
			rl.route.root();
			setTimeout(() => location.reload(), 100);
		},
		off: () => hasher.active = false,
		on: () => hasher.active = true
	};

	rl.fetch = (resource, init, postData) => {
		init = Object.assign({
			mode: 'same-origin',
			cache: 'no-cache',
			redirect: 'error',
			referrerPolicy: 'no-referrer',
			credentials: 'same-origin',
			headers: {}
		}, init);
		if (postData) {
			init.method = 'POST';
			init.headers['Content-Type'] = 'application/json';
			postData = (postData instanceof FormData) ? FormDataToObject(postData) : postData;
			postData.XToken = Settings.app('token');
			init.body = JSON.stringify(postData);
		}

		return fetch(resource, init);
	};

	rl.fetchJSON = (resource, init, postData) => {
		init = Object.assign({ headers: {} }, init);
		init.headers.Accept = 'application/json';
		return rl.fetch(resource, init, postData).then(response => {
			if (!response.ok) {
				return Promise.reject('Network response error: ' + response.status);
			}
			/* TODO: use this for non-developers?
			response.clone()
			let data = response.text();
			try {
				return JSON.parse(data);
			} catch (e) {
				console.error(e);
//				console.log(data);
				return Promise.reject(Notification.JsonParse);
				return {
					Result: false,
					ErrorCode: 952, // Notification.JsonParse
					ErrorMessage: e.message,
					ErrorMessageAdditional: data
				}
			}
			*/
			return response.json();
		});
	};

};
