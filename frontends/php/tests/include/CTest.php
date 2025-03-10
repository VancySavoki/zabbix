<?php
/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

require_once 'vendor/autoload.php';

require_once dirname(__FILE__).'/../../include/defines.inc.php';
require_once dirname(__FILE__).'/../../include/hosts.inc.php';

require_once dirname(__FILE__).'/helpers/CDBHelper.php';
require_once dirname(__FILE__).'/helpers/CAPIHelper.php';
require_once dirname(__FILE__).'/helpers/CExceptionHelper.php';
require_once dirname(__FILE__).'/helpers/CTestArrayHelper.php';

define('USER_ACTION_ADD', 'add');
define('USER_ACTION_UPDATE', 'update');
define('USER_ACTION_REMOVE', 'remove');

/**
 * Base class of php unit tests.
 */
class CTest extends PHPUnit_Framework_TestCase {

	// Table that should be backed up at the test suite level.
	protected static $suite_backup = null;
	// Table that should be backed up at the test case level.
	protected $case_backup = null;
	// Table that should be backed up at the test case level once (for multiple case executions).
	protected static $case_backup_once = null;
	// Name of the last executed test.
	protected static $last_test_case = null;
	// Test case data key.
	protected $data_key = null;
	// Lists of test case data set keys.
	protected static $test_data_sets = [];
	// List of backups to be performed.
	protected $backup = [];
	// Test case annotations.
	protected $annotations = null;
	// Test case warnings.
	protected static $warnings = [];
	// Skip test suite execution.
	protected static $skip_suite = false;

	/**
	 * Overriden constructor for collecting data on data sets from dataProvider annotations.
	 *
	 * @param string $name
	 * @param array  $data
	 * @param string $data_name
	 */
	public function __construct($name = null, array $data = [], $data_name = '') {
		parent::__construct($name, $data, $data_name);

		// If data limits are enabled and test case uses data.
		if (defined('PHPUNIT_ENABLE_DATA_LIMITS') && PHPUNIT_ENABLE_DATA_LIMITS && $data) {
			$this->data_key = $data_name;
			self::$test_data_sets[$name][] = $data_name;
		}
	}

	/**
	 * Get annotations by type name.
	 * Helper function for method / class annotation processing.
	 *
	 * @param array  $annotations    annotations
	 * @param string $type	         type name
	 *
	 * @return array or null
	 */
	protected function getAnnotationsByType($annotations, $type) {
		if ($annotations === null || !array_key_exists($type, $annotations) || !is_array($annotations[$type])) {
			return null;
		}

		return $annotations[$type];
	}

	/**
	 * Get annotation tokens by annotation name.
	 * Helper function for method / class annotation processing.
	 *
	 * @param array  $annotations    annotations
	 * @param string $name	         annotation name
	 *
	 * @return array
	 */
	protected function getAnnotationTokensByName($annotations, $name) {
		if ($annotations === null || !array_key_exists($name, $annotations) || !is_array($annotations[$name])) {
			return [];
		}

		$result = [];
		foreach ($annotations[$name] as $annotation) {
			foreach (explode(',', $annotation) as $token) {
				$result[] = trim($token);
			}
		}

		return $result;
	}

	/**
	 * Callback executed before every test suite.
	 */
	protected function onBeforeTestSuite() {
		// Test suite level annotations.
		$class_annotations = $this->getAnnotationsByType($this->annotations, 'class');

		// Backup performed before test suite execution.
		$suite_backup = $this->getAnnotationTokensByName($class_annotations, 'backup');

		if ($suite_backup) {
			self::$suite_backup = $suite_backup;
			CDBHelper::backupTables(self::$suite_backup);
		}

		self::$skip_suite = false;
	}

	/**
	 * Callback executed before every test case.
	 *
	 * @before
	 */
	public function onBeforeTestCase() {
		global $DB;
		static $suite = null;
		$class_name = get_class($this);
		$case_name = $this->getName(false);
		$this->backup = [];
		self::$warnings = [];

		// Clear contents of error log.
		@file_put_contents(PHPUNIT_ERROR_LOG, '');

		if (!isset($DB['DB'])) {
			DBconnect($error);
		}

		$this->annotations = $this->getAnnotations();

		// Restore data from backup if test case changed
		if (self::$case_backup_once !== null && self::$last_test_case !== $case_name) {
			CDBHelper::restoreTables();
			self::$case_backup_once = null;
		}

		// Test case level annotations.
		$method_annotations = $this->getAnnotationsByType($this->annotations, 'method');

		if ($method_annotations !== null) {
			// Backup performed before every test case execution.
			$case_backup = $this->getAnnotationTokensByName($method_annotations, 'backup');

			if ($case_backup) {
				$this->case_backup = $case_backup;
				CDBHelper::backupTables($this->case_backup);
			}

			if (self::$last_test_case !== $case_name) {
				if (array_key_exists($case_name, self::$test_data_sets)) {
					// Check for data data set limit.
					$limit = $this->getAnnotationTokensByName($method_annotations, 'data-limit');

					if (count($limit) === 1 && is_numeric($limit[0]) && $limit[0] >= 1
							&& count(self::$test_data_sets[$case_name]) > $limit[0]) {
						$sets = self::$test_data_sets[$case_name];
						shuffle($sets);
						self::$test_data_sets[$case_name] = array_slice($sets, 0, (int)$limit[0]);
					}
				}

				// Backup performed once before first test case execution.
				$case_backup_once = $this->getAnnotationTokensByName($method_annotations, 'backup-once');

				if ($case_backup_once) {
					self::$case_backup_once = $case_backup_once;
					CDBHelper::backupTables(self::$case_backup_once);
				}
			}
		}

		// Class name change is used to determine suite change.
		if ($suite !== $class_name) {
			$suite = $class_name;
			$this->onBeforeTestSuite();
		}

		self::$last_test_case = $case_name;

		// Mark excessive test cases as skipped.
		if (array_key_exists($case_name, self::$test_data_sets)
				&& !in_array($this->data_key, self::$test_data_sets[$case_name])) {
			self::markTestSkipped('Test case skipped by data provider limit check.');
		}

		if (self::$skip_suite) {
			self::markTestSkipped();
		}
	}

	/**
	 * Callback executed after every test case.
	 *
	 * @after
	 */
	public function onAfterTestCase() {
		if ($this->case_backup !== null) {
			CDBHelper::restoreTables();
		}

		DBclose();

		if (self::$warnings) {
			throw new PHPUnit_Framework_Warning(implode("\n", self::$warnings));
		}

		if (($errors = @file_get_contents(PHPUNIT_ERROR_LOG))) {
			$this->fail("Runtime errors:\n".$errors);
		}
	}

	/**
	 * Callback executed after every test suite.
	 *
	 * @afterClass
	 */
	public static function onAfterTestSuite() {
		global $DB;

		if (self::$suite_backup !== null || self::$case_backup_once !== null) {
			DBconnect($error);

			// Restore case level backups.
			if (self::$case_backup_once !== null) {
				CDBHelper::restoreTables();
				self::$case_backup_once = null;
			}

			// Restore suite level backups.
			if (self::$suite_backup !== null) {
				CDBHelper::restoreTables();
				self::$suite_backup = null;
			}

			DBclose();
		}
	}

	/**
	 * Add warning to test case warning list.
	 *
	 * @param string $warning    warning text
	 */
	public static function addWarning($warning) {
		if (defined('PHPUNIT_REPORT_WARNINGS') && PHPUNIT_REPORT_WARNINGS && !in_array($warning, self::$warnings)) {
			self::$warnings[] = $warning;
		}
	}

	/**
	 * Mark test suite skipped.
	 */
	public static function markTestSuiteSkipped() {
		self::$skip_suite = true;
	}
}
