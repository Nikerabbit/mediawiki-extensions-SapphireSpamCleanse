<?php

namespace SapphireSpamCleanse;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use Override;

class Hooks implements LoadExtensionSchemaUpdatesHook {
	#[Override]
	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
		$dir = __DIR__ . '/../sql/';
		$db = $updater->getDB();
		$dbType = $db->getType();

		if ( $dbType === 'mysql' ) {
			$updater->addExtensionTable(
				'sapphirespamcleanse_trusted_user',
				$dir . 'tables-generated.sql'
			);
		} elseif ( $dbType === 'sqlite' ) {
			$updater->addExtensionTable(
				'sapphirespamcleanse_trusted_user',
				$dir . 'sqlite/tables-generated.sql'
			);
		} elseif ( $dbType === 'postgres' ) {
			$updater->addExtensionTable(
				'sapphirespamcleanse_trusted_user',
				$dir . 'postgres/tables-generated.sql'
			);
		}
	}
}
