import { AbstractModel } from 'Knoin/AbstractModel';
import { addObservablesTo, addComputablesTo } from 'External/ko';
import Remote from 'Remote/User/Fetch';
import { SettingsUserStore } from 'Stores/User/Settings';
import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore } from 'Stores/User/Folder';
import { ClientSideKeyNameExpandedAccounts } from 'Common/EnumsUser';
import { i18n } from 'Common/Translator';
import { isArray } from 'Common/Utils';
import * as Local from 'Storage/Client';

const
	// Same order as the system folders of the active account
	roleOrder = ['inbox', 'drafts', 'sent', 'archive', 'junk', 'trash'],
	roleNames = {
		inbox: 'FOLDER_LIST/INBOX_NAME',
		drafts: 'FOLDER_LIST/DRAFTS_NAME',
		sent: 'FOLDER_LIST/SENT_NAME',
		archive: 'FOLDER_LIST/ARCHIVE_NAME',
		junk: 'GLOBAL/SPAM',
		trash: 'FOLDER_LIST/TRASH_NAME'
	},
	rolePos = role => {
		const i = roleOrder.indexOf(role);
		return 0 > i ? roleOrder.length : i;
	},

	expandedAccounts = () => {
		const value = Local.get(ClientSideKeyNameExpandedAccounts);
		return value && 'object' === typeof value ? value : {};
	};

export class AccountModel extends AbstractModel {
	/**
	 * @param {string} email
	 * @param {boolean=} canBeDelete = true
	 * @param {number=} count = 0
	 */
	constructor(email, name, isAdditional = true) {
		super();

		this.name = name;
		this.email = email;

		this.displayName = name ? name + ' <' + email + '>' : email;

		const stored = expandedAccounts()[email];

		addObservablesTo(this, {
			unreadEmails: null,
			askDelete: false,
			isAdditional: isAdditional,
			expanded: null != stored ? !!stored : AccountUserStore.email() === email,
			folders: null,
			foldersLoading: false,
			foldersError: false
		});

		addComputablesTo(this, {
			isActive: () => AccountUserStore.email() === this.email,
			// Only show the unread count when the folders are hidden
			collapsedUnread: () => (!this.expanded()
				&& (this.isActive() ? FolderUserStore.foldersInboxUnreadCount() : this.unreadEmails())) || null,
			ariaLabel: () => this.collapsedUnread()
				? this.label() + ', ' + i18n('FOLDER_LIST/UNREAD_COUNT', {COUNT: this.collapsedUnread()})
				: this.label()
		});

		this.expanded() && !this.isActive() && setTimeout(() => this.fetchFolders(), 1000);

		// Load at random between 3 and 30 seconds
		SettingsUserStore.showUnreadCount() && AccountUserStore.email() !== email
		&& setTimeout(()=>this.fetchUnread(), (Math.ceil(Math.random() * 10)) * 3000);
	}

	label() {
		return this.name || IDN.toUnicode(this.email);
	}

	/**
	 * @param {boolean} value
	 */
	setExpanded(value) {
		const list = expandedAccounts();
		list[this.email] = !!value;
		Local.set(ClientSideKeyNameExpandedAccounts, list);
		this.expanded(!!value);
		value && !this.isActive() && null == this.folders() && this.fetchFolders();
	}

	/**
	 * Get INBOX unread messages
	 */
	fetchUnread() {
		Remote.request('AccountUnread', (iError, oData) => {
			iError || this.unreadEmails(oData?.Result?.unreadEmails || null);
		}, {
			email: this.email
		});
	}

	/**
	 * Get the folders of a not active account
	 */
	fetchFolders() {
		this.foldersLoading(true);
		this.foldersError(false);
		Remote.request('AccountFolders', (iError, oData) => {
			this.foldersLoading(false);
			const items = oData?.Result;
			if (iError || !isArray(items)) {
				this.foldersError(true);
				return;
			}
			const depth = folder => {
				const parts = folder.delimiter ? folder.fullName.split(folder.delimiter) : [folder.fullName];
				// Subfolders of the INBOX are shown on the same level as INBOX itself
				return Math.max(0, parts.length - 1 - ('INBOX' === parts[0].toUpperCase() && 1 < parts.length ? 1 : 0));
			};
			this.folders(items.map(folder => ({
				name: roleNames[folder.role] && !depth(folder) ? i18n(roleNames[folder.role]) : folder.name,
				fullName: folder.fullName,
				role: folder.role,
				selectable: folder.selectable,
				unreadEmails: folder.unreadEmails || null,
				depth: depth(folder)
			})).sort((a, b) => {
				const ra = a.depth ? roleOrder.length : rolePos(a.role),
					rb = b.depth ? roleOrder.length : rolePos(b.role);
				return ra - rb || a.fullName.localeCompare(b.fullName);
			}));
			const inbox = items.find(folder => 'inbox' === folder.role);
			inbox && this.unreadEmails(inbox.unreadEmails || null);
		}, {
			email: this.email
		});
	}

	/**
	 * Imports all mail to main account
	 *//*
	importAll(account) {
		Remote.streamPerLine(line => {
			try {
				line = JSON.parse(line);
				console.dir(line);
			} catch (e) {
				// OOPS
			}
		}, 'AccountImport', {
			Action: 'AccountImport',
			email: account.email
		});
	}
	*/

}
