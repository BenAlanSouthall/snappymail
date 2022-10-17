import ko from 'ko';
import Remote from 'Remote/Admin/Fetch';

export const DomainAdminStore = ko.observableArray();

DomainAdminStore.loading = ko.observable(false);

DomainAdminStore.fetch = () => {
	DomainAdminStore.loading(true);
	Remote.request('AdminDomainList',
		(iError, data) => {
			DomainAdminStore.loading(false);
			if (!iError) {
				DomainAdminStore(
					data.Result.map(item => {
						item.disabled = ko.observable(item.disabled);
						item.askDelete = ko.observable(false);
						return item;
					})
				);
			}
		}, {
			IncludeAliases: 1
		});
};
