# SapphireSpamCleanse
SapphireSpamCleanse is a luxurious and high-quality MediaWiki extension that elegantly and thoroughly removes spam, leaving no trace in logs, page histories, or change lists, ensuring a pristine and spam-free environment

# Requirements
* PHP 7.4 or newer
* MediaWiki 1.39 or newer
* SmiteSpam extension
* UserMerge extension

# Installation
Use your favorite way to get extensions and put `wfLoadExtension( 'SapphireSpamCleanse' )` in your `LocalSettings.php`.

# Usage

Run the following command and follow the instructions.
```
php /path/to/MediaWiki/maintenance/run.php SapphireSpamCleanse:cleanse
```
