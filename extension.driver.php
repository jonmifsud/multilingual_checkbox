<?php

	if( !defined('__IN_SYMPHONY__') ) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');



	require_once(EXTENSIONS.'/textboxfield/extension.driver.php');



	define_safe(MCB_NAME, 'Field: Multilingual Checkbox');
	define_safe(MB_GROUP, 'multilingual_checkbox');



	Class Extension_Multilingual_Checkbox extends Extension
	{

		const FIELD_TABLE = 'tbl_fields_multilingual_checkbox';



		/*------------------------------------------------------------------------------------------------*/
		/*  Installation  */
		/*------------------------------------------------------------------------------------------------*/

		public function install(){
			Symphony::Database()->query(sprintf("
				CREATE TABLE IF NOT EXISTS `%s` (
					`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`field_id` INT(11) UNSIGNED NOT NULL,
					`default_state` enum('on','off') NOT NULL default 'off',
					`description` VARCHAR(255) DEFAULT NULL,
					`def_ref_lang` ENUM('yes','no') DEFAULT 'no',
					PRIMARY KEY (`id`),
					KEY `field_id` (`field_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;",
				self::FIELD_TABLE
			));

			return true;
		}

		public function update($prev_version){
			return true;
		}

		public function uninstall(){
			Symphony::Database()->query(sprintf(
				"DROP TABLE `%s`",
				self::FIELD_TABLE
			));

			return true;
		}

		private function __createHandle($value, $entry_id, $lang, $tbl){

			$handle = Lang::createHandle(strip_tags(html_entity_decode($value)));

			if( $this->__isHandleLocked($handle, $entry_id, $lang, $tbl) ){
				$count = 2;

				while( $this->__isHandleLocked("{$handle}-{$count}", $entry_id, $lang, $tbl) ) $count++;

				return "{$handle}-{$count}";
			}

			return $handle;
		}

		private function __isHandleLocked($handle, $entry_id, $lang, $tbl){
			return (boolean)Symphony::Database()->fetchVar('id', 0, sprintf(
				"
				SELECT
					f.id
				FROM
					`{$tbl}` AS f
				WHERE
					f.`handle-{$lang}` = '%s'
					%s
				LIMIT 1
			",
				$handle,
				(!is_null($entry_id) ? "AND f.entry_id != '{$entry_id}'" : '')
			));
		}


		/*------------------------------------------------------------------------------------------------*/
		/*  Delegates  */
		/*------------------------------------------------------------------------------------------------*/

		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'dAddCustomPreferenceFieldsets'
				),
				array(
					'page' => '/extensions/frontend_localisation/',
					'delegate' => 'FLSavePreferences',
					'callback' => 'dFLSavePreferences'
				),
			);
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  System preferences  */
		/*------------------------------------------------------------------------------------------------*/

		/**
		 * Display options on Preferences page.
		 *
		 * @param array $context
		 */
		public function dAddCustomPreferenceFieldsets($context){
			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(new XMLElement('legend', __(MCB_NAME)));

			$label = Widget::Label(__('Consolidate entry data'));
			$label->appendChild(Widget::Input('settings['.MB_GROUP.'][consolidate]', 'yes', 'checkbox', array('checked' => 'checked')));
			$group->appendChild($label);
			$group->appendChild(new XMLElement('p', __('Check this field if you want to consolidate database by <b>keeping</b> entry values of removed/old Language Driver language codes. Entry values of current language codes will not be affected.'), array('class' => 'help')));

			$context['wrapper']->appendChild($group);
		}

		/**
		 * Save options from Preferences page
		 *
		 * @param array $context
		 */
		public function dFLSavePreferences($context){
			$fields = Symphony::Database()->fetch(sprintf('SELECT `field_id` FROM `%s`', self::FIELD_TABLE));

			if( $fields ){
				// Foreach field check multilanguage values foreach language
				foreach( $fields as $field ){
					$entries_table = 'tbl_entries_data_'.$field["field_id"];

					try{
						$show_columns = Symphony::Database()->fetch("SHOW COLUMNS FROM `{$entries_table}` LIKE 'value-%';");
					}
					catch( DatabaseException $dbe ){
						// Field doesn't exist. Better remove it's settings
						Symphony::Database()->query(sprintf(
								"DELETE FROM `%s` WHERE `field_id` = %s;",
								self::FIELD_TABLE, $field["field_id"])
						);
						continue;
					}

					$columns = array();

					// Remove obsolete fields
					if( $show_columns ){
						foreach( $show_columns as $column ){
							$lc = substr($column['Field'], strlen($column['Field']) - 2);

							// If not consolidate option AND column lang_code not in supported languages codes -> Drop Column
							if( ($_POST['settings'][MB_GROUP]['consolidate'] !== 'yes') && !in_array($lc, $context['new_langs']) ){
								Symphony::Database()->query(sprintf("
									ALTER TABLE `%s`
										DROP COLUMN `value-{$lc}`",
									$entries_table));
							} else{
								$columns[] = $column['Field'];
							}
						}
					}

					$fieldObject = FieldManager::fetch($field["field_id"]);
					$default_state = $fieldObject->get('default_state');
					// Add new fields
					foreach( $context['new_langs'] as $lc ){
						// If column lang_code dosen't exist in the laguange drop columns
						if( !in_array('value-'.$lc, $columns) ){
							Symphony::Database()->query(sprintf("
								ALTER TABLE `%s`
									ADD COLUMN `value-{$lc}` enum('yes','no') NOT NULL default '".($default_state == 'on' ? 'yes' : 'no')."'",
								$entries_table));
						}
					}

				}
			}
		}
	}
