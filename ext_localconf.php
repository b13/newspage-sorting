<?php

defined('TYPO3') or die();

(function () {
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
        B13\NewspageSorting\Hooks\SortNews::class;
})();
