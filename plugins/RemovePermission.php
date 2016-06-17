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
use yii\helpers\StringHelper;


/**
 * Class RemovePermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class RemovePermission implements PluginInterface
{
    protected $filesystem;

    protected $adapterName;

    /**
     * @param FilesystemInterface $filesystem
     */
    public function setFilesystem(FilesystemInterface $filesystem)
    {
        $this->filesystem  = $filesystem;
        $this->adapterName = StringHelper::basename(get_class($filesystem->getAdapter()));
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return 'removePermission';
    }

    /**
     * The full path strings of the file or directory to be removed
     *
     * @param string $temPath
     *
     * @return bool
     */
    public function handle($temPath = null)
    {
        $temPath = ltrim($temPath, '/');

        // find has for item
        $oldHash = FileflyHashmap::find()
            ->where(
                [
                    'filesystem' => $this->adapterName,
                    'path'       => $temPath,
                ]
            )
            ->one();

        if (empty($oldHash)) {
            \Yii::error('Could not find item [' . $temPath . '] in hash table!', __METHOD__);
            return false;
        }

        if (!$oldHash->delete()) {
            \Yii::error('Could not delete item [' . $temPath . '] in hash table!', __METHOD__);
            return false;
        }
        return true;
    }
}
