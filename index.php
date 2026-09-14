<?php

require_once('JournalPaymentPlugin.inc.php');

// OJS stores the plugin class name from version.xml in the versions table.
// Releases 1.0.3 through 1.0.13 used version-specific class names. Keep those
// names as aliases so a clean file replacement still works before OJS has had
// a chance to refresh the versions record.
$legacyClassNames = array(
	'JournalPaymentV103Plugin',
	'JournalPaymentV104Plugin',
	'JournalPaymentV105Plugin',
	'JournalPaymentV106Plugin',
	'JournalPaymentV107Plugin',
	'JournalPaymentV108Plugin',
	'JournalPaymentV109Plugin',
	'JournalPaymentV110Plugin',
	'JournalPaymentV111Plugin',
	'JournalPaymentV112Plugin',
	'JournalPaymentV113Plugin',
);
foreach ($legacyClassNames as $legacyClassName) {
	if (!class_exists($legacyClassName, false)) {
		class_alias('JournalPaymentPlugin', $legacyClassName);
	}
}

return new JournalPaymentPlugin();
