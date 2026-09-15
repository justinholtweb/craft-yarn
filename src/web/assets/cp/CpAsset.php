<?php

namespace justinholtweb\yarn\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * Styles for every Yarn screen, and the map's renderer.
 *
 * No dependencies. A force layout and an SVG are about two hundred lines of arithmetic, and
 * shipping a graph library to draw them would be a megabyte of somebody else's release schedule.
 */
class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [CraftCpAsset::class];

    public $js = ['yarn-cp.js'];

    public $css = ['yarn-cp.css'];
}
