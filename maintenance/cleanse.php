<?php

namespace SapphireSpamCleanse;

use Maintenance;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Logging\DatabaseLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\DeletePageFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MergeUser;
use User;
use UserMergeLogger;
use Wikimedia\Rdbms\IConnectionProvider;
use WikiPage;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class Cleanse extends Maintenance {
	private ActorNormalization $actorNormalization;
	private DeletePageFactory $deletePageFactory;
	private IConnectionProvider $connectionProvider;
	private RevisionStore $revisionStore;
	private UserFactory $userFactory;
	private UserIdentityLookup $userIdentityLookup;
	private WikiPageFactory $wikiPageFactory;
	private DatabaseBlockStore $databaseBlockStore;
	private int $maxUsers;
	private int $pagesPerUser;
	private ?int $beforeLogId = null;
	private bool $simulate;

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'UserMerge' );

		$this->addOption( 'remove-user', 'Remove named user' );
		$this->addOption( 'max-users', 'Maximum number of users to process in one run', false, true );
		$this->addOption(
			'before-log-id',
			'Only process accounts whose newusers log_id is lower than this value',
			false,
			true
		);
		$this->addOption( 'pages-per-user', 'Maximum number of recent pages to preview per user', false, true );
		$this->addOption( 'simulate', 'Do not execute any actions' );
	}

	private function initializeServices(): void {
		$services = MediaWikiServices::getInstance();
		$this->actorNormalization = $services->getActorNormalization();
		$this->deletePageFactory = $services->getDeletePageFactory();
		$this->connectionProvider = $services->getConnectionProvider();
		$this->revisionStore = $services->getRevisionStore();
		$this->userFactory = $services->getUserFactory();
		$this->userIdentityLookup = $services->getUserIdentityLookup();
		$this->wikiPageFactory = $services->getWikiPageFactory();
		$this->databaseBlockStore = $services->getDatabaseBlockStore();
	}

	public function execute(): void {
		$this->initializeServices();

		$admin = $this->userIdentityLookup->getUserIdentityByUserId( 1 );
		$this->maxUsers = max( 1, (int)$this->getOption( 'max-users', 25 ) );
		$this->pagesPerUser = max( 1, (int)$this->getOption( 'pages-per-user', 3 ) );
		$this->beforeLogId = $this->hasOption( 'before-log-id' )
			? max( 1, (int)$this->getOption( 'before-log-id' ) )
			: null;
		$this->simulate = $this->hasOption( 'simulate' );

		if ( $this->hasOption( 'remove-user' ) ) {
			$target =
				$this->userIdentityLookup->getUserIdentityByName(
					$this->getOption( 'remove-user' )
				);
			if ( $target === null || !$target->isRegistered() ) {
				$this->fatalError( 'Given user account does not exist' );
			}
			$this->removeUser( $target, $admin );
			return;
		}

		$this->cleanseSpam( $admin );
		$this->cleanUsers( $admin );
	}

	private function removeUser( UserIdentity $target, UserIdentity $admin ): void {
		$db = $this->connectionProvider->getReplicaDatabase();
		$actorId = $this->actorNormalization->findActorId( $target, $db );

		$res = $this->revisionStore->newSelectQueryBuilder( $db )
			->where( [ 'rev_actor' => $actorId ] )
			->groupBy( 'rev_page' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$pages = [];
		echo "Found these pages by the user {$target->getName()}:\n";
		foreach ( $res as $row ) {
			$pages[] = $this->wikiPageFactory->newFromID( $row->rev_page );
			echo Title::newFromId( $row->rev_page )->getPrefixedText() . "\n";
		}

		$confirmation = trim( readline( 'Write purge to purge the pages and the user: ' ) ) === 'purge';
		if ( !$confirmation ) {
			echo "Aborted\n";
			return;
		}

		$this->deletePages( $pages, $admin );
		$this->deleteUser( $target, $admin );
	}

	/** @param WikiPage[] $pages */
	private function deletePages( array $pages, UserIdentity $admin ): void {
		$ts = wfTimestampNow();
		$reason = 'Spamming';

		$adminUser = $this->userFactory->newFromUserIdentity( $admin );
		foreach ( $pages as $page ) {
			$deletePage =
				$this->deletePageFactory->newDeletePage( $page, $adminUser )->forceImmediate(
						true
					);

			if ( !$this->simulate ) {
				$deletePage->deleteUnsafe( $reason );
			}
		}

		$this->cleanupRecentChanges( $admin, $ts );
	}

	private function cleanupRecentChanges( UserIdentity $user, ?string $ts = null ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$conds = [];
		$conds['rc_actor'] = $this->actorNormalization->findActorId( $user, $dbw );
		if ( $ts ) {
			$conds[] = 'rc_timestamp >= ' . $dbw->addQuotes( $dbw->timestamp( $ts ) );
		}

		if ( !$this->simulate ) {
			$dbw->delete( 'recentchanges', $conds, __METHOD__ );
		}
	}

	private function deleteUser( UserIdentity $userIdentity, UserIdentity $adminIdentity ): void {
		$ts = wfTimestampNow();

		$this->cleanupRecentChanges( $userIdentity );
		$this->cleanupLogs( $userIdentity );

		// Delete the user
		$user = $this->userFactory->newFromUserIdentity( $userIdentity );
		$admin = $this->userFactory->newFromUserIdentity( $adminIdentity );

		$l = new UserMergeLogger();
		$o = new MergeUser( $user, $user, $l, $this->databaseBlockStore );
		if ( !$this->simulate ) {
			$o->delete( $admin, 'wfMessage' );
		}

		$this->cleanupRecentChanges( $admin, $ts );
	}

	private function cleanupLogs( UserIdentity $user ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$conds = [];
		$conds['log_actor'] = $this->actorNormalization->findActorId( $user, $dbw );

		if ( !$this->simulate ) {
			$dbw->delete( 'logging', $conds, __METHOD__ );
		}
	}

	private function cleanseSpam( UserIdentity $admin ): void {
		$candidates = $this->getNewUsersFromLog();
		$count = count( $candidates );
		echo "Found $count new users to review\n";

		$smallestSeenLogId = null;
		foreach ( $candidates as $candidate ) {
			$user = $candidate['user'];
			$previewPages = $this->getRecentPagesForUser( $user, $this->pagesPerUser );
			$userName = $user->getName();
			$email = $user->getEmail();
			$logId = $candidate['log_id'];
			$smallestSeenLogId = $smallestSeenLogId === null ? $logId : min( $smallestSeenLogId, $logId );

			if ( $previewPages === [] ) {
				continue;
			}

			echo "\e[1m$userName <$email>\e[0m\n";
			echo "newusers log_id: $logId\n";

			foreach ( $previewPages as $page ) {
				$this->printPagePreview( $userName, $email, $page );
			}

			while ( true ) {
				$response = trim( readline( '[p]urge (default) or [t]rust: ' ) );
				readline_add_history( $response );
				switch ( $response ) {
					case '':
					case 'p':
					case 'purge':
						$this->deletePages( $this->getAllPagesForUser( $user ), $admin );
						$this->deleteUser( $user, $admin );
						echo "\n";
						break 2;
					case 't':
					case 'trust':
						$this->trustUser( $user, $admin );
						echo "\n";
						break 2;
					default:
						break;
				}
			}
		}

		if ( $smallestSeenLogId !== null ) {
			echo "Next run cursor: --before-log-id $smallestSeenLogId\n";
		}
	}

	/**
	 * @return array<int,array{user:User,log_id:int}>
	 */
	private function getNewUsersFromLog(): array {
		$db = $this->connectionProvider->getReplicaDatabase();

		$qb = DatabaseLogEntry::newSelectQueryBuilder( $db )
			->where( [
				'log_type' => 'newusers',
			] )
			->leftJoin( 'sapphirespamcleanse_trusted_user', 't', 't.trusted_user_id = user_id' )
			->where( 't.trusted_user_id IS NULL' );

		if ( $this->beforeLogId !== null ) {
			$qb->andWhere( 'log_id < ' . $this->beforeLogId );
		}

		$res = $qb
			->orderBy( 'log_id', 'DESC' )
			->limit( $this->maxUsers * 5 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$users = [];
		foreach ( $res as $row ) {
			$userId = (int)$row->user_id;
			$user = $this->userFactory->newFromId( $userId );
			$users[$userId] = [
				'user' => $user,
				'log_id' => (int)$row->log_id,
			];
			if ( count( $users ) >= $this->maxUsers ) {
				break;
			}
		}

		return array_values( $users );
	}

	/** @return WikiPage[] */
	private function getAllPagesForUser( UserIdentity $user ): array {
		$db = $this->connectionProvider->getReplicaDatabase();
		$actorId = $this->actorNormalization->findActorId( $user, $db );
		if ( !$actorId ) {
			return [];
		}

		$res = $this->revisionStore->newSelectQueryBuilder( $db )
			->where( [ 'rev_actor' => $actorId ] )
			->groupBy( 'rev_page' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$pages = [];
		foreach ( $res as $row ) {
			$pages[] = $this->wikiPageFactory->newFromID( $row->rev_page );
		}

		return $pages;
	}

	/** @return WikiPage[] */
	private function getRecentPagesForUser( UserIdentity $user, int $limit ): array {
		$db = $this->connectionProvider->getReplicaDatabase();
		$actorId = $this->actorNormalization->findActorId( $user, $db );
		if ( !$actorId ) {
			return [];
		}

		$res = $db->select(
			'recentchanges',
			[ 'rc_namespace', 'rc_title' ],
			[ 'rc_actor' => $actorId ],
			__METHOD__,
			[
				'ORDER BY' => 'rc_timestamp DESC',
				'LIMIT' => $limit * 4,
			]
		);

		$pages = [];
		foreach ( $res as $row ) {
			$title = Title::makeTitle( (int)$row->rc_namespace, $row->rc_title );
			$key = $title->getPrefixedDBkey();
			if ( isset( $pages[$key] ) ) {
				continue;
			}
			$page = $this->wikiPageFactory->newFromTitle( $title );
			if ( !$page->exists() ) {
				continue;
			}
			$pages[$key] = $page;
			if ( count( $pages ) >= $limit ) {
				break;
			}
		}

		return array_values( $pages );
	}

	private function printPagePreview( string $userName, string $email, WikiPage $page ): void {
		$pageName = $page->getTitle()->getPrefixedText();
		echo "\033[1m$userName <$email> edited [[$pageName]]\033[0m\n";

		$text = '';
		$content = $page->getContent();
		if ( $content && method_exists( $content, 'getNativeData' ) ) {
			$text = (string)$content->getNativeData();
		}

		if ( $text === '' ) {
			echo "(No text preview available)\n";
			return;
		}

		$text = $this->sanitizeTerminalOutput( $text );
		$text = mb_substr( $text, 0, 500 );
		$text = wordwrap( $text, 120, "\n", true );
		echo $text . "\n";
	}

	private function sanitizeTerminalOutput( string $text ): string {
		// Strip ANSI CSI sequences: ESC [ ... final_byte
		$text = preg_replace( '/\x1b\[[0-9;]*[A-Za-z]/', '', $text );
		// Strip OSC sequences: ESC ] ... ST (BEL or ESC \)
		$text = preg_replace( '/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', (string) $text );
		// Strip remaining ESC sequences
		$text = preg_replace( '/\x1b[^\x1b]/', '', (string) $text );
		// Strip control characters except newline (\n) and tab (\t)
		$text = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string) $text );
		return $text;
	}

	private function trustUser( UserIdentity $user, UserIdentity $admin ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		if ( !$this->simulate ) {
			$dbw->insert(
				'sapphirespamcleanse_trusted_user', [
					'trusted_user_id' => $user->getId(),
					'trusted_user_timestamp' => $dbw->timestamp(),
					'trusted_user_admin_id' => $admin->getId(),
				]
			);
		}
	}

	private function cleanUsers( UserIdentity $admin ): void {
		$db = $this->connectionProvider->getPrimaryDatabase();

		$res = $db->newSelectQueryBuilder()
			->from( 'user', 'u' )
			->leftJoin( 'sapphirespamcleanse_trusted_user', 't', 't.trusted_user_id = u.user_id' )
			->select( 'u.*' )
			->where( [
				'u.user_editcount' => 0,
				't.trusted_user_id' => null,
			] )
			->orderBy( 'u.user_name', 'ASC' )
			->limit( $this->maxUsers )
			->caller( __METHOD__ )
			->fetchResultSet();

		$users = [];
		foreach ( $res as $row ) {
			$users[] = User::newFromRow( $row );
		}

		$users = $this->updateUserList( $users );
		if ( $users === [] ) {
			echo "No new user accounts to process :)\n";
			return;
		}

		$this->printNewAccountList( $users );

		$confirmation = false;
		while ( true ) {
			$response = trim( readline( '[p]urge (default) or [t]rust or [a]ll: ' ) );
			readline_add_history( $response );
			switch ( $response ) {
				case '':
				case 'p':
				case 'purge':
					$confirmation = true;
					echo "\n";
					break 2;
				case 't':
				case 'trust':
					while ( true ) {
						$response =
						trim( readline( 'enter one number to trust or q to stop trusting: ' ) );
						readline_add_history( $response );
						if ( $response === 'q' ) {
							echo "\n";
							break;
						}

						if ( isset( $users[$response] ) ) {
							$this->trustUser( $users[$response], $admin );
							$userName = $users[$response]->getName();
							echo "Trusted user $userName\n";
							unset( $users[$response] );
						}
					}

					$users = $this->updateUserList( $users );
					$this->printNewAccountList( $users );
					break;
				case 'a':
				case 'all':
					foreach ( $users as $user ) {
						$this->trustUser( $user, $admin );
						$userName = $user->getName();
						echo "Trusted user $userName\n";
					}
					$users = [];
					break 2;
				default:
					break;
			}
		}

		if ( !$confirmation ) {
			return;
		}

		foreach ( $users as $user ) {
			$this->deleteUser( $user, $admin );
			echo ".";
		}

		echo "\n";
	}

	/**
	 * @param User[] $users
	 * @return User[]
	 */
	private function updateUserList( array $users ): array {
		$userMap = [];
		foreach ( $users as $user ) {
			$sortKey = strrev( (string)$user->getEmail() ) . $user->getName();
			$userMap[$sortKey] = $user;
		}

		ksort( $userMap );
		return array_values( $userMap );
	}

	private function printNewAccountList( array $users ): void {
		ksort( $users );

		echo "List of new user accounts to purge:\n";
		foreach ( $users as $i => $user ) {
			$name = $user->getName();
			$email = $user->getEmail();
			$realName = $user->getRealName();

			echo "$i\t$name\t$email\t$realName\n";
		}
	}
}

$maintClass = Cleanse::class;
require_once RUN_MAINTENANCE_IF_MAIN;
