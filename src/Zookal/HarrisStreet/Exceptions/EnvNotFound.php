<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

namespace Zookal\HarrisStreet\Exceptions;

use Exception;

class EnvNotFound extends \Exception
{
    public function __construct($pwd)
    {
        parent::__construct(sprintf('A environment file target.json doesn\'t exists in folder: %s', $pwd));
    }
}