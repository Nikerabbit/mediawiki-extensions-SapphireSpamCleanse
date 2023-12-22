<?php

namespace SapphireSpamCleanse;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\DeletePageFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MergeUser;
use SmiteSpamAnalyzer;
use Title;
use User;
use UserMergeLogger;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiPage;
use const DB_PRIMARY;
use const DB_REPLICA;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class Cleanse extends Maintenance {
	private ActorNormalization $actorNormalization;
	private DeletePageFactory $deletePageFactory;
	private ILoadBalancer $loadBalancer;
	private RevisionLookup $revisionLookup;
	private RevisionStore $revisionStore;
	private UserFactory $userFactory;
	private UserIdentityLookup $userIdentityLookup;
	private WikiPageFactory $wikiPageFactory;
	private bool $simulate;

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'UserMerge' );
		$this->requireExtension( 'SmiteSpam' );

		$this->addOption( 'remove-user', 'Remove named user' );
		$this->addOption( 'simulate', 'Do not execute any actions' );
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$this->actorNormalization = $services->getActorNormalization();
		$this->deletePageFactory = $services->getDeletePageFactory();
		$this->loadBalancer = $services->getDBLoadBalancer();
		$this->revisionLookup = $services->getRevisionLookup();
		$this->revisionStore = $services->getRevisionStore();
		$this->userFactory = $services->getUserFactory();
		$this->userIdentityLookup = $services->getUserIdentityLookup();
		$this->wikiPageFactory = $services->getWikiPageFactory();

		$admin = $this->userIdentityLookup->getUserIdentityByUserId( 1 );
		$this->simulate = $this->hasOption( 'simulate' );

		if ( $this->hasOption( 'remove-user' ) ) {
			$target =
				$this->userIdentityLookup->getUserIdentityByName(
					$this->getOption( 'remove-user' )
				);
			if ( !$target->isRegistered() ) {
				$this->fatalError( 'Given user account does not exist' );
			}
			$this->removeUser( $target, $admin );
			return;
		}

		$this->cleanseSpam( $admin );
		$this->cleanUsers( $admin );
	}

	private function removeUser( UserIdentity $target, UserIdentity $admin ): void {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$actorId = $this->actorNormalization->findActorId( $target, $db );

		if ( method_exists( $this->revisionStore, 'newSelectQueryBuilder' ) ) {
			$res = $this->revisionStore->newSelectQueryBuilder( $db )
				->where( [ 'rev_actor' => $actorId ] )
				->groupBy( 'rev_page' )
				->caller( __METHOD__ )
				->fetchResultSet();
		} else {
			$queryInfo = $this->revisionStore->getQueryInfo();
			$res = $db->newSelectQueryBuilder()
				->fields( $queryInfo['fields'] )
				->tables( $queryInfo['tables'] )
				->where( [ 'rev_actor' => $actorId ] )
				->groupBy( 'rev_page' )
				->caller( __METHOD__ )
				->fetchResultSet();
		}

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

	private function cleanupRecentChanges( UserIdentity $user, string $ts = null ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
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
		$o = new MergeUser( $user, $user, $l );
		if ( !$this->simulate ) {
			$o->delete( $admin, 'wfMessage' );
		}

		$this->cleanupRecentChanges( $admin, $ts );
	}

	private function cleanupLogs( UserIdentity $user ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$conds = [];
		$conds['log_actor'] = $this->actorNormalization->findActorId( $user, $dbw );

		if ( !$this->simulate ) {
			$dbw->delete( 'logging', $conds, __METHOD__ );
		}
	}

	private function cleanseSpam( UserIdentity $admin ): void {
		$ss = new SmiteSpamAnalyzer( false );
		$spamPages = $ss->run( 0, 500 );

		$byUser = [];

		foreach ( $spamPages as $page ) {
			$userId =
				$this->revisionLookup->getFirstRevision( $page->getTitle() )
					->getUser( RevisionRecord::RAW )->getId();
			if ( $userId === 0 ) {
				continue;
			}

			$byUser[$userId][] = $page;
		}

		$count = count( $byUser );
		echo "Found $count spammy users to review\n";

		foreach ( $byUser as $userId => $pages ) {
			$user = $this->userFactory->newFromId( $userId );
			$userName = $user->getName();
			$email = $user->getEmail();

			foreach ( $pages as $page ) {
				$pageName = $page->getTitle()->getPrefixedText();
				echo "\e[1m$userName <$email> created [[$pageName]]\e[0m\n";
				$text = $page->getContent()->getNativeData();
				$text = mb_substr( $text, 0, 500 );
				$text = wordwrap( $text, 120, "\n", true );
				echo $text . "\n";
			}

			while ( true ) {
				$response = trim( readline( '[p]urge (default) or [t]rust: ' ) );
				readline_add_history( $response );
				switch ( $response ) {
					case '':
					case 'p':
					case 'purge':
						$this->deletePages( $pages, $admin );
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
	}

	private function trustUser( UserIdentity $user, UserIdentity $admin ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		if ( !$this->simulate ) {
			$dbw->insert(
				'smitespam_trusted_user', [
					'trusted_user_id' => $user->getId(),
					'trusted_user_timestamp' => $dbw->timestamp(),
					'trusted_user_admin_id' => $admin->getId(),
				]
			);
		}
	}

	private function cleanUsers( UserIdentity $admin ): void {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );

		$table = 'user';
		$fields = [ '*' ];
		$conds = [];
		$conds['user_editcount'] = 0;
		$options = [ 'ORDER BY' => 'user_name ASC' ];

		$users = [];
		$res = $db->select( $table, $fields, $conds, __METHOD__, $options );
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
		$trustedUserIds = [];
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $db->select( 'smitespam_trusted_user', 'trusted_user_id', [], __METHOD__ );
		foreach ( $res as $row ) {
			$trustedUserIds[] = $row->trusted_user_id;
		}

		$trustedMap = array_flip( $trustedUserIds );
		foreach ( $users as $key => $user ) {
			if ( isset( $trustedMap[$user->getId()] ) ) {
				unset( $users[$key] );
			}
		}

		$userMap = [];
		foreach ( $users as $user ) {
			$sortKey = strrev( $user->getEmail() ) . $user->getName();
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
