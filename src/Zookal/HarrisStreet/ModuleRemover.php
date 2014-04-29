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
    /**
     * @var IOInterface
     */
    protected $_io = null;

    /**
     * @param IOInterface $io
     */
    protected function _construct(IOInterface $io)
    {
        $this->_io = $io;
    }

    public function remove($baseRoot, $moduleName)
    {
    }
}
