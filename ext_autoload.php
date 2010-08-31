<?php
/* 
 * Register necessary class names with autoloader
 *
 * $Id$
 */
$extensionPath = t3lib_extMgm::extPath('overlays');
return array(
	'tx_overlays' => $extensionPath . 'class.tx_overlays.php',
);
?>
