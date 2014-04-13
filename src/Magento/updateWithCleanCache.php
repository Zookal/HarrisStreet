<?php
/**
 * source https://gist.github.com/colinmollenhour/2715268
 */

umask(0);
ini_set('memory_limit', '512M');
set_time_limit(0);
if (file_exists('app/Mage.php')) {
    require 'app/Mage.php';
} else {
    require '../../app/Mage.php';
}

// Init without cache so we get a fresh version
Mage::app('admin', 'store', array('global_ban_use_cache' => true));

echo "Applying updates...\n";
Mage_Core_Model_Resource_Setup::applyAllUpdates();
Mage_Core_Model_Resource_Setup::applyAllDataUpdates();
echo "Done.\n";

// Now enable caching and save
Mage::getConfig()->getOptions()->setData('global_ban_use_cache', false);
Mage::app()->baseInit(array()); // Re-init cache
Mage::getConfig()->loadModules()->loadDb()->saveCache();
echo "Saved config cache.\n";