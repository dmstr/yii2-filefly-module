<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2016 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use yii\base\Component;


/**
 * Class GetPermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class GetPermission extends Component implements PluginInterface
{
    /**
     * The yii component name of this filesystem
     * @var string
     */
    public $component;

    /**
     * @var FilesystemInterface $filesystem
     */
    protected $filesystem;

    /**
     * @var array
     */
    protected $permissions = [];

    /**
     * @param FilesystemInterface $filesystem
     */
    public function setFilesystem(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return 'getPermission';
    }

    /**
     * Build permissions array for multi selects in permission modal
     * // TODO optimize iterations!?
     *
     * @param $path
     *
     * @return bool
     */
    public function handle($path)
    {
        $this->permissions           = [];
        $this->permissions['read']   = null;
        $this->permissions['update'] = null;
        $this->permissions['delete'] = null;

        /** @var $hash \hrzg\filefly\models\FileflyHashmap */
        $query = FileflyHashmap::find();
        $query->andWhere(['component' => $this->component]);
        $query->andWhere(['path' => $path]);
        $hash = $query->one();

        if ($hash === null) {
            return false;
        } else {
            $userAuthItems = FileflyHashmap::getUsersAuthItems();

            // READ ACCESS
            $posRead = 0;
            foreach (array_keys($userAuthItems) as $authItem) {
                $selected = false;
                foreach ($hash->authItemStringToArray(Module::ACCESS_READ) as $readItem) {
                    if ($authItem === $readItem) {
                        $selected = true;
                    }
                    $this->permissions['read'][$posRead] = ['role' => $authItem, 'selected' => $selected];
                }
                $posRead++;
            }

            // UPDATE ACCESS
            $posUpdate = 0;
            foreach (array_keys($userAuthItems) as $authItem) {
                $selected = false;
                foreach ($hash->authItemStringToArray(Module::ACCESS_UPDATE) as $updateItem) {
                    if ($authItem === $updateItem) {
                        $selected = true;
                    }
                    $this->permissions['update'][$posUpdate] = ['role' => $authItem, 'selected' => $selected];
                }
                $posUpdate++;
            }

            // DELETE ACCESS
            $posDelete = 0;
            foreach (array_keys($userAuthItems) as $authItem) {
                $selected = false;
                foreach ($hash->authItemStringToArray(Module::ACCESS_DELETE) as $deleteItem) {
                    if ($authItem === $deleteItem) {
                        $selected = true;
                    }
                    $this->permissions['delete'][$posDelete] = ['role' => $authItem, 'selected' => $selected];
                }
                $posDelete++;
            }
        }
        return $this->permissions;
    }
}
