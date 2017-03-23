<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;
use League\Flysystem\FileNotFoundException;


/**
 * Class SelfHealKit
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class SelfHealKit extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'check';
    }

    /**
     * Check if path has hash and repair with default permissions if not set
     *
     * @param string $path
     * @param bool $repair
     * @param bool $all sync filesystem with database
     *
     * @return bool
     */
    public function handle($path, $repair, $all = false)
    {
        // \hrzg\filefly\Module->repair
        if ($repair) {
            if ($all) {

                // cleanup orphan hashes
                $hashes = FileflyHashmap::find()->andWhere(['component' => $this->component])->each(100);
                foreach ($hashes as $hash) {
                    if ($hash->path === '/') {
                        continue;
                    }

                    // try to find in filesystem by hash map path
                    try {
                        $this->filesystem->get($hash->path);
                    } catch (FileNotFoundException $e) {
                        \Yii::info($e->getMessage(), __METHOD__);
                        $hash->delete();
                    }
                }

                // add missing hashes
                $allFilesystemItems = $this->filesystem->listContents('/', true);
                foreach ($allFilesystemItems as $item) {
                    $this->handleHash($item['path']);
                }
            } else {
                // ensure single hash
                $this->handleHash($path);
            }
        }
        return true;
    }

    /**
     * Create hash if not exists for path
     * @param $path
     *
     * @return bool
     */
    private function handleHash($path)
    {
        $path = $this->normalize($path);

        /** @var $hash \hrzg\filefly\models\FileflyHashmap */
        $query = FileflyHashmap::find();
        $query->andWhere(['component' => $this->component]);
        $query->andWhere(['path' => $path]);
        $hash = $query->one();

        if ($hash === null) {
            $defaultPermissions = FileflyHashmap::accessDefaults();

            // get meta information
            try {
                $meta = $this->filesystem->getMetadata($path);
                $type = (isset($meta['type'])) ? $meta['type'] : null;
                $size = (isset($meta['size'])) ? $meta['size'] : null;
            } catch (FileNotFoundException $e) {
                \Yii::error($e->getMessage(), __METHOD__);
                $type = null;
                $size = null;
            }

            if ($path === '/') {
                $type = 'root';
            }

            $repairHash         = new FileflyHashmap(
                [
                    'component'           => $this->component,
                    'type'                => $type,
                    'path'                => $path,
                    'size'                => $size,
                    Module::ACCESS_OWNER  => $defaultPermissions[Module::ACCESS_OWNER],
                    Module::ACCESS_READ   => $defaultPermissions[Module::ACCESS_READ],
                    Module::ACCESS_UPDATE => $defaultPermissions[Module::ACCESS_UPDATE],
                    Module::ACCESS_DELETE => $defaultPermissions[Module::ACCESS_DELETE],
                ]
            );
            if (!$repairHash->save()) {
                \Yii::error('filefly hash could not been repaired for path ' . $path, __METHOD__);
                return false;
            }
        }
        return true;
    }
}
