import { SettingsGet } from 'Common/Globals';
import { forEachObjectEntry } from 'Common/Utils';
import { mailBox } from 'Common/Links';
import { getNotification } from 'Common/Translator';
import { getFolderInboxName } from 'Common/Cache';
import { setRefreshFoldersInterval } from 'Common/Folders';
import { loadAccountsAndIdentities } from 'Common/UtilsUser';

import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore } from 'Stores/User/Folder';
import { MessageUserStore } from 'Stores/User/Message';
import { MessagelistUserStore } from 'Stores/User/Messagelist';
import { SettingsUserStore } from 'Stores/User/Settings';
import { PgpUserStore } from 'Stores/User/Pgp';
import { SMimeUserStore } from 'Stores/User/SMime';
import { UnifiedUserStore } from 'Stores/User/Unified';

import { loadFolders } from 'Model/FolderCollection';

import Remote from 'Remote/User/Fetch';

const
	/**
	 * Show the hash, also when it is the current one (then the folder must be loaded again)
	 * @param {string} hash
	 */
	showHash = hash => {
		rl.route.off();
		hasher.setHash(hash);
		rl.route.on();
		hasher.init();
	};

export const
	/**
	 * Switch the active account without reloading the page
	 * @param {string} email
	 * @param {Object=} options
	 *     folder: fullNameHash of the folder to open, default the INBOX
	 *     route: false to stay at the current route (the unified inbox)
	 *     onError: called when the switch failed
	 * @param {Function=} fCallback Called when the account is active and its folders are loaded
	 */
	switchAccount = (email, options, fCallback) => {
		options = options || {};
		if (UnifiedUserStore.isActiveAccount(email)) {
			fCallback?.();
			return;
		}
		// The unified inbox has all emails in unicode
		email = UnifiedUserStore.findAccount(email)?.email || email;
		if (!email || AccountUserStore.loading()) {
			return;
		}

		AccountUserStore.loading(true);
		Remote.request('AccountSwitch', (iError, oData) => {
			if (iError) {
				AccountUserStore.loading(false);
				alert(getNotification(iError).replace('%EMAIL%', email));
				options.onError?.(iError);
				return;
			}

			// Keep the INBOX unread count of the account we leave
			AccountUserStore.find(account => account.isActive())
				?.unreadEmails(UnifiedUserStore.activeInboxUnread() || null);

			// Account specific settings, see getAccountData()
			const { token, ...data } = oData.Result;
			forEachObjectEntry(data, (key, value) => rl.settings.set(key, value));
			// The request token depends on the active account
			rl.settings.get('System').token = token;
			SettingsUserStore.init();

			// Mail bodies and messages are cached by folder and uid, which can exist in both accounts
			MessageUserStore.message(null);
			MessageUserStore.clearBodies();
			const unified = UnifiedUserStore.active();
			unified || MessagelistUserStore([]);
			FolderUserStore.foldersInboxUnreadCount(0);

			AccountUserStore.email(SettingsGet('Email'));

			loadFolders((success, error) => {
				AccountUserStore.loading(false);
				if (!success) {
					console.error(error);
					// Fallback to the old behaviour
					rl.route.reload();
					return;
				}

				setRefreshFoldersInterval(SettingsGet('CheckMailInterval'));
				PgpUserStore.loadKeyrings();
				SMimeUserStore.loadCertificates();

				if (false !== options.route && !unified) {
					showHash(mailBox(options.folder || getFolderInboxName()));
				}
				// Compose needs the identities of the account
				loadAccountsAndIdentities(true, fCallback);
			});
		}, {Email: email});
	};
