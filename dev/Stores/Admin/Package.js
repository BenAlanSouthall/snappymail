import ko from 'ko';
import { isArray } from 'Common/Utils';
import Remote from 'Remote/Admin/Fetch';

export const PackageAdminStore = ko.observableArray();

PackageAdminStore.real = ko.observable(true);

PackageAdminStore.loading = ko.observable(false);

PackageAdminStore.error = ko.observable('');

PackageAdminStore.fetch = () => {
	PackageAdminStore.loading(true);
	Remote.request('AdminPackagesList', (iError, data) => {
		PackageAdminStore.loading(false);
		if (iError) {
			PackageAdminStore.real(false);
		} else {
			PackageAdminStore.real(!!data.Result.Real);
			PackageAdminStore.error(data.Result.Error);

			const loading = {};
			PackageAdminStore.forEach(item => {
				if (item?.loading()) {
					loading[item.file] = item;
				}
			});

			let list = [];
			if (isArray(data.Result.List)) {
				list = data.Result.List.filter(v => v).map(item => {
					item.loading = ko.observable(loading[item.file] !== undefined);
					item.enabled = ko.observable(item.enabled);
					return item;
				});
			}

			PackageAdminStore(list);
		}
	});
};
