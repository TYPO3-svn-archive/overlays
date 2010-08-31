<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2008-2010 Francois Suter (Cobweb) <typo3@cobweb.ch>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


/**
 * Main library of the 'overlays' extension.
 * It aims to improve on the performance of the original overlaying mechanism provided by t3lib_page
 * and to provide a more useful API for developers
 *
 * @author		Francois Suter (Cobweb) <typo3@cobweb.ch>
 * @package		TYPO3
 * @subpackage	tx_overlays
 *
 * $Id$
 */
final class tx_overlays {

	/**
	 * This method is designed to get all the records from a given table, properly overlaid with versions and translations
	 * Its parameters are the same as t3lib_db::exec_SELECTquery()
	 * A small difference is that it will take only a single table
	 * The big difference is that it returns an array of properly overlaid records and not a result pointer
	 *
	 * @param	string		$selectFields: List of fields to select from the table. This is what comes right after "SELECT ...". Required value.
	 * @param	string		$fromTable: Table from which to select. This is what comes right after "FROM ...". Required value.
	 * @param	string		$whereClause: Optional additional WHERE clauses put in the end of the query. NOTICE: You must escape values in this argument with $this->fullQuoteStr() yourself! DO NOT PUT IN GROUP BY, ORDER BY or LIMIT!
	 * @param	string		$groupBy: Optional GROUP BY field(s), if none, supply blank string.
	 * @param	string		$orderBy: Optional ORDER BY field(s), if none, supply blank string.
	 * @param	string		$limit: Optional LIMIT value ([begin,]max), if none, supply blank string.
	 * @return	array		Fully overlaid recordset
	 */
	public static function getAllRecordsForTable($selectFields, $fromTable, $whereClause = '', $groupBy = '', $orderBy = '', $limit = '') {
			// SQL WHERE clause is the base clause passed to the function, plus language condition, plus enable fields condition
		$where = $whereClause;
		$condition = self::getLanguageCondition($fromTable);
		if (!empty($condition)) {
			if (!empty($where)) {
				$where .= ' AND ';
			}
			$where .= '(' . $condition . ')';
		}
		$condition = self::getEnableFieldsCondition($fromTable);
		if (!empty($condition)) {
			if (!empty($where)) {
				$where .= ' AND ';
			}
			$where .= '(' . $condition . ')';
		}

			// If the language is not default, prepare for overlays
		$doOverlays = FALSE;
		if ($GLOBALS['TSFE']->sys_language_content > 0) {
				// Make sure the list of selected fields includes "uid", "pid" and language fields so that language overlays can be gotten properly
				// If these do not exist in the queried table, the recordset is returned as is, without overlay
			try {
				$selectFields = self::selectOverlayFields($fromTable, $selectFields);
				$doOverlays = TRUE;
			}
			catch (Exception $e) {
				$doOverlays = FALSE;
			}
		}

			// Execute the query itself
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($selectFields, $fromTable, $where, $groupBy, $orderBy, $limit);
			// Assemble a raw recordset
		$records = array();
		while (($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res))) {
			$records[] = $row;
		}
		$GLOBALS['TYPO3_DB']->sql_free_result($res);

			// If we have both a uid and a pid field, we can proceed with overlaying the records
		if ($doOverlays) {
			$records = self::overlayRecordSet($fromTable, $records, $GLOBALS['TSFE']->sys_language_content, $GLOBALS['TSFE']->sys_language_contentOL);
		}
		return $records;
	}

	/**
	 * This method gets the SQL condition to apply for fetching the proper language
	 * depending on the localization settings in the TCA
	 *
	 * @param	string		$table: name of the table to assemble the condition for
	 * @return	string		SQL to add to the WHERE clause (without "AND")
	 */
	public static function getLanguageCondition($table) {
		$languageCondition = '';

			// First check if there's actually a TCA for the given table
		if (isset($GLOBALS['TCA'][$table]['ctrl'])) {
			$tableCtrlTCA = $GLOBALS['TCA'][$table]['ctrl'];

				// Assemble language condition only if a language field is defined
			if (!empty($tableCtrlTCA['languageField'])) {
				if (isset($GLOBALS['TSFE']->sys_language_contentOL) && isset($tableCtrlTCA['transOrigPointerField'])) {
					$languageCondition = $table . '.' . $tableCtrlTCA['languageField'] . ' IN (0,-1)'; // Default language and "all" language

						// If current language is not default, select elements that exist only for current language
						// That means elements that exist for current language but have no parent element
					if ($GLOBALS['TSFE']->sys_language_content > 0) {
						$languageCondition .= ' OR (' . $table . '.' . $tableCtrlTCA['languageField'] . " = '" . $GLOBALS['TSFE']->sys_language_content . "' AND " . $table . '.' . $tableCtrlTCA['transOrigPointerField'] . " = '0')";
					}
				} else {
					$languageCondition = $table . '.' . $tableCtrlTCA['languageField'] . " = '" . $GLOBALS['TSFE']->sys_language_content . "'";
				}
			}
		}
		return $languageCondition;
	}

	/**
	 * This method returns the condition on enable fields for the given table
	 * Basically it calls on the method provided by t3lib_page, but without the " AND " in front
	 *
	 * @param	string		$table: name of the table to build the condition for
	 * @param	boolean		$showHidden: set to TRUE to force the display of hidden records
	 * @param	array		$ignoreArray: use keys like "disabled", "starttime", "endtime", "fe_group" (i.e. keys from "enablefields" in TCA) and set values to TRUE to exclude corresponding conditions from WHERE clause
	 * @return	string		SQL to add to the WHERE clause (without "AND")
	 */
	public static function getEnableFieldsCondition($table, $showHidden = FALSE, $ignoreArray = array()) {
		$enableCondition = '';
			// First check if table has a TCA ctrl section, otherwise t3lib_page::enableFields() will die() (stupid thing!)
		if (isset($GLOBALS['TCA'][$table]['ctrl'])) {
			$showHidden = $showHidden ? $showHidden : ($table == 'pages' ? $GLOBALS['TSFE']->showHiddenPage : $GLOBALS['TSFE']->showHiddenRecords);
			$enableCondition = $GLOBALS['TSFE']->sys_page->enableFields($table, $showHidden , $ignoreArray);
				// If an enable clause was returned, strip the first ' AND '
			if (!empty($enableCondition)) {
				$enableCondition = substr($enableCondition, strlen(' AND '));
			}
		}
			// TODO: throw an exception if the given table has no TCA? (t3lib_page::enableFields() used a die)
		return $enableCondition;
	}

	/**
	 * This method makes sure that all the fields necessary for proper overlaying are included
	 * in the list of selected fields and exist in the table being queried
	 * If not, it throws an exception
	 *
	 * @param	string		$table: Table from which to select. This is what comes right after "FROM ...". Required value.
	 * @param	string		$selectFields: List of fields to select from the table. This is what comes right after "SELECT ...". Required value.
	 * @return	string		Possibly modified list of fields to select
	 */
	public static function selectOverlayFields($table, $selectFields) {
		$select = $selectFields;

			// Check if the table indeed has a TCA
		if (isset($GLOBALS['TCA'][$table]['ctrl'])) {

				// If the table uses a foreign table for translations, there are no fields to add
				// Return original select fields directly
			if ($GLOBALS['TCA'][$table]['ctrl']['transForeignTable']) {
				return $selectFields;
			} else {
				$languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'];

					// In order to be properly overlaid, a table has to have a uid, a pid and languageField
				$hasUidField = strpos($selectFields, 'uid');
				$hasPidField = strpos($selectFields, 'pid');
				$hasLanguageField = strpos($selectFields, $languageField);
				if ($hasUidField === FALSE || $hasPidField === FALSE || $hasLanguageField === FALSE) {
					$availableFields = $GLOBALS['TYPO3_DB']->admin_get_fields($table);
					if (isset($availableFields['uid'])) {
						if ($selectFields != '*') {
							$select .= ', ' . $table . '.uid';
						}
						$hasUidField = TRUE;
					}
					if (isset($availableFields['pid'])) {
						if ($selectFields != '*') {
							$select .= ', ' . $table . '.pid';
						}
						$hasPidField = TRUE;
					}
					if (isset($availableFields[$languageField])) {
						if ($selectFields != '*') {
							$select .= ', ' . $table . '.'.$languageField;
						}
						$hasLanguageField = TRUE;
					}
				}
					// If one of the fields is still missing after that, throw an exception
				if ($hasUidField === FALSE || $hasPidField === FALSE || $hasLanguageField === FALSE) {
					throw new Exception('Not all overlay fields available.');

					// Else return the modified list of fields to select
				} else {
					return $select;
				}
			}

			// The table has no TCA, throw an exception
		} else {
			throw new Exception('No TCA for table, cannot add overlay fields.');
		}
	}

	/**
	 * Creates language-overlay for records in general (where translation is found in records from the same table)
	 * This is originally copied from t3lib_page::getRecordOverlay()
	 *
	 * @param	string		$table: Table name
	 * @param	array		$recordset: Full recordset to overlay. Must containt uid, pid and $TCA[$table]['ctrl']['languageField']
	 * @param	integer		$currentLanguage: Uid of the currently selected language in the FE
	 * @param	string		$overlayMode: Overlay mode. If "hideNonTranslated" then records without translation will not be returned un-translated but removed instead.
	 * @return	array		Returns the full overlaid recordset. If $overlayMode is "hideNonTranslated" then some records may be missing if no translation was found.
	 */
	public static function overlayRecordSet($table, $recordset, $currentLanguage, $overlayMode = '') {

			// Test with the first row if uid and pid fields are present
		if (!empty($recordset[0]['uid']) && !empty($recordset[0]['pid'])) {

				// Test if the table has a TCA definition
			if (isset($GLOBALS['TCA'][$table])) {
				$tableCtrl = $GLOBALS['TCA'][$table]['ctrl'];

					// Test if the TCA definition includes translation information for the same table
				if (isset($tableCtrl['languageField']) && isset($tableCtrl['transOrigPointerField'])) {

						// Test with the first row if languageField is present
					if (isset($recordset[0][$tableCtrl['languageField']])) {

							// Filter out records that are not in the default or [ALL] language, should there be any
						$filteredRecordset = array();
						foreach ($recordset as $row) {
							if ($row[$tableCtrl['languageField']] <= 0) {
								$filteredRecordset[] = $row;
							}
						}
							// Will try to overlay a record only if the sys_language_content value is larger than zero,
							// that is, it is not default or [ALL] language
						if ($currentLanguage > 0) {
								// Assemble a list of uid's for getting the overlays,
								// but only from the filtered recordset
							$uidList = array();
							foreach ($filteredRecordset as $row) {
								$uidList[] = $row['uid'];
							}

								// Get all overlay records
							$overlays = self::getLocalOverlayRecords($table, $uidList, $currentLanguage);

								// Now loop on the filtered recordset and try to overlay each record
							$overlaidRecordset = array();
							foreach ($recordset as $row) {
									// If record is already in the right language, keep it as is
								if ($row[$tableCtrl['languageField']] == $currentLanguage) {
									$overlaidRecordset[] = $row;

									// Else try to apply an overlay
								} elseif (isset($overlays[$row['uid']][$row['pid']])) {
									$overlaidRecordset[] = self::overlaySingleRecord($table, $row, $overlays[$row['uid']][$row['pid']]);

									// No overlay exists, apply relevant translation rules
								} else {
										// Take original record, only if non-translated are not hidden, or if language is [All]
									if ($overlayMode != 'hideNonTranslated' || $row[$tableCtrl['languageField']] == -1) {
										$overlaidRecordset[] = $row;
									}
								}
							}
								// Return the overlaid recordset
							return $overlaidRecordset;

						} else {
								// When default language is displayed, we never want to return a record carrying another language!
								// Return the filtered recordset
							return $filteredRecordset;
						}

						// Provided recordset does not contain languageField field, return recordset unchanged
					} else {
						return $recordset;
					}

					// Test if the TCA definition includes translation information for a foreign table
				} elseif (isset($tableCtrl['transForeignTable'])) {
						// The foreign table has a TCA structure. We can proceed.
					if (isset($GLOBALS['TCA'][$tableCtrl['transForeignTable']])) {
						$foreignCtrl = $GLOBALS['TCA'][$tableCtrl['transForeignTable']]['ctrl'];
							// Check that the foreign table is indeed the appropriate translation table
							// and also check that the foreign table has all the necessary TCA definitions
						if (!empty($foreignCtrl['transOrigPointerTable']) && $foreignCtrl['transOrigPointerTable'] == $table && !empty($foreignCtrl['transOrigPointerField']) && !empty($foreignCtrl['languageField'])) {
								// Assemble a list of all uid's of records to translate
							$uidList = array();
							foreach ($recordset as $row) {
								$uidList[] = $row['uid'];
							}

								// Get all overlay records
							$overlays = $this->getForeignOverlayRecords($tableCtrl['transForeignTable'], $uidList, $currentLanguage);

								// Now loop on the filtered recordset and try to overlay each record
							$overlaidRecordset = array();
							foreach ($recordset as $row) {
									// An overlay exists, apply it
								if (isset($overlays[$row['uid']])) {
									$overlaidRecordset[] = self::overlaySingleRecord($table, $row, $overlays[$row['uid']][$row['pid']]);

									// No overlay exists
								} else {
										// Take original record, only if non-translated are not hidden
									if ($overlayMode != 'hideNonTranslated') {
										$overlaidRecordset[] = $row;
									}
								}
							}
								// Return the overlaid recordset
							return $overlaidRecordset;
						}

						// The foreign table has no TCA definition, it's impossible to perform overlays
						// Return recordset as is
					} else {
						return $recordset;
					}

					// No appropriate language fields defined in TCA, return recordset unchanged
				} else {
					return $recordset;
				}

				// No TCA for table, return recordset unchanged
			} else {
				return $recordset;
			}
		}
			// Recordset did not contain uid or pid field, return recordset unchanged
		else {
			return $recordset;
		}
	}

	/**
	 * This method is a wrapper around getLocalOverlayRecords() and getForeignOverlayRecords().
	 * It makes it possible to use the same call whether translations are in the same table or
	 * in a foreign table. This method dispatches accordingly.
	 * 
	 * @param	string		$table: name of the table for which to fetch the records
	 * @param	array		$uids: array of all uid's of the original records for which to fetch the translation
	 * @param	integer		$currentLanguage: uid of the system language to translate to
	 * @return	array		All overlay records arranged per original uid and per pid, so that they can be checked (this is related to workspaces)
	 */
	public static function getOverlayRecords($table, $uids, $currentLanguage) {
		if (is_array($uids) && count($uids) > 0) {
			if (isset($GLOBALS['TCA'][$table]['ctrl']['transForeignTable'])) {
				return self::getForeignOverlayRecords($GLOBALS['TCA'][$table]['ctrl']['transForeignTable'], $uids, $currentLanguage);
			} else {
				return self::getLocalOverlayRecords($table, $uids, $currentLanguage);
			}
		} else {
			return array();
		}
	}

	/**
	 * This method is used to retrieve all the records for overlaying other records
	 * when those records are stored in the same table as the originals
	 *
	 * @param	string		$table: name of the table for which to fetch the records
	 * @param	array		$uids: array of all uid's of the original records for which to fetch the translation
	 * @param	integer		$currentLanguage: uid of the system language to translate to
	 * @return	array		All overlay records arranged per original uid and per pid, so that they can be checked (this is related to workspaces)
	 */
	public static function getLocalOverlayRecords($table, $uids, $currentLanguage) {
		$overlays = array();
		if (is_array($uids) && count($uids) > 0) {
			$tableCtrl = $GLOBALS['TCA'][$table]['ctrl'];
				// Select overlays for all records
			$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
				'*',
				$table,
					$tableCtrl['languageField'].' = '.intval($currentLanguage).
					' AND ' . $tableCtrl['transOrigPointerField'] . ' IN (' . implode(', ', $uids) . ')' .
					' AND ' . self::getEnableFieldsCondition($table)
			);
				// Arrange overlay records according to transOrigPointerField, so that it's easy to relate them to the originals
				// This structure is actually a 2-dimensional array, with the pid as the second key
				// Because of versioning, there may be several overlays for a given original and matching the pid too
				// ensures that we are refering to the correct overlay
			while (($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res))) {
				if (!isset($overlays[$row[$tableCtrl['transOrigPointerField']]])) {
					$overlays[$row[$tableCtrl['transOrigPointerField']]] = array();
				}
				$overlays[$row[$tableCtrl['transOrigPointerField']]][$row['pid']] = $row;
			}
			$GLOBALS['TYPO3_DB']->sql_free_result($res);
		}
		return $overlays;
	}

	/**
	 * This method is used to retrieve all the records for overlaying other records
	 * when those records are stored in a different table than the originals
	 *
	 * @param	string		$table: name of the table for which to fetch the records
	 * @param	array		$uids: array of all uid's of the original records for which to fetch the translation
	 * @param	integer		$currentLanguage: uid of the system language to translate to
	 * @return	array		All overlay records arranged per original uid and per pid, so that they can be checked (this is related to workspaces)
	 */
	public static function getForeignOverlayRecords($table, $uids, $currentLanguage) {
		$overlays = array();
		if (is_array($uids) && count($uids) > 0) {
			$tableCtrl = $GLOBALS['TCA'][$table]['ctrl'];
				// Select overlays for all records
			$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
				'*',
				$table,
					$tableCtrl['languageField'].' = '.intval($currentLanguage).
					' AND ' . $tableCtrl['transOrigPointerField'] . ' IN (' . implode(', ', $uids) . ')' .
					' AND ' . self::getEnableFieldsCondition($table)
			);
				// Arrange overlay records according to transOrigPointerField, so that it's easy to relate them to the originals
			while (($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res))) {
				$overlays[$row[$tableCtrl['transOrigPointerField']]] = $row;
			}
			$GLOBALS['TYPO3_DB']->sql_free_result($res);
		}
		return $overlays;
	}

	/**
	 * This method takes a record and its overlay and performs the overlay according to active translation rules
	 * This piece of code is extracted from t3lib_page::getRecordOverlay()
	 *
	 * @param	string	$table: name of the table for which the operation is taking place
	 * @param	array	$record: record to overlay
	 * @param	array	$overlay: overlay of the record
	 * @return	array	Overlaid record
	 */
	public static function overlaySingleRecord($table, $record, $overlay) {
		$overlaidRecord = $record;
		$overlaidRecord['_LOCALIZED_UID'] = $overlay['uid'];
		foreach ($record as $key => $value) {
			if ($key != 'uid' && $key != 'pid' && isset($overlay[$key])) {
				if (isset($GLOBALS['TSFE']->TCAcachedExtras[$table]['l10n_mode'][$key])) {
					if ($GLOBALS['TSFE']->TCAcachedExtras[$table]['l10n_mode'][$key] != 'exclude'
							&& ($GLOBALS['TSFE']->TCAcachedExtras[$table]['l10n_mode'][$key] != 'mergeIfNotBlank' || strcmp(trim($overlay[$key]), ''))) {
						$overlaidRecord[$key] = $overlay[$key];
					}
				} else {
					$overlaidRecord[$key] = $overlay[$key];
				}
			}
		}
		return $overlaidRecord;
	}
}
?>