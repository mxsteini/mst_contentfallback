<?php

namespace MST\MstContentfallback\XClass;

use Psr\Log\LoggerAwareInterface;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendWorkspaceRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepositoryGetRecordOverlayHookInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

class PageRepository extends \TYPO3\CMS\Core\Domain\Repository\PageRepository implements LoggerAwareInterface
{

    /**
     * Creates language-overlay for records in general (where translation is found
     * in records from the same table)
     *
     * @param string $table Table name
     * @param array $row Record to overlay. Must contain uid, pid and $table]['ctrl']['languageField']
     * @param LanguageAspect|int|null $sys_language_content Pointer to the sys_language uid for content on the site.
     * @param string $OLmode Overlay mode. If "hideNonTranslated" then records without translation will not be returned  un-translated but unset (and return value is NULL)
     * @throws \UnexpectedValueException
     * @return mixed Returns the input record, possibly overlaid with a translation.  But if $OLmode is "hideNonTranslated" then it will return NULL if no translation is found.
     */
    public function getRecordOverlay($table, $row, $sys_language_content = null, $OLmode = '')
    {
        if ($sys_language_content === null) {
            $sys_language_content = $this->context->getAspect('language');
        }
        if ($sys_language_content instanceof LanguageAspect) {
            // Early return when no overlays are needed
            if ($sys_language_content->getOverlayType() === $sys_language_content::OVERLAYS_OFF) {
                return $row;
            }
            $OLmode = $sys_language_content->getOverlayType() === $sys_language_content::OVERLAYS_MIXED ? '1' : 'hideNonTranslated';
            $sys_language_content = $sys_language_content->getContentId();
        }
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_page.php']['getRecordOverlay'] ?? [] as $className) {
            $hookObject = GeneralUtility::makeInstance($className);
            if (!$hookObject instanceof PageRepositoryGetRecordOverlayHookInterface) {
                throw new \UnexpectedValueException($className . ' must implement interface ' . PageRepositoryGetRecordOverlayHookInterface::class, 1269881658);
            }
            $hookObject->getRecordOverlay_preProcess($table, $row, $sys_language_content, $OLmode, $this);
        }

        $tableControl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];

        if (!empty($tableControl['languageField'])
            // Return record for ALL languages untouched
            // @todo: Fix call stack to prevent this situation in the first place
            && (int)$row[$tableControl['languageField']] !== -1
            && !empty($tableControl['transOrigPointerField'])
            && $row['uid'] > 0
            && ($row['pid'] > 0 || in_array($tableControl['rootLevel'] ?? false, [true, 1, -1], true))) {
            // Will try to overlay a record only if the sys_language_content value is larger than zero.
            if ($sys_language_content > 0) {
                // Must be default language, otherwise no overlaying
                if ((int)$row[$tableControl['languageField']] === 0) {
                    // Select overlay record:
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getQueryBuilderForTable($table);
                    $queryBuilder->setRestrictions(
                        GeneralUtility::makeInstance(FrontendRestrictionContainer::class, $this->context)
                    );
                    if ($this->context->getPropertyFromAspect('workspace', 'id', 0) > 0) {
                        // If not in live workspace, remove query based "enable fields" checks, it will be done in versionOL()
                        // @see functional workspace test createLocalizedNotHiddenWorkspaceContentHiddenInLive()
                        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
                        $queryBuilder->getRestrictions()->removeByType(StartTimeRestriction::class);
                        $queryBuilder->getRestrictions()->removeByType(EndTimeRestriction::class);
                        // We remove the FrontendWorkspaceRestriction in this case, because we need to get the LIVE record
                        // of the language record before doing the version overlay of the language again. WorkspaceRestriction
                        // does this for us, PLUS we need to ensure to get a possible LIVE record first (that's why
                        // the "orderBy" query is there, so the LIVE record is found first), as there might only be a
                        // versioned record (e.g. new version) or both (common for modifying, moving etc).
                        if ($this->hasTableWorkspaceSupport($table)) {
                            $queryBuilder->getRestrictions()->removeByType(FrontendWorkspaceRestriction::class);
                            $queryBuilder->getRestrictions()->add(
                                GeneralUtility::makeInstance(WorkspaceRestriction::class,
                                $this->context->getPropertyFromAspect('workspace', 'id', 0)));
                            $queryBuilder->orderBy('t3ver_wsid', 'ASC');
                        }
                    }

                    $pid = $row['pid'];
                    // When inside a workspace, the already versioned $row of the default language is coming in
                    // For moved versioned records, the PID MIGHT be different. However, the idea of this function is
                    // to get the language overlay of the LIVE default record, and afterwards get the versioned record
                    // the found (live) language record again, see the versionOL() call a few lines below.
                    // This means, we need to modify the $pid value for moved records, as they might be on a different
                    // page and use the PID of the LIVE version.
                    if (isset($row['_ORIG_pid']) && $this->hasTableWorkspaceSupport($table) && VersionState::cast($row['t3ver_state'] ?? 0)->equals(VersionState::MOVE_POINTER)) {
                        $pid = $row['_ORIG_pid'];
                    }

                    $languageAspect = $this->context->getAspect('language');
                    $fallbackChain = $languageAspect->get('fallbackChain');
                    // MST: append fallbackchain to requested language
                    $fallbackChain = [$sys_language_content, ...$fallbackChain];

                    // MST: load content by fallbackChain
                    foreach ($fallbackChain as $overlay_language_content) {
                        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
                        $olrow = $queryBuilder->select('*')
                            ->from($table)
                            ->where(
                                $queryBuilder->expr()->eq(
                                    'pid',
                                    $queryBuilder->createNamedParameter($pid, \PDO::PARAM_INT)
                                ),
                                $queryBuilder->expr()->eq(
                                    $tableControl['languageField'],
                                    $queryBuilder->createNamedParameter($overlay_language_content, \PDO::PARAM_INT)
                                ),
                                $queryBuilder->expr()->eq(
                                    $tableControl['transOrigPointerField'],
                                    $queryBuilder->createNamedParameter($row['uid'], \PDO::PARAM_INT)
                                )
                            )
                            ->setMaxResults(1)
                            ->executeQuery()
                            ->fetchAssociative();

                        // MST: exit if somthing is found
                        if (is_array($olrow) && isset($GLOBALS['TCA'][$table]['ctrl']['enablecolumns']) && isset($GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'])) {
                            if ($olrow[$GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled']] &&
                                $olrow[$tableControl['languageField']] == $sys_language_content) {
                                $olrow = null;
                                $row = null;
                                break;
                            }
                            if (is_array($olrow) && !$olrow[$GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled']]) {
                                break;
                            }
                        }
                        if (is_array($olrow) && !isset($GLOBALS['TCA'][$table]['ctrl']['enablecolumns'])) {
                            break;
                        }
                    }

                    $this->versionOL($table, $olrow);
                    // Merge record content by traversing all fields:
                    if (is_array($olrow)) {
                        if (isset($olrow['_ORIG_uid'])) {
                            $row['_ORIG_uid'] = $olrow['_ORIG_uid'];
                        }
                        if (isset($olrow['_ORIG_pid'])) {
                            $row['_ORIG_pid'] = $olrow['_ORIG_pid'];
                        }
                        foreach ($row as $fN => $fV) {
                            if ($fN !== 'uid' && $fN !== 'pid' && array_key_exists($fN, $olrow)) {
                                $row[$fN] = $olrow[$fN];
                            } elseif ($fN === 'uid') {
                                $row['_LOCALIZED_UID'] = $olrow['uid'];
                            }
                        }
                    } elseif ($OLmode === 'hideNonTranslated' && (int)$row[$tableControl['languageField']] === 0) {
                        // Unset, if non-translated records should be hidden. ONLY done if the source
                        // record really is default language and not [All] in which case it is allowed.
                        $row = null;
                    }
                } elseif ($sys_language_content != $row[$tableControl['languageField']]) {
                    $row = null;
                }
            } else {
                // When default language is displayed, we never want to return a record carrying
                // another language!
                if ($row[$tableControl['languageField']] > 0) {
                    $row = null;
                }
            }
        }

        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_page.php']['getRecordOverlay'] ?? [] as $className) {
            $hookObject = GeneralUtility::makeInstance($className);
            if (!$hookObject instanceof PageRepositoryGetRecordOverlayHookInterface) {
                throw new \UnexpectedValueException($className . ' must implement interface ' . PageRepositoryGetRecordOverlayHookInterface::class, 1269881659);
            }
            $hookObject->getRecordOverlay_postProcess($table, $row, $sys_language_content, $OLmode, $this);
        }

        return $row;
    }
}
