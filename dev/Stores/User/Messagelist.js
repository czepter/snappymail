import { koComputable, addObservablesTo, addComputablesTo } from 'External/ko';

import { SMAudio } from 'Common/Audio';
import { Notifications } from 'Common/Enums';
import { MessageSetAction, FolderType } from 'Common/EnumsUser';
import { $htmlCL, fireEvent } from 'Common/Globals';
import { arrayLength, pString } from 'Common/Utils';
import { UNUSED_OPTION_VALUE } from 'Common/Consts';

import {
	getFolderInboxName,
	getFolderFromCacheList,
	setFolderETag
} from 'Common/Cache';

import { mailBox } from 'Common/Links';
import { i18n, getNotification } from 'Common/Translator';

import { EmailCollectionModel } from 'Model/EmailCollection';
import { MessageCollectionModel } from 'Model/MessageCollection';

import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore } from 'Stores/User/Folder';
import { MessageUserStore } from 'Stores/User/Message';
import { NotificationUserStore } from 'Stores/User/Notification';
import { SettingsUserStore } from 'Stores/User/Settings';

import Remote from 'Remote/User/Fetch';

import { b64EncodeJSONSafe } from 'Common/Utils';
import { SettingsGet } from 'Common/Globals';
import { SUB_QUERY_PREFIX } from 'Common/Links';
import { AppUserStore } from 'Stores/User/App';
import { UnifiedUserStore, UNIFIED_HASH } from 'Stores/User/Unified';

import { baseCollator } from 'Common/Translator';

const
	isChecked = item => item.checked(),
	isDeleted = item => item.isDeleted(),
	replaceHash = hash => {
		rl.route.off();
		hasher.replaceHash(hash);
		rl.route.on();
	},
	disableAutoSelect = ko.observable(false).extend({ falseTimeout: 500 });

export const MessagelistUserStore = ko.observableArray().extend({ debounce: 0 });

addObservablesTo(MessagelistUserStore, {
	count: 0,
	listSearch: '',
	listLimited: 0,
	threadUid: 0,
	page: 1,
	pageBeforeThread: 1,
	error: '',
//	folder: '',

	endHash: '',
	endThreadUid: 0,

	loading: false,
	// Happens when message(s) removed from list
	isIncomplete: false,

	selectedMessage: null,
	focusedMessage: null
});

// Computed Observables

addComputablesTo(MessagelistUserStore, {
	// Unified inbox, messages of all accounts
	isUnified: () => UNIFIED_HASH === MessagelistUserStore().folder,

	isLoading: () => {
		const value = MessagelistUserStore.loading() | MessagelistUserStore.isIncomplete();
		$htmlCL.toggle('list-loading', value);
		return value;
	},

	isArchiveFolder: () => FolderUserStore.archiveFolder() === MessagelistUserStore().folder,

	isDraftFolder: () => FolderUserStore.draftsFolder() === MessagelistUserStore().folder,

	isSentFolder: () => FolderUserStore.sentFolder() === MessagelistUserStore().folder,

	isSpamFolder: () => FolderUserStore.spamFolder() === MessagelistUserStore().folder,

	isTrashFolder: () => FolderUserStore.trashFolder() === MessagelistUserStore().folder,

	// In the unified inbox it depends on the account of each message, see moveUnifiedMessages()
	archiveAllowed: () => MessagelistUserStore.isUnified()
		|| (![UNUSED_OPTION_VALUE, MessagelistUserStore().folder].includes(FolderUserStore.archiveFolder())
		&& !MessagelistUserStore.isDraftFolder()),

	canMarkAsSpam: () => MessagelistUserStore.isUnified() || !(UNUSED_OPTION_VALUE === FolderUserStore.spamFolder()
//		| MessagelistUserStore.isArchiveFolder()
		| MessagelistUserStore.isSentFolder()
		| MessagelistUserStore.isDraftFolder()
		| MessagelistUserStore.isSpamFolder()),

	pageCount: () => Math.max(1, Math.ceil(MessagelistUserStore.count() / SettingsUserStore.messagesPerPage())),

	mainSearch: {
		read: MessagelistUserStore.listSearch,
		write: value => hasher.setHash(
			mailBox(FolderUserStore.currentFolderFullNameHash(), 1,
				value.toString().trim(), MessagelistUserStore.threadUid())
		)
	},

	listCheckedOrSelected: () => {
		const
			selectedMessage = MessagelistUserStore.selectedMessage(),
			checked = MessagelistUserStore.filter(item => isChecked(item));
		return checked.length ? checked : (selectedMessage ? [selectedMessage] : []);
	},

	listCheckedOrSelectedUidsWithSubMails: () => {
		let result = new Set;
		// Used in the unified inbox, where the same uid can exist in multiple accounts
		result.messages = MessagelistUserStore.listCheckedOrSelected();
		result.messages.forEach(message => {
			result.add(message.uid);
			result.folder = message.folder;
			if (1 < message.threadsLen()) {
				message.threads().forEach(result.add, result);
			}
		});
		return result;
	}
});

MessagelistUserStore.listChecked = koComputable(
	() => MessagelistUserStore.filter(isChecked)
).extend({ rateLimit: 0 });

// Also used by Selector
MessagelistUserStore.hasChecked = koComputable(
	// Issue: not all are observed?
	() => !!MessagelistUserStore.find(isChecked)
).extend({ rateLimit: 0 });

MessagelistUserStore.hasCheckedOrSelected = koComputable(() =>
	!!MessagelistUserStore.selectedMessage()
	// Issue: not all are observed?
	| !!MessagelistUserStore.find(isChecked)
).extend({ rateLimit: 50 });

MessagelistUserStore.hasCheckedOrSelectedAndDeleted = koComputable(
	() => !!MessagelistUserStore.listCheckedOrSelected().find(isDeleted)
).extend({ rateLimit: 50 });

MessagelistUserStore.hasCheckedOrSelectedAndUndeleted = koComputable(
	() => !!MessagelistUserStore.listCheckedOrSelected().find(item => !item?.isDeleted())
).extend({ rateLimit: 50 });

MessagelistUserStore.notifyNewMessages = (folder, newMessages) => {
	if (getFolderInboxName() === folder && arrayLength(newMessages)) {

		SMAudio.playNotification();

		const len = newMessages.length;
		if (3 < len) {
			NotificationUserStore.display(
				AccountUserStore.email(),
				i18n('MESSAGE_LIST/NEW_MESSAGE_NOTIFICATION', {
					COUNT: len
				}),
				{ Url: mailBox(newMessages[0].folder) }
			);
		} else {
			newMessages.forEach(item => {
				NotificationUserStore.display(
					EmailCollectionModel.reviveFromJson(item.from).toString(),
					item.subject,
					{ folder: item.folder, uid: item.uid }
				);
			});
		}
	}
}

MessagelistUserStore.canSelect = () =>
	!disableAutoSelect()
	&& SettingsUserStore.usePreviewPane();
//	&& !SettingsUserStore.showNextMessage();

let prevFolderName;

const
	/**
	 * Response of the UnifiedMessageList action
	 */
	setUnifiedList = (iError, oData) => {
		let error = '';
		if (iError) {
			if ('reload' == oData?.name) {
				return;
			}
			error = getNotification(iError);
		} else {
			const result = oData.Result,
				collection = MessageCollectionModel.reviveFromJson(result);
			if (collection && UnifiedUserStore.active()) {
				UnifiedUserStore.setAccounts(result.accounts);
				collection.folder = UNIFIED_HASH;
				if (result.accounts?.some(account => account.error)) {
					error = i18n('MESSAGE_LIST/UNIFIED_ACCOUNT_ERROR', {
						EMAILS: result.accounts.filter(account => account.error).map(account => account.email).join(', ')
					});
				}

				MessagelistUserStore.count(collection.totalEmails);
				MessagelistUserStore.listSearch(pString(collection.search));
				MessagelistUserStore.listLimited(!!collection.limited);
				MessagelistUserStore.page(Math.ceil(collection.offset / SettingsUserStore.messagesPerPage() + 1));
				MessagelistUserStore.threadUid(0);
				MessagelistUserStore.endHash(
					UNIFIED_HASH + '|' + collection.search + '|0|' + MessagelistUserStore.page()
				);
				MessagelistUserStore.endThreadUid(0);

				const message = MessageUserStore.message();
				if (message && !message.accountHash) {
					MessageUserStore.message(null);
				}

				disableAutoSelect(true);
				MessagelistUserStore(collection);
				MessagelistUserStore.isIncomplete(false);
			} else if (!collection) {
				MessagelistUserStore.count(0);
				MessagelistUserStore([]);
				error = getNotification(Notifications.CantGetMessageList);
			}
		}
		MessagelistUserStore.loading(false);
		MessagelistUserStore.error(error);
	},

	/**
	 * In the unified inbox messages are from multiple accounts.
	 * The requests started in fn(email, messages) are for that account.
	 * @param {Array} messages
	 * @param {Function} fn
	 */
	forEachAccount = (messages, fn) => {
		const accounts = new Map;
		messages.forEach(message => {
			const email = message.account || '';
			accounts.has(email) || accounts.set(email, []);
			accounts.get(email).push(message);
		});
		accounts.forEach((list, email) => Remote.forAccount(email, () => fn(email, list)));
	},

	/**
	 * The folder of the current account to update the counts for
	 * @param {string} email
	 * @param {string} name
	 * @returns {?FolderModel}
	 */
	accountFolder = (email, name) =>
		(!email || UnifiedUserStore.isActiveAccount(email)) ? getFolderFromCacheList(name) : null;

MessagelistUserStore.forEachAccount = forEachAccount;

/**
 * @param {boolean=} bDropPagePosition = false
 * @param {boolean=} bDropCurrentFolderCache = false
 */
MessagelistUserStore.reload = (bDropPagePosition = false, bDropCurrentFolderCache = false) => {
	let iOffset = (MessagelistUserStore.page() - 1) * SettingsUserStore.messagesPerPage(),
		folderName = FolderUserStore.currentFolderFullName();
//		folderName = FolderUserStore.currentFolder() ? self.currentFolder().fullName : '');

	if (bDropCurrentFolderCache) {
		setFolderETag(folderName, '');
	}

	if (bDropPagePosition) {
		MessagelistUserStore.page(1);
		MessagelistUserStore.pageBeforeThread(1);
		iOffset = 0;

		replaceHash(
			mailBox(
				FolderUserStore.currentFolderFullNameHash(),
				MessagelistUserStore.page(),
				MessagelistUserStore.listSearch(),
				MessagelistUserStore.threadUid()
			)
		);
	}

	if (prevFolderName != folderName) {
		prevFolderName = folderName;
		MessagelistUserStore([]);
	}

	MessagelistUserStore.loading(true);

	Remote.abort('UnifiedMessageList', 'reload');
	if (UnifiedUserStore.active()) {
		Remote.abort('MessageList', 'reload').request('UnifiedMessageList',
			(iError, oData) => setUnifiedList(iError, oData),
			{
				offset: iOffset,
				limit: SettingsUserStore.messagesPerPage(),
				search: MessagelistUserStore.listSearch()
			},
			60000
		);
		return;
	}

	let sGetAdd = '',
//		folder = getFolderFromCacheList(folderName.fullName),
		folder = getFolderFromCacheList(folderName),
		folderETag = folder?.etag || '',
		params = {
			folder: folderName,
			offset: iOffset,
			limit: SettingsUserStore.messagesPerPage(),
			uidNext: folder?.uidNext || 0, // Used to check for new messages
			sort: FolderUserStore.sortMode(),
			search: MessagelistUserStore.listSearch()
		},
		fCallback = (iError, oData, bCached) => {
			let error = '';
			if (iError) {
				if ('reload' != oData?.name) {
					error = getNotification(iError);
					MessagelistUserStore.loading(false);
//					if (Notifications.RequestAborted !== iError) {
//						MessagelistUserStore([]);
//					}
//					if (oData.message) { error = oData.message + error; }
				}
			} else {
				const collection = MessageCollectionModel.reviveFromJson(oData.Result, bCached);
				if (collection) {
					const
						folderInfo = collection.folder,
						folder = getFolderFromCacheList(folderInfo.name);
					collection.folder = folderInfo.name;
					if (folder && !bCached) {
//						folder.revivePropertiesFromJson(result);
						folder.expires = Date.now();
						folder.uidNext = folderInfo.uidNext;
						folder.etag = folderInfo.etag;

						if (null != folderInfo.totalEmails) {
							folder.totalEmails(folderInfo.totalEmails);
						}

						if (null != folderInfo.unreadEmails) {
							folder.unreadEmails(folderInfo.unreadEmails);
						}

						let flags = folderInfo.permanentFlags || [];
						if (flags.includes('\\*')) {
							/** Add Thunderbird labels */
							let i = 6;
							while (--i) {
								flags.includes('$label'+i) || flags.push('$label'+i);
							}
							/** TODO: add others by default? */
						}
						folder.permanentFlags(flags.sort(baseCollator().compare));

						MessagelistUserStore.notifyNewMessages(folder.fullName, collection.newMessages);
					}

					MessagelistUserStore.count(collection.totalEmails);
					MessagelistUserStore.listSearch(pString(collection.search));
					MessagelistUserStore.listLimited(!!collection.limited);
					MessagelistUserStore.page(Math.ceil(collection.offset / SettingsUserStore.messagesPerPage() + 1));
					MessagelistUserStore.threadUid(collection.threadUid);

					MessagelistUserStore.endHash(
						folderInfo.name +
						'|' + collection.search +
						'|' + MessagelistUserStore.threadUid() +
						'|' + MessagelistUserStore.page()
					);
					MessagelistUserStore.endThreadUid(collection.threadUid);
					const message = MessageUserStore.message();
					if (message && folderInfo.name !== message.folder) {
						MessageUserStore.message(null);
					}

					disableAutoSelect(true);

					if (collection.threadUid) {
						let refs = {};
						collection.forEach(msg => {
							msg.level = 0;
							if (msg.inReplyTo && refs[msg.inReplyTo]) {
								msg.level = 1 + refs[msg.inReplyTo].level;
							}
							refs[msg.messageId] = msg;
						});
					}

					MessagelistUserStore(collection);
					MessagelistUserStore.isIncomplete(false);
				} else {
					MessagelistUserStore.count(0);
					MessagelistUserStore([]);
					error = getNotification(Notifications.CantGetMessageList);
				}
				MessagelistUserStore.loading(false);
			}
			MessagelistUserStore.error(error);
		};

	if (AppUserStore.threadsAllowed() && SettingsUserStore.useThreads()) {
		params.useThreads = 1;
		params.threadAlgorithm = SettingsUserStore.threadAlgorithm();
		params.threadUid = MessagelistUserStore.threadUid();
	} else {
		params.threadUid = 0;
	}
	if (folderETag) {
		params.hash = folderETag + '-' + SettingsGet('accountHash');
		sGetAdd = 'MessageList/' + SUB_QUERY_PREFIX + '/' + b64EncodeJSONSafe(params);
		params = {};
	}

	Remote.abort('MessageList', 'reload').request('MessageList',
		fCallback,
		params,
		60000, // 60 seconds before aborting
		sGetAdd
	);
};

/**
 * @param {string} sFolderFullName
 * @param {number} iSetAction
 * @param {Array=} messages = null
 */
MessagelistUserStore.setAction = (sFolderFullName, iSetAction, messages) => {
	messages = messages || MessagelistUserStore.listChecked();

	const changed = [];

	if (iSetAction == MessageSetAction.SetSeen) {
		messages.forEach(oMessage => {
			if (oMessage.isUnseen() && changed.push(oMessage)) {
				oMessage.flags.push('\\seen');
				if (oMessage.threads().length > 0 && oMessage.threadUnseen().includes(oMessage.uid)) {
					oMessage.threadUnseen.remove(oMessage.uid);
				}
			}
		});
	} else if (iSetAction == MessageSetAction.UnsetSeen) {
		messages.forEach(oMessage => {
			if (!oMessage.isUnseen() && changed.push(oMessage)) {
				oMessage.flags.remove('\\seen');
				if (oMessage.threads().length > 0 && !oMessage.threadUnseen().includes(oMessage.uid)) {
					oMessage.threadUnseen.push(oMessage.uid);
				}
			}
		});
	} else if (iSetAction == MessageSetAction.SetFlag) {
		messages.forEach(oMessage =>
			!oMessage.isFlagged() && changed.push(oMessage) && oMessage.flags.push('\\flagged')
		);
	} else if (iSetAction == MessageSetAction.UnsetFlag) {
		messages.forEach(oMessage =>
			oMessage.isFlagged() && changed.push(oMessage) && oMessage.flags.remove('\\flagged')
		);
	} else if (iSetAction == MessageSetAction.SetDeleted) {
		messages.forEach(oMessage =>
			!oMessage.isDeleted() && changed.push(oMessage) && oMessage.flags.push('\\deleted')
		);
	} else if (iSetAction == MessageSetAction.UnsetDeleted) {
		messages.forEach(oMessage =>
			oMessage.isDeleted() && changed.push(oMessage) && oMessage.flags.remove('\\deleted')
		);
	}

	// In the unified inbox each account has its own INBOX
	sFolderFullName && forEachAccount(changed, (email, list) => {
		const
			folderName = email ? list[0].folder : sFolderFullName,
			rootUids = list.map(oMessage => oMessage.uid).validUnique();
		let length = rootUids.length,
			folder;
		switch (iSetAction) {
			case MessageSetAction.SetSeen:
				length = -length;
				// fallthrough is intentionally
			case MessageSetAction.UnsetSeen:
				folder = accountFolder(email, folderName);
				if (folder) {
					folder.unreadEmails(Math.max(0, folder.unreadEmails() + length));
				} else {
					const account = UnifiedUserStore.findAccount(email);
					account?.unreadEmails(Math.max(0, (account.unreadEmails() || 0) + length) || null);
				}
				Remote.request('MessageSetSeen', null, {
					folder: folderName,
					uids: rootUids.join(','),
					setAction: iSetAction == MessageSetAction.SetSeen ? 1 : 0
				});
				break;

			case MessageSetAction.SetFlag:
			case MessageSetAction.UnsetFlag:
				Remote.request('MessageSetFlagged', null, {
					folder: folderName,
					uids: rootUids.join(','),
					setAction: iSetAction == MessageSetAction.SetFlag ? 1 : 0
				});
				break;

			case MessageSetAction.SetDeleted:
			case MessageSetAction.UnsetDeleted:
				Remote.request('MessageSetDeleted', null, {
					folder: folderName,
					uids: rootUids.join(','),
					setAction: iSetAction == MessageSetAction.SetDeleted ? 1 : 0
				});
				break;
			// no default
		}
	});
};

/**
 * @param {string} fromFolderFullName
 * @param {Set} oUids
 * @param {string=} toFolderFullName = ''
 * @param {boolean=} copy = false
 */
MessagelistUserStore.moveMessages = (
	fromFolderFullName, oUids, toFolderFullName = '', copy = false
) => {
	// Unified inbox: the folders of other accounts are not in the folder list
	const unified = oUids?.messages?.some(message => message.account);
	if (unified) {
		if (oUids.messages.some(message => !UnifiedUserStore.isActiveAccount(message.account))) {
			alert(i18n('MESSAGE_LIST/UNIFIED_MOVE_ACTIVE_ONLY'));
			return;
		}
		fromFolderFullName = oUids.messages[0].folder;
	}

	const fromFolder = getFolderFromCacheList(fromFolderFullName);

	if (!fromFolder || !oUids?.size) return;

	let unseenCount = 0,
		setPage = 0,
		currentMessage = MessageUserStore.message();

	const toFolder = toFolderFullName ? getFolderFromCacheList(toFolderFullName) : null,
		trashFolder = FolderUserStore.trashFolder(),
		spamFolder = FolderUserStore.spamFolder(),
		page = MessagelistUserStore.page(),
		messages = unified ? oUids.messages : (
			FolderUserStore.currentFolderFullName() === fromFolderFullName
				? MessagelistUserStore.filter(item => item && oUids.has(item.uid))
				: []),
		moveOrDeleteResponseHelper = (iError, oData) => {
			if (iError) {
				setFolderETag(FolderUserStore.currentFolderFullName(), '');
				alert(getNotification(iError));
			} else if (FolderUserStore.currentFolder()) {
				if (2 === arrayLength(oData.Result)) {
					setFolderETag(oData.Result[0], oData.Result[1]);
				} else {
					setFolderETag(FolderUserStore.currentFolderFullName(), '');
				}

				MessagelistUserStore.count(MessagelistUserStore.count() - oUids.size);
				if (page > MessagelistUserStore.pageCount()) {
					setPage = MessagelistUserStore.pageCount();
				}
				if (setPage) {
					MessagelistUserStore.page(setPage);
					replaceHash(
						mailBox(
							FolderUserStore.currentFolderFullNameHash(),
							setPage,
							MessagelistUserStore.listSearch(),
							MessagelistUserStore.threadUid()
						)
					);
				}

				MessagelistUserStore.reload(!MessagelistUserStore.count());
			}
		};

	messages.forEach(item => item?.isUnseen() && ++unseenCount);

	if (!copy) {
		fromFolder.etag = '';
		fromFolder.totalEmails(Math.max(0, fromFolder.totalEmails() - oUids.size));
		fromFolder.unreadEmails(Math.max(0, fromFolder.unreadEmails() - unseenCount));
	}

	if (toFolder) {
		toFolder.etag = '';
		toFolder.totalEmails(toFolder.totalEmails() + oUids.size);
		if (trashFolder !== toFolder.fullName && spamFolder !== toFolder.fullName) {
			toFolder.unreadEmails(toFolder.unreadEmails() + unseenCount);
		}
		toFolder.actionBlink(true);
	}

	if (messages.length) {
		disableAutoSelect(true);
		if (copy) {
			messages.forEach(item => item.checked(false));
		} else {
			MessagelistUserStore.isIncomplete(true);

			// Select next email https://github.com/the-djmaze/snappymail/issues/968
			if (currentMessage && 1 == messages.length && SettingsUserStore.showNextMessage()) {
				let next = MessagelistUserStore.indexOf(currentMessage) + 1;
				if (0 < next && (next = MessagelistUserStore()[next])) {
					currentMessage = null;
					fireEvent('mailbox.message.show', {
						folder: next.folder,
						uid: next.uid
					});
				}
			}

			messages.forEach(item => {
				if (currentMessage && currentMessage.hash === item.hash) {
					currentMessage = null;
					MessageUserStore.message(null);
				}
				MessagelistUserStore.remove(item);
			});
		}
	}

	if (toFolderFullName) {
		if (toFolder && fromFolderFullName != toFolderFullName) {
			const params =  {
				fromFolder: fromFolderFullName,
				toFolder: toFolderFullName,
				uids: [...oUids].join(',')
			};
			if (copy) {
				Remote.request('MessageCopy', null, params);
			} else {
				const
					isSpam = spamFolder === toFolderFullName,
					isHam = !isSpam && spamFolder === fromFolderFullName && getFolderInboxName() === toFolderFullName;
				params.markAsRead = (isSpam || FolderUserStore.trashFolder() === toFolderFullName) ? 1 : 0;
				params.learning = isSpam ? 'SPAM' : isHam ? 'HAM' : '';
				Remote.abort('MessageList', 'reload').request('MessageMove', moveOrDeleteResponseHelper, params);
			}
		}
	} else {
		Remote.abort('MessageList', 'reload').request('MessageDelete',
			moveOrDeleteResponseHelper,
			{
				folder: fromFolderFullName,
				uids: [...oUids].join(',')
			}
		);
	}
};

/**
 * Move messages of the unified inbox to the trash, spam or archive folder of their account
 * @param {Array} messages
 * @param {number} iFolderType FolderType.Trash, FolderType.Junk or FolderType.Archive
 * @param {boolean=} bDelete Delete without moving to the trash
 */
MessagelistUserStore.moveUnifiedMessages = (messages, iFolderType, bDelete) => {
	const
		type = {
			[FolderType.Trash]: 'trash',
			[FolderType.Junk]: 'junk',
			[FolderType.Archive]: 'archive'
		}[iFolderType],
		missing = [];
	let pending = 0,
		removed = 0;

	if (!type && !bDelete) {
		return;
	}

	forEachAccount(messages, (email, list) => {
		const
			fromFolder = list[0].folder,
			toFolder = bDelete ? '' : UnifiedUserStore.folder(email, type),
			uids = list.map(message => message.uid).validUnique().join(','),
			unseen = list.filter(message => message.isUnseen()).length;

		if (!bDelete && UNUSED_OPTION_VALUE === toFolder) {
			if (FolderType.Trash === iFolderType) {
				// Flag as \Deleted for removal by later EXPUNGE, like without trash folder
				MessagelistUserStore.setAction(fromFolder, MessageSetAction.SetDeleted, list);
			} else {
				missing.push(email);
			}
			return;
		}
		if (!bDelete && !toFolder) {
			missing.push(email);
			return;
		}

		const folder = accountFolder(email, fromFolder);
		if (folder) {
			folder.etag = '';
			folder.totalEmails(Math.max(0, folder.totalEmails() - list.length));
			folder.unreadEmails(Math.max(0, folder.unreadEmails() - unseen));
		} else if (unseen) {
			const account = UnifiedUserStore.findAccount(email);
			account?.unreadEmails(Math.max(0, (account.unreadEmails() || 0) - unseen) || null);
		}

		list.forEach(message => {
			MessageUserStore.message() === message && MessageUserStore.message(null);
			MessagelistUserStore.remove(message);
		});
		removed += list.length;

		++pending;
		const fCallback = iError => {
			iError && alert(getNotification(iError));
			if (!--pending) {
				MessagelistUserStore.count(Math.max(0, MessagelistUserStore.count() - removed));
				MessagelistUserStore.page() > MessagelistUserStore.pageCount()
					&& MessagelistUserStore.page(MessagelistUserStore.pageCount());
				MessagelistUserStore.reload();
			}
		};
		if (bDelete) {
			Remote.request('MessageDelete', fCallback, {
				folder: fromFolder,
				uids: uids
			});
		} else {
			Remote.request('MessageMove', fCallback, {
				fromFolder: fromFolder,
				toFolder: toFolder,
				uids: uids,
				markAsRead: FolderType.Archive === iFolderType ? 0 : 1,
				learning: FolderType.Junk === iFolderType ? 'SPAM' : ''
			});
		}
	});

	if (pending) {
		disableAutoSelect(true);
		MessagelistUserStore.isIncomplete(true);
	}

	missing.length && alert(i18n('MESSAGE_LIST/UNIFIED_FOLDER_MISSING', {
		EMAILS: missing.map(email => IDN.toUnicode(email)).join(', ')
	}));
};
