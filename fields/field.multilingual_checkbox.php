<?php
	if( !defined('__IN_SYMPHONY__') ) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');



	require_once(TOOLKIT.'/class.xsltprocess.php');
	require_once(EXTENSIONS.'/frontend_localisation/lib/class.FLang.php');
	require_once(TOOLKIT.'/fields/field.checkbox.php');



	Class fieldMultilingual_Checkbox extends FieldCheckbox
	{

		/*------------------------------------------------------------------------------------------------*/
		/*  Definition  */
		/*------------------------------------------------------------------------------------------------*/

		public function __construct(){
			parent::__construct();

			$this->_name = __('Multilingual Checkbox');
		}

		public function toggleFieldData(array $data, $newState, $entry_id=null){
			$author = Administration::instance()->Author;
			$lang = $author->get('language');
			$data["value-{$lang}"] = $newState;
			return $data;
		}

		public function createTable(){
			$field_id = $this->get('id');

			$query = "
				CREATE TABLE IF NOT EXISTS `tbl_entries_data_{$field_id}` (
					`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`entry_id` INT(11) UNSIGNED NOT NULL,
					`value` enum('yes','no') NOT NULL default '".($this->get('default_state') == 'on' ? 'yes' : 'no')."',";

			foreach( FLang::getLangs() as $lc )
				$query .= "
				    `value-{$lc}` TEXT default NULL,";

			$query .= "
					PRIMARY KEY (`id`),
					KEY `entry_id` (`entry_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";

			return Symphony::Database()->query($query);
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Utilities  */
		/*------------------------------------------------------------------------------------------------*/

		public function createHandle($value, $entry_id, $lang_code = null){
			if( !FLang::validateLangCode($lang_code) ) $lang_code = FLang::getLangCode();

			$handle = Lang::createHandle(strip_tags(html_entity_decode($value)));

			if( $this->isHandleLocked($handle, $entry_id, $lang_code) ){
				if( $this->isHandleFresh($handle, $value, $entry_id, $lang_code) ){
					return $this->getCurrentHandle($entry_id, $lang_code);
				}

				else{
					$count = 2;

					while( $this->isHandleLocked("{$handle}-{$count}", $entry_id, $lang_code) ) $count++;

					return "{$handle}-{$count}";
				}
			}

			return $handle;
		}

		public function getCurrentHandle($entry_id, $lang_code){
			return Symphony::Database()->fetchVar('handle', 0, sprintf(
				"
					SELECT
						f.`handle-%s`
					FROM
						`tbl_entries_data_%s` AS f
					WHERE
						f.entry_id = '%s'
					LIMIT 1
				",
				$lang_code,
				$this->get('id'),
				$entry_id
			));
		}

		public function isHandleLocked($handle, $entry_id, $lang_code){
			return (boolean)Symphony::Database()->fetchVar('id', 0, sprintf(
				"
					SELECT
						f.id
					FROM
						`tbl_entries_data_%s` AS f
					WHERE
						f.`handle-%s` = '%s'
						%s
					LIMIT 1
				",
				$this->get('id'), $lang_code, $handle,
				(!is_null($entry_id) ? "AND f.entry_id != '{$entry_id}'" : '')
			));
		}

		public function isHandleFresh($handle, $value, $entry_id, $lang_code){
			return (boolean)Symphony::Database()->fetchVar('id', 0, sprintf(
				"
					SELECT
						f.id
					FROM
						`tbl_entries_data_%s` AS f
					WHERE
						f.entry_id = '%s'
						AND f.`value-%s` = '%s'
						AND f.`handle-%s` = '%s'
					LIMIT 1
				",
				$this->get('id'), $entry_id,
				$lang_code, $this->cleanValue(General::sanitize($value)),
				$lang_code, $this->cleanValue(General::sanitize($handle))
			));
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Settings  */
		/*------------------------------------------------------------------------------------------------*/

		public function findDefaults(&$fields){
			parent::findDefaults($fields);

			$fields['def_ref_lang'] = 'no';
		}

		public function displaySettingsPanel(XMLElement &$wrapper, $errors = null){
			parent::displaySettingsPanel($wrapper, $errors);

			foreach( $wrapper->getChildrenByName('ul') as /* @var XMLElement $list*/
			         $list ){

				if( $list->getAttribute('class') === 'options-list' ){
					$item = new XMLElement('li');

					$input = Widget::Input("fields[{$this->get('sortorder')}][def_ref_lang]", 'yes', 'checkbox');
					if( $this->get('def_ref_lang') == 'yes' ) $input->setAttribute('checked', 'checked');

					$item->appendChild(Widget::Label(
						__('%s Use value from main language if selected language has empty value.', array($input->generate()))
					));

					$list->appendChild($item);
				}
			}
		}

		public function commit($propogate = null){
			if( !parent::commit($propogate) ) return false;

			return Symphony::Database()->query(sprintf("
				UPDATE
					`tbl_fields_%s`
				SET
					`def_ref_lang` = '%s'
				WHERE
					`field_id` = '%s';",
				$this->handle(), $this->get('def_ref_lang') === 'yes' ? 'yes': 'no', $this->get('id')
			));
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Publish  */
		/*------------------------------------------------------------------------------------------------*/

		public function displayPublishPanel(&$wrapper, $data = null, $error = null, $prefix = null, $postfix = null){

			// We've been called out of context: Pulblish Filter
			$callback = Administration::instance()->getPageCallback();
			if($callback['context']['page'] != 'edit' && $callback['context']['page'] != 'new') {
				return;
			}

			Extension_Frontend_Localisation::appendAssets();
			Extension_Multilingual_Field::appendHeaders(
				Extension_Multilingual_Field::PUBLISH_HEADERS
			);

			$main_lang = FLang::getMainLang();
			$all_langs = FLang::getAllLangs();
			$langs = FLang::getLangs();

			$wrapper->setAttribute('class', $wrapper->getAttribute('class').' field-multilingual');
			$container = new XMLElement('div', null, array('class' => 'container'));


			/*------------------------------------------------------------------------------------------------*/
			/*  Label  */
			/*------------------------------------------------------------------------------------------------*/

			$label = Widget::Label($this->get('label'));
			$optional = '';

			if( $this->get('required') != 'yes' ){
				$optional = __('Optional');
			}

			if( $optional !== '' )
				foreach( $langs as $lc )
					$label->appendChild(new XMLElement('i', $optional, array('class' => 'tab-element tab-'.$lc, 'data-lang_code' => $lc)));

			$container->appendChild($label);


			/*------------------------------------------------------------------------------------------------*/
			/*  Tabs  */
			/*------------------------------------------------------------------------------------------------*/

			$ul = new XMLElement('ul', null, array('class' => 'tabs'));
			foreach( $langs as $lc ){
				$li = new XMLElement('li', $all_langs[$lc], array('class' => $lc));
				$lc === $main_lang ? $ul->prependChild($li) : $ul->appendChild($li);
			}

			$container->appendChild($ul);


			/*------------------------------------------------------------------------------------------------*/
			/*  Panels  */
			/*------------------------------------------------------------------------------------------------*/

			foreach( $langs as $lc ){
				$div = new XMLElement('div', null, array('class' => 'file tab-panel tab-'.$lc, 'data-lang_code' => $lc));

				$element_name = $this->get('element_name');

				$input = Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$postfix.'['.$lc.']', 'yes', 'checkbox', ($data['value-'.$lc] == 'yes' ? array('checked' => 'checked') : NULL));

				$div->appendChild($input);

				$container->appendChild($div);
			}


			/*------------------------------------------------------------------------------------------------*/
			/*  Errors  */
			/*------------------------------------------------------------------------------------------------*/

			if( $error != null ){
				$wrapper->appendChild(Widget::Error($container, $error));
			}
			else{
				$wrapper->appendChild($container);
			}
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Input  */
		/*------------------------------------------------------------------------------------------------*/

		public function checkPostFieldData($data, &$message, $entry_id = null){
			$error = self::__OK__;
			$field_data = $data;
			$all_langs = FLang::getAllLangs();
			$main_lang = FLang::getMainLang();

			foreach( FLang::getLangs() as $lc ){

				$file_message = '';
				$data = $field_data[$lc];

				$status = parent::checkPostFieldData($data, $file_message, $entry_id);

				// if one language fails, all fail
				if( $status != self::__OK__ ){

					if( $lc === $main_lang ){
						$message = "<br />{$all_langs[$lc]}: {$file_message}" . $message;
					}
					else{
						$message .= "<br />{$all_langs[$lc]}: {$file_message}";
					}

					$error = self::__ERROR__;
				}
			}

			return $error;
		}

		public function processRawFieldData($data, &$status, &$message = null, $simulate = false, $entry_id = null){
			if( !is_array($data) ) $data = array();

			$status = self::__OK__;
			$result = array();
			$field_data = $data;


			foreach( FLang::getLangs() as $lc ){

				$data = isset($field_data[$lc]) ? $field_data[$lc] : '';

				$result = array_merge($result, array(
					'value-'.$lc => (strtolower($data) == 'yes' || strtolower($data) == 'on' ? 'yes' : 'no')
				));

				// Insert values of default language as default values of the field for compatibility with other extensions 
				// that watch the values without lang code.
				if (FLang::getMainLang() == $lc) {
					$result = array_merge($result, array(
						'value' =>  (strtolower($data) == 'yes' || strtolower($data) == 'on' ? 'yes' : 'no')
					));
				}
			}

			return $result;
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Output  */
		/*------------------------------------------------------------------------------------------------*/

		public function fetchIncludableElements() {
			$parent_elements = parent::fetchIncludableElements();
			$includable_elements = $parent_elements;

			$name = $this->get('element_name');
			$name_length = strlen($name);

			foreach( $parent_elements as $element ){
				$includable_elements[] = $name.': all-languages'.substr($element, $name_length);
			}

			return $includable_elements;
		}

		public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null){

			// all-languages
			$all_languages = strpos($mode, 'all-languages');

			if( $all_languages !== false ){
				$submode = substr($mode, $all_languages+15);

				if( empty($submode) ) $submode = 'formatted';

				$all = new XMLElement($this->get('element_name'), NULL, array('mode' => $mode));

				foreach (FLang::getLangs() as $lc) {
					$data['value'] = $data['value-'.$lc];

					$attributes = array(
						'lang' => $lc
					);

					$item = new XMLElement(
						'item', null, $attributes
					);

					parent::appendFormattedElement($item, $data, $encode, $submode);

					// Reformat generated XML
					$elem = $item->getChild(0);
					if (!is_null($elem)) {
						$attributes = $elem->getAttributes();
						unset($attributes['mode']);
						$value = $elem->getValue();
						$item->setAttributeArray($attributes);
						$item->setValue($value);
						$item->removeChildAt(0);
					}

					$all->appendChild($item);
				}

				$wrapper->appendChild($all);
			}

			// current-language
			else{
				$lang_code = FLang::getLangCode();

				// If value is empty for this language, load value from main language
				if( $this->get('def_ref_lang') == 'yes' && $data['value-'.$lang_code] === '' ){
					$lang_code = FLang::getMainLang();
				}

				$data['value'] = $data['value-'.$lang_code];

				parent::appendFormattedElement($wrapper, $data, $encode, $mode);

	
			}
		}

		public function prepareTableValue($data, XMLElement $link = null){
			$lang_code = Lang::get();

			if( !FLang::validateLangCode($lang_code) ){
				$lang_code = FLang::getLangCode();
			}

			// If value is empty for this language, load value from main language
			if( $this->get('def_ref_lang') == 'yes' && $data['value-'.$lang_code] === '' ){
				$lang_code = FLang::getMainLang();
			}

			$data['value'] = $data['value-'.$lang_code];

			return parent::prepareTableValue($data, $link);
		}

		public function getParameterPoolValue($data){
			$lang_code = FLang::getLangCode();

			// If value is empty for this language, load value from main language
			if( $this->get('def_ref_lang') === 'yes' && $data['value-'.$lang_code] === '' ){
				$lang_code = FLang::getMainLang();
			}

			return $data['value-'.$lang_code];
		}

		public function getExampleFormMarkup(){

			$label = Widget::Label($this->get('label').'
					<!-- '.__('Modify just current language value').' -->
					<input name="fields['.$this->get('element_name').'][value-{$url-fl-language}]" type="checkbox" />

					<!-- '.__('Modify all values').' -->');

			foreach( FLang::getLangs() as $lc )
				$label->appendChild(Widget::Input('fields['.$this->get('element_name').'][value-{$lc}]', 'yes', 'checkbox', ($this->get('default_state') == 'on' ? array('checked' => 'checked') : NULL)));
			

			return $label;
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Filtering  */
		/*------------------------------------------------------------------------------------------------*/

		public function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation = false){
			$multi_where = '';

			parent::buildDSRetrivalSQL($data, $joins, $multi_where, $andOperation);

			$lc = FLang::getLangCode();

			$multi_where = str_replace('.value', ".`value-{$lc}`", $multi_where);

			$where .= $multi_where;

			return true;
		}



	/*-------------------------------------------------------------------------
		Sorting:
	-------------------------------------------------------------------------*/

		public function buildSortingSQL(&$joins, &$where, &$sort, $order = 'ASC') {
			$lc = FLang::getLangCode();

			if (in_array(strtolower($order), array('random', 'rand'))) {
				$sort = 'ORDER BY RAND()';
			}

			else {
				$sort = sprintf('
					ORDER BY (
						SELECT `%s`
						FROM tbl_entries_data_%d
						WHERE entry_id = e.id
					) %s',
					'value-'.$lc,
					$this->get('id'),
					$order
				);
			}
		}

		/*------------------------------------------------------------------------------------------------*/
		/*  Grouping  */
		/*------------------------------------------------------------------------------------------------*/

		public function groupRecords($records){
			$lc = FLang::getLangCode();

			$groups = array(
				$this->get('element_name') => array()
			);

			foreach( $records as $record ){
				$data = $record->getData($this->get('id'));

				$value = $data['value-'.$lc];
				$element = $this->get('element_name');

				if( !isset($groups[$element][$value]) ){
					$groups[$element][$value] = array(
						'attr' => array(
							'value' => $value
						),
						'records' => array(),
						'groups' => array()
					);
				}

				$groups[$element][$handle]['records'][] = $record;
			}

			return $groups;
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Field schema  */
		/*------------------------------------------------------------------------------------------------*/

		public function appendFieldSchema($f){}

	}
