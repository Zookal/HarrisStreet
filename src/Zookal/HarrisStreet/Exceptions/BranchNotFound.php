<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

namespace Zookal\HarrisStreet\Exceptions;

use Exception;

class BranchNotFound extends \Exception
{
    public function __construct($current, $branchPrefix)
    {
        parent::__construct(sprintf('The branch name must be this pattern "%s<semver>" but your branch is: "%s".
Please have a look at www.semver.org', $branchPrefix, $current));
    }
}