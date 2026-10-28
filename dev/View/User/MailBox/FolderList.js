import ko from 'ko';

import { ScopeFolderList, ScopeMessageList } from 'Common/Enums';
import { addShortcut, leftPanelDisabled, stopEvent } from 'Common/Globals';
import { mailBox, settings } from 'Common/Links';
import { b64EncodeJSONSafe } from 'Common/Utils';
import { i18n, getNotification } from 'Common/Translator';
//import { setFolderETag } from 'Common/Cache';
import { addComputablesTo } from 'External/ko';

import { AppUserStore } from 'Stores/User/App';
import { SettingsUserStore } from 'Stores/User/Settings';
import { FolderUserStore } from 'Stores/User/Folder';
import { MessageUserStore } from 'Stores/User/Message';
import { MessagelistUserStore } from 'Stores/User/Messagelist';
import { AccountUserStore } from 'Stores/User/Account';

import Remote from 'Remote/User/Fetch';

import { showScreenPopup } from 'Knoin/Knoin';
import { AbstractViewLeft } from 'Knoin/AbstractViews';

import { showMessageComposer, moveAction } from 'Common/UtilsUser';
import { FolderCreatePopupView } from 'View/Popup/FolderCreate';
import { ContactsPopupView } from 'View/Popup/Contacts';
import { ComposePopupView } from 'View/Popup/Compose';

import { setExpandedFolder, foldersFilter } from 'Model/FolderCollection';
import { ThemeStore } from '../../../Stores/Theme';

// Stand-in when there is only one account, renders the folders without account header
const singleAccount = {
	email: '',
	isActive: () => true,
	expanded: () => true,
	foldersLoading: () => false,
	foldersError: () => false
};

export class MailFolderList extends AbstractViewLeft {
	constructor() {
		super();

//		this.oContentScrollable = null;

		this.composeInEdit = ComposePopupView.inEdit;

		this.systemFolders = FolderUserStore.systemFolders;

		this.moveAction = moveAction;

		this.allowContacts = AppUserStore.allowContacts();

		this.foldersFilter = foldersFilter;

		this.filterUnseen = ko.observable(false);

		addComputablesTo(this, {
			foldersFilterVisible: () => 20 < FolderUserStore.folderList().CountRec,

			folderListVisible: () => {
				let result = FolderUserStore.folderList().visible();
				return 1 === result.length && result[0].isInbox() ? result[0].visibleSubfolders() : result;
			},

			// Show all accounts in the folder panel, each one collapsible
			multiAccount: () => 1 < AccountUserStore().length,

			accountList: () => this.multiAccount() ? AccountUserStore() : [singleAccount],

			toggleAllAccountsLabel: () => i18n(AccountUserStore().some(account => account.expanded())
				? 'FOLDER_LIST/COLLAPSE_ALL' : 'FOLDER_LIST/EXPAND_ALL'),

			// Translate nodes that knockout renders after the view was shown
			i18nTrigger: () => [this.multiAccount(), AccountUserStore().map(account =>
				[account.expanded(), account.foldersLoading(), account.foldersError()]
			)]
		});
	}

	toggleAllAccounts() {
		const expand = !AccountUserStore().some(account => account.expanded());
		AccountUserStore().forEach(account => account.setExpanded(expand));
	}

	/**
	 * Switch to another account and open the given folder
	 */
	openAccountFolder(account, fullName) {
		if (AccountUserStore.loading()) {
			return;
		}
		AccountUserStore.loading(true);
		Remote.request('AccountSwitch', iError => {
			if (iError) {
				AccountUserStore.loading(false);
				alert(getNotification(iError).replace('%EMAIL%', account.email));
			} else {
				rl.route.off();
				hasher.setHash(mailBox(fullName.replace(/[^a-z0-9._-]+/giu, b64EncodeJSONSafe)));
				setTimeout(() => location.reload(), 100);
			}
		}, {Email: account.email});
	}

	onBuild(dom) {
		const qs = s => dom.querySelector(s),
			eqs = (ev, s) => ev.target.closestWithin(s, dom);

		this.oContentScrollable = qs('.b-content');

		dom.addEventListener('click', event => {
			let el = eqs(event, '.b-account-header');
			if (el) {
				const account = ko.dataFor(el);
				account?.setExpanded(!account.expanded());
				stopEvent(event);
				return;
			}

			el = eqs(event, '.b-account-folder');
			if (el) {
				const folder = ko.dataFor(el),
					account = ko.dataFor(el.closest('.b-account'));
				folder?.selectable && account && this.openAccountFolder(account, folder.fullName);
				stopEvent(event);
				return;
			}

			el = eqs(event, '.e-collapsed-sign');
			if (el) {
				const folder = ko.dataFor(el);
				if (folder) {
					const collapsed = folder.collapsed();
					setExpandedFolder(folder.fullName, collapsed);

					folder.collapsed(!collapsed);
					stopEvent(event);
					return;
				}
			}

			el = eqs(event, 'a');
			if (el?.matches('.selectable')) {
				event.preventDefault();
				const folder = ko.dataFor(el);
				if (folder) {
					if (moveAction()) {
						const copy = event.ctrlKey || 2 === moveAction(),
							messages = MessagelistUserStore.listCheckedOrSelectedUidsWithSubMails();
						moveAction(0);
						messages.size && MessagelistUserStore.moveMessages(
							messages.folder,
							messages,
							folder.fullName,
							copy
						);
					} else {
						if (!SettingsUserStore.usePreviewPane()) {
							MessageUserStore.message(null);
						}
/*
						if (folder.fullName === FolderUserStore.currentFolderFullName()) {
							setFolderETag(folder.fullName, '');
						}
*/
						let search = '';
						if (event.target.matches('.flag-icon') && !folder.isFlagged()) {
							search = 'flagged';
						} else if (folder.unreadCount() && event.clientX > el.getBoundingClientRect().right - 25) {
							search = 'unseen';
						}
						hasher.setHash(mailBox(folder.fullNameHash, 1, search));

						// in mobile mode hide the panel when a folder is clicked
						ThemeStore.isMobile() && leftPanelDisabled(true);
					}

					AppUserStore.focusedState(ScopeMessageList);
				}
			}
		});

		addShortcut('arrowup,arrowdown', '', ScopeFolderList, event => {
			let items = [], index = 0;
			dom.querySelectorAll('li a').forEach(node => {
				if (node.offsetHeight || node.getClientRects().length) {
					items.push(node);
					if (node.matches('.focused')) {
						node.classList.remove('focused');
						index = items.length - 1;
					}
				}
			});
			if (items.length) {
				if ('ArrowUp' === event.key) {
					index && --index;
				} else if (index < items.length - 1) {
					++index;
				}
				items[index].classList.add('focused');
				this.scrollToFocused();
			}

			return false;
		});

		addShortcut('enter,open', '', ScopeFolderList, () => {
			const item = qs('li a.focused');
			if (item) {
				AppUserStore.focusedState(ScopeMessageList);
				item.click();
			}

			return false;
		});

		addShortcut('space', '', ScopeFolderList, () => {
			const item = qs('li a.focused'),
				folder = item && ko.dataFor(item);
			// Folders of other accounts have no collapsed state
			if (folder?.collapsed) {
				const collapsed = folder.collapsed();
				setExpandedFolder(folder.fullName, collapsed);
				folder.collapsed(!collapsed);
			}

			return false;
		});

//		addShortcut('tab', 'shift', ScopeFolderList, () => {
		addShortcut('escape,tab,arrowright', '', ScopeFolderList, () => {
			AppUserStore.focusedState(ScopeMessageList);
			moveAction(0);
			return false;
		});
	}

	scrollToFocused() {
		const scrollable = this.oContentScrollable;
		if (scrollable) {
			let block, focused = scrollable.querySelector('li a.focused');
			if (focused) {
				const fRect = focused.getBoundingClientRect(),
					sRect = scrollable.getBoundingClientRect();
				if (fRect.top < sRect.top) {
					block = 'start';
				} else if (fRect.bottom > sRect.bottom) {
					block = 'end';
				}
				block && focused.scrollIntoView(block === 'start');
			}
		}
	}

	composeClick() {
		showMessageComposer();
	}

	clearFolderSearch() {
		foldersFilter('');
	}

	createFolder() {
		showScreenPopup(FolderCreatePopupView);
	}

	configureFolders() {
		hasher.setHash(settings('folders'));
	}

	contactsClick() {
		if (this.allowContacts) {
			showScreenPopup(ContactsPopupView);
		}
	}
}
