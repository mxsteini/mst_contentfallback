<?php
defined('TYPO3_MODE') || exit();

/**
 *
 */
(static function (): void {
    $extensionKey = 'mst_contentfallback';
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Core\Domain\Repository\PageRepository::class] = [
        'className' => MST\MstContentfallback\XClass\PageRepository::class
    ];

})();
