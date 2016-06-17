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
 * Class FindPermissions
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class FindPermissions implements PluginInterface
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
        return 'getPermissions';
    }

    /**
     * @param array $contents
     *
     * @return bool
     */
    public function handle(array $contents)
    {
        $files = [];

        \Yii::info($contents, 'getPermissions.$contents');

        foreach ($contents as $file) {
            $files[] = $file['path'];
        }

        \Yii::info($files, 'getPermissions.$files');

        $hashes = FileflyHashmap::find()
            ->select(['path'])
            ->andWhere(['filesystem' => $this->adapterName])
            ->andWhere(['IN', 'path', $files])
            ->asArray()
            ->all();

        \Yii::info($hashes, 'getPermissions.$hashes');

        return $hashes;
    }
}