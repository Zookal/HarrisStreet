<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

namespace Zookal\HarrisStreet;

use Composer\IO\IOInterface;
use Composer\Script\Event;
use Symfony\Component\Process\Process;
use Zookal\HarrisStreet\Exceptions;

class ModuleRemover
{
    const ALL_INACTIVE = 'all-inactive';

    /**
     * @var IOInterface
     */
    protected $_io = null;

    protected $_rootFolder = null;

    /**
     * @param   string    $magentoRootDir
     * @param IOInterface $io
     */
    protected function _construct($magentoRootDir, IOInterface $io)
    {
        $this->_rootFolder = $magentoRootDir;
        $this->_io         = $io;
    }

    /**
     * @param string $moduleName
     */
    public function remove($moduleName)
    {
        if (self::ALL_INACTIVE === strtolower($moduleName)) {
            $modules = $this->_getInActiveModules();
            foreach ($modules as $module) {
                $this->_remove($module);
            }
        } else {
            $this->_remove($moduleName);
        }
    }

    /**
     * @return array
     */
    protected function _getInActiveModules()
    {
        $modules = array();
        // applying magic
        return $modules;
    }

    /**
     * @param string $moduleName
     */
    protected function _remove($moduleName)
    {
    }
}
