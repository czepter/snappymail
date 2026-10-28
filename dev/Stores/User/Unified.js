import { addComputablesTo } from 'External/ko';
import { isArray } from 'Common/Utils';
import { getFolderFromCacheList, getFolderInboxName } from 'Common/Cache';

import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore } from 'Stores/User/Folder';

const
	sameEmail = (a, b) => IDN.toUnicode(a || '') === IDN.toUnicode(b || '');

export const
	// Folder hash of the unified inbox: #/mailbox/~all
	// '~' is never part of a folder hash, see FolderModel.fullNameHash
	UNIFIED_HASH = '~all',

	/**
	 * The INBOX of all accounts in one message list
	 */
	UnifiedUserStore = {
		/**
		 * Trash, junk and archive folder of each account, by email
		 * as returned by the UnifiedMessageList action
		 */
		folders: {},

		/**
		 * Unread count of the INBOX of the active account
		 * @returns {number}
		 */
		activeInboxUnread: () => {
			// Observe the folder list, the cache itself is not observable
			FolderUserStore.folderList();
			return getFolderFromCacheList(getFolderInboxName())?.unreadEmails() || 0;
		},

		/**
		 * @param {string} email
		 * @returns {?AccountModel}
		 */
		findAccount: email => AccountUserStore.find(account => sameEmail(account.email, email)),

		/**
		 * @param {string} email
		 * @returns {boolean}
		 */
		isActiveAccount: email => sameEmail(AccountUserStore.email(), email),

		/**
		 * Store the account information of the UnifiedMessageList action
		 * @param {Array} accounts
		 */
		setAccounts: accounts => {
			isArray(accounts) && accounts.forEach(info => {
				UnifiedUserStore.folders[IDN.toUnicode(info.email)] = info.folders || {};
				const account = UnifiedUserStore.findAccount(info.email);
				if (account && null != info.unreadEmails && !account.isActive()) {
					account.unreadEmails(info.unreadEmails || null);
				}
			});
		},

		/**
		 * @param {string} email
		 * @param {string} type trash, junk or archive
		 * @returns {string}
		 */
		folder: (email, type) => (UnifiedUserStore.folders[IDN.toUnicode(email || '')] || {})[type] || ''
	};

addComputablesTo(UnifiedUserStore, {
	// Only with more than one account
	enabled: () => 1 < AccountUserStore().length,

	active: () => UNIFIED_HASH === FolderUserStore.currentFolder()?.fullNameHash,

	unreadCount: () => AccountUserStore().reduce((sum, account) =>
		sum + ((account.isActive() ? UnifiedUserStore.activeInboxUnread() : account.unreadEmails()) || 0), 0
	) || null
});
