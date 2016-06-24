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
use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use yii\base\Component;


/**
 * Class FindPermissions
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class FindPermissions extends Component implements PluginInterface
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
     * List of tree parents to be checked
     * @var array
     */
    private $_iterator = [];

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
        return 'grantPermission';
    }

    /**
     * Find permissions for paths by permission type
     *
     * - Parent permission support if no direct permission can be granted
     *
     * @param array $contents
     * @param string $permissionType
     * @param bool $findRaw
     *
     * @return array|\yii\db\ActiveRecord[]
     */
    public function handle(array $contents, $permissionType = 'access_read', $findRaw = false)
    {
        $this->_iterator = [];

        // Grand ALL access for admins
        if (in_array('FileflyAdmin', array_keys(FileflyHashmap::getUsersAuthItems()))) {
            return true;
        }

        foreach ($contents as $path) {

            // built path iterations
            $this->buildPathIterator($path);

            foreach ($this->_iterator as $subPath) {
                /** @var $hash \hrzg\filefly\models\FileflyHashmap */
                $query = FileflyHashmap::find($findRaw);
                $query->andWhere(['component' => $this->component]);
                $query->andWhere(['path' => $subPath]);
                $query->andWhere(['access_domain' => \Yii::$app->language]);
                $hash = $query->one();

                if ($hash === null) {
                    continue;
                }

                // if permissions for type are set
                if (!empty($hash->{$permissionType})) {

                    // on permission deny break else grant
                    if (!$hash->hasPermission($permissionType)) {
                        return false;
                    } else {
                        return true;
                    }
                }

                // to match if full owner rights can be granted
                if ($hash->hasPermission($permissionType)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * built the the path iterations down -> up
     *
     * @param $path
     */
    private function buildPathIterator($path)
    {
        $path           = ltrim($path, '/');
        $parts          = explode('/', $path);
        $countPathParts = count($parts);
        $subCounter     = $countPathParts;

        for ($i = 0; $i < $countPathParts; $i++) {
            $tmp = '';
            for ($j = 0; $j < $subCounter; $j++) {
                $tmp .= '/' . $parts[$j];

            }
            $subCounter--;
            $this->_iterator[] = $tmp;
        }
    }
}