<?php
/**
 * Copyright (c) 2011 Mediaparks, SIA
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */


 /*
  * PHP SQL CREATE (table) Converter class
  *
  */

	class PSCC {

		/**
		 *
		 * @param String $sqlQuery
		 * @param boolean $cleanWhitespace (default = true)
		 * @return array tokenized SQL query
		 */
		public static function tokenize($sqlQuery,$cleanWhitespace = true) {

			/**
			 * Strip extra whitespace from the query
			 */
			if($cleanWhitespace) {
			 $sqlQuery = ltrim(preg_replace('/[\\s]{2,}/',' ',$sqlQuery));
			}

			/**
			 * Regular expression based on SQL::Tokenizer's Tokenizer.pm by Igor Sutton Lopes
			 **/
			$regex = '('; # begin group
			$regex .= '(?:--|\\#)[\\ \\t\\S]*'; # inline comments
			$regex .= '|(?:<>|<=>|>=|<=|==|=|!=|!|<<|>>|<|>|\\|\\||\\||&&|&|-|\\+|\\*(?!\/)|\/(?!\\*)|\\%|~|\\^|\\?)'; # logical operators
			$regex .= '|[\\[\\]\\(\\),;`]|\\\'\\\'(?!\\\')|\\"\\"(?!\\"")'; # empty single/double quotes
			$regex .= '|".*?(?:(?:""){1,}"|(?<!["\\\\])"(?!")|\\\\"{2})|\'.*?(?:(?:\'\'){1,}\'|(?<![\'\\\\])\'(?!\')|\\\\\'{2})'; # quoted strings
			$regex .= '|\/\\*[\\ \\t\\n\\S]*?\\*\/'; # c style comments
			$regex .= '|(?:[\\w:@]+(?:\\.(?:\\w+|\\*)?)*)'; # words, placeholders, database.table.column strings
			$regex .= '|[\t\ ]+';
			$regex .= '|[\.]'; #period
			$regex .= '|[\s]'; #whitespace

			$regex .= ')'; # end group

			// get global match
			preg_match_all( '/' . $regex . '/smx', $sqlQuery, $result );

			// return tokens
			return $result[0];

		}

		/**
		 *
		 * @param array $table (the table data format returned by self::getTableInfoFromCreateStatement function
		 * @param string $db_type (currently can be mysql or pgsql)
		 * @return array of SQL statements required for creating the table
		 */
		public static function getTranslatedTableDef($table,$db_type) {
			
			$ret = array();
			$pret = array();
			$query = "CREATE TABLE ".$table['name']." (\n";
			$fields = array();
			foreach ( $table['fields'] as $field ) {
				$f = $field['title'];
				if ( $field['type'] == 'enum' ) {
					if ( $db_type == 'mysql' ) {
						$f.=" ENUM (".implode(",",$field['enum_values']).") ";
					} else if ( $db_type == 'pgsql' ) {
						$type_query = "CREATE TYPE enum_".$table['name']."_".$field['title']." AS ENUM  (".implode(",",$field['enum_values']).") ";
						$ret[] = $type_query;
						$f.= " enum_".$table['name']."_".$field['title']." ";
					} else {
						throw new Exception('unsupported DB type');
					}
				} else {
					$f .= self::translateType($field,$db_type);
				}
				if ( !$field['is_null'] ) {
					$f.=" NOT NULL ";
				}
				if ( $field['default'] !== NULL ) {
					$f.= " DEFAULT ".(is_string($field['default'])?"".$field['default']."":$field['default'])." ";
				}
				if ( $db_type == 'mysql' && $field['is_auto_increment'] ) {
					$f.= ' auto_increment ';
				}
				$fields[] = $f;
			}
			foreach ( $table['keys'] as $key ) {
				switch ( $key['type'] ) {
					case 'index':
						if ( $db_type == 'mysql' ) {
							$fields[] = 'INDEX ('.implode(',',$key['field']).')';
						} else {
							$pret[] = "CREATE INDEX ".$table['name']."_".(implode('_',$key['field']))."_idx ON ".$table['name'].' ('.implode(',',$key['field']).') ';
						}
						break;
					case 'primary':
						$fields[] = 'PRIMARY KEY ('.implode(',',$key['field']).')';
						break;
					case 'unique':
						$fields[] = 'UNIQUE ('.implode(',',$key['field']).')';
						break;
					case 'foreign':
						$fk = 'FOREIGN KEY ('.implode(',',$key['field']).') REFERENCES '.$key['references'].' ('.implode(',',$key['ref_fields']).') ';
						if ( $key['on_update'] != 'NO ACTION' ) {
							$fk.=' ON UPDATE '.$key['on_update'].' ';
						}
						if ( $key['on_delete'] != 'NO ACTION' ) {
							$fk.=' ON DELETE '.$key['on_delete'].' ';
						}
						$fields[] = $fk;
						break;
				}
			}
			$query.= implode(",\n", $fields)." ) \n";
			if ( count($table['meta']) && $db_type == 'mysql' ) {
				foreach ( $table['meta'] as $meta => $value ) {
					$query.=$meta.'='.$value.' ';
				}
			}
			$ret[] = $query;
			$ret = array_merge($ret, $pret);
			return $ret;
		}

		/**
		 *
		 * @param array $column a column field definition defined by self::getTableInfoFromCreateStatement function
		 * @param string $db_type (currently can be mysql or pgsql)
		 * @return string type definition
		 */
		public static function translateType($column,$db_type) {

			if ( $db_type == 'mysql' ) {
				switch ( strtolower($column['type']) ) {
					case 'inet':
						$column['type'] = 'integer';
						$column['is_unsigned'] = true;
						break;
					case 'character varying':
						$column['type'] = 'varchar';
						break;
					case 'serial':
						$column['is_auto_increment'] = true;
						$column['type'] = 'integer';
						break;
				}
				$ret = ' '.$column['type'];
				if ( $column['is_unsigned'] ) {
					$ret.= ' UNSIGNED ';
				}
				if ( $column['typelen'] ) {
					$ret.=' ('.$column['typelen'].') ';
				}
				return $ret;
			}
			if ( $column['is_auto_increment'] && $db_type == 'pgsql' ) {
				return ' serial ';
			}
			switch ( strtolower($column['type']) ) {
				case 'tinyint':
				case 'smallint':
				case 'mediumint':
					return ' smallint ';
				case 'int':
				case 'integer':
					return ' integer ';
				case 'bigint':
					return ' bigint ';
				case 'text':
				case 'tinytext':
				case 'mediumtext':
				case 'longtext':
					return ' text ';
				case 'datetime':
					return ' timestamp ';
				default:
					$ret = ' '.$column['type'].' ';
					if ( $column['typelen'] ) {
						$ret.='('.$column['typelen'].') ';
					}
					return $ret;
			}
		}


		/**
		 *
		 * @param string $query a VALID SQL create table statement
		 * @return array table info
		 */
		public static function getTableInfoFromCreateStatement($query) {

			$table = array(
				'name'=>'',
				'fields'=>array(),
				'keys'=>array(),
				'meta'=>array()
			);

			$tokens = self::tokenize($query);

			$state = array();
			$next_token_is = '';

			foreach ( $tokens as $token ) {
				switch ( strtolower($token) ) {
					case ' ':
						break;
					case 'create':
						break;
					case 'table':
						$next_token_is = 'table_name';
						break;
					case '(':
						if ( count($state) == 0 && !count($table['fields']) ) {
							$state = array('table-definition');
							$current_field = array(
								'title'=>'',
								'type'=>'',
								'typelen'=>'',
								'is_null'=>TRUE,
								'default'=>NULL,
								'is_auto_increment'=>FALSE,
								'is_primary_key'=>FALSE,
								'is_unique'=>FALSE,
								'enum_values'=>NULL
							);
						} else {
							if ( $next_token_is == 'enum-values' ) {
								$enum_values = array();
								$state[] = 'enum-values';
							} else if ( $next_token_is == 'set-values' ) {
								$set_values = array();
								$state[] = 'set-values';
							} else if ( $next_token_is == 'key-name' ) {
								$next_token_is = 'key-fields';
								$state[] = 'key-fields';
							} else if ( $next_token_is == 'maybe-field-length' ) {
								$next_token_is = 'field-length';
								$state[] = 'field-length';
							} else if ( $next_token_is == 'ref-field-names' ) {
								$state[] = 'ref-field-names';
							}  else {
								$state[] = 'default-substate';
							}
						}
						break;
					case ')':
						$pstate = array_pop($state);
						switch ( $pstate ) {
							case 'table-definition':
								if ( $current_field['title'] != '' ) {
									$table['fields'][$current_field['title']] = $current_field;
								}
								if ( isset($current_key) ) {
									$table['keys'][] = $current_key;
									unset($current_key);
								}
								break;
							case 'enum-values':
								$current_field['enum_values'] = $enum_values;
								$next_token_is = '';
								break;
							case 'set-values':
								$current_field['set_values'] = $set_values;
								$next_token_is = '';
								break;
							case 'key-fields':
								$next_token_is = '';
								break;
							case 'ref-field-names':
								$next_token_is = 'maybe-match';
								break;
							default:
								break;
						}
						break;
					case '`':
						break;
					case 'inet':
					case 'bit':
					case 'tinyint':
					case 'smallint':
					case 'mediumint':
					case 'int':
					case 'integer':
					case 'bigint':
					case 'real':
					case 'double':
					case 'float':
					case 'decimal':
					case 'numeric':
					case 'date':
					case 'time':
					case 'timestamp':
					case 'datetime':
					case 'year':
					case 'char':
					case 'varchar':
					case 'text':
					case 'tinytext':
					case 'mediumtext':
					case 'longtext':
						$current_field['type'] = strtolower($token);
						$next_token_is = 'maybe-field-length';
						break;
					case 'enum':
						$current_field['type'] = strtolower($token);
						$next_token_is = 'enum-values';
						break;
					case 'serial':
						$current_field['type'] = 'integer';
						$current_field['is_auto_increment'] = true;
						break;
					case 'default':
						if ( end($state) == 'table-definition' ) {
							$next_token_is = 'default-value';
						}
						break;
					case 'varying':
						if ( $next_token_is == 'char-set-set' ) {
							$next_token_is = 'maybe-field-length';
							$current_field['type'] = 'varchar';
						}
						break;
					case 'not':
						$current_field['is_null'] = FALSE;
						$next_token_is = 'null';
						break;
					case 'unsigned':
						$current_field['is_unsigned'] = true;
						break;
					case 'auto_increment':
						if ( count($state) == 0 ) {
							$next_token_is = 'table-meta';
							$current_meta = $token;
						} else {
							$current_field['is_auto_increment'] = TRUE;
						}
						break;

					case 'collate':
						$collate_type = count($state) == 0 ? 'table' : 'column';
						$next_token_is = 'collation';
						break;

					case 'character':
						$charset = array(
							'type' => count($state) == 0 ? 'table' : 'column',
							'value' => ''
						);
						$next_token_is = 'char-set-set';
						break;
					case 'set':
						if ( $next_token_is == 'char-set-set' ) {
							$next_token_is = 'char-set-value';
						} else if ( in_array($next_token_is, array('on-update','on-delete'))) {
							$current_key[str_replace('-', '_', $next_token_is)] = $token;
							break;
						} else {
							$current_field['type'] = strtolower($token);
							$next_token_is = 'set-values';
						}
						break;
					case 'constraint':
						$next_token_is = 'key-name';
						break;
					case 'charset':
						$charset = array(
							'type' => count($state) == 0 ? 'table' : 'column',
							'value' => ''
						);
						$next_token_is = 'char-set-value';
						break;
					case 'engine':
						if ( count($state) == 0 ) {
							$next_token_is = 'table-meta';
							$current_meta = $token;
						}
						break;
					case 'primary':
						if ( $current_field['title'] != '' ) {
							$current_field['is_primary_key'] = true;
							$current_key = array(
								'type'=>'primary',
								'field'=>array($current_field['title'])
							);
						} else {
							$current_key = array(
								'type'=>'primary',
								'field'=>array()
							);
						}
						break;
					case 'foreign':
						if ( $current_field['title'] == '' ) {
							$current_key = array(
								'type'=>'foreign',
								'field'=>array(),
								'references'=>'',
								'ref_fields'=>array(),
								'on_delete'=>'NO ACTION',
								'on_update'=>'NO ACTION'
							);
						}
						break;
					case 'unique':
						if ( $current_field['title'] != '' ) {
							$current_field['is_unique'] = true;
							$current_key = array(
								'type'=>'unique',
								'field'=>array($current_field['title'])
							);
						} else {
							$current_key = array(
								'type'=>'unique',
								'field'=>array()
							);
						}
						break;
					case 'key':
						if ( !isset($current_key) && $current_field['title'] == '' ) {
							$current_key = array(
								'type'=>'index',
								'field'=>array()
							);
						}
						if ( $current_field['title'] == '' ){
							$next_token_is = 'key-name';
						}
						break;
					case 'references':
						$next_token_is = 'ref-table-name';
						break;
					case ',':
						if ( end($state) == 'table-definition' ) {
							if ( $current_field['title'] != '' ) {
								$table['fields'][$current_field['title']] = $current_field;
								$current_field = array(
									'title'=>'',
									'type'=>'',
									'typelen'=>'',
									'is_null'=>TRUE,
									'default'=>NULL,
									'is_auto_increment'=>FALSE,
									'is_primary_key'=>FALSE,
									'is_unique'=>FALSE,
									'enum_values'=>NULL
								);
							}
							if ( isset($current_key) ) {
								$table['keys'][] = $current_key;
								unset($current_key);
							}
						}
						break;
					case 'on':
						$next_token_is = 'on-action';
						break;
					case '=':
						break;
					default :
						switch ( $next_token_is ) {
							case 'null':
								$next_token_is = '';
								break;
							case 'key-name':

								break;
							case 'maybe-match':
								break;
							case 'on-action':
								if ( strtolower($token) == 'update' ) {
									$next_token_is = 'on-update';
								} else if ( strtolower($token) == 'delete' ) {
									$next_token_is = 'on-delete';
								} else {
									$next_token_is = '';
								}
								break;
							case 'table_name':
								$table['name'] = $token;
								$next_token_is = '';
								break;
							case 'field-length':
								$current_field['typelen'] = $token;
								$next_token_is = '';
								break;
							case 'key-fields':
								$current_key['field'][] = $token;
								if ( $current_key['type'] == 'primary' ) {
									if ( isset($table['fields'][$token]) ) {
										$table['fields'][$token]['is_primary_key'] = true;
									}
								} else if ( $current_key['type'] == 'unique' ) {
									if ( isset($table['fields'][$token]) ) {
										$table['fields'][$token]['is_unique'] = true;
									}
								}
								break;
							case 'enum-values':
								$enum_values[] = $token;
								break;
							case 'set-values':
								$set_values[] = $token;
								break;
							case 'default-value':
								if ( substr(strtolower($token),0,4) == 'null' ) {
									$current_field['default'] = NULL;
								} else {
									$current_field['default'] = $token;
								}
								$next_token_is = '';
								break;
							case 'collation':
								if ( $collate_type == 'table' ) {
									$table['meta']['collate'] = $token;
								} else {
									$current_field['collate'] = $token;
								}
								unset($charset);
								$next_token_is = '';
								break;
							case 'char-set-value':
								//$charset['value'] = $token;
								if ( $charset['type'] == 'table' ) {
									$table['meta']['character set'] = $token;
								} else {
									$current_field['character_set'] = $token;
								}
								unset($charset);
								$next_token_is = '';
								break;
							case 'table-meta':
								$table['meta'][$current_meta] = $token;
								$next_token_is = '';
								break;
							case 'ref-table-name':
								if ( isset($current_key) ) {
									$current_key['references'] = $token;
								} else {
									$current_key = array(
										'type'=>'foreign',
										'field'=>array($current_field['title']),
										'references'=>$token,
										'ref_fields'=>array(),
										'on_delete'=>'NO ACTION',
										'on_update'=>'NO ACTION'
									);
								}
								$next_token_is = 'ref-field-names';
								break;
							case 'ref-field-names':
								$current_key['ref_fields'][] = $token;
								break;
							case 'on-update':
							case 'on-delete':
								switch ( strtolower($token) ) {
									case 'no'  :
										$current_key[str_replace('-', '_', $next_token_is)] = $token;
										break;
									case 'cascade':
									case 'restrict':
										$current_key[str_replace('-', '_', $next_token_is)] = $token;
										$next_token_is = '';
										break;
									case 'null':
									case 'action':
										$current_key[str_replace('-', '_', $next_token_is)] .= ' '.$token;
										$next_token_is = '';
										break;
								}
								break;
							default:
								if ( strtolower($token) == 'null' ) {
									break;
								}
								if ( '::' == substr($token,0,2) ) {
									break;
								}
								if ( end($state) == 'table-definition' ) {
									$current_field['title'] = $token;
								}
								break;
						}
						break;

				}
			}
			return $table;

		}

	}