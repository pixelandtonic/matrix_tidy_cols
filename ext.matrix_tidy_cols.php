<?php

/**
 * Matrix Tidy Cols
 *
 * @author    Brandon Kelly <brandon@pixelandtonic.com>
 * @copyright Copyright (c) 2011 Pixel & Tonic, Inc
 */
class Matrix_tidy_cols
{
	var $name = 'Matrix Tidy Cols';
	var $version = '1.0.2';
	var $description = 'Tidy up your Matrix columns after MSM site duplication';
	var $settings_exist = 'y';
	var $docs_url = '';

	/**
	 * Activate Extension
	 */
	function activate_extension()
	{
		$this->_update_field_col_associations();
	}

	/**
	 * Update Field Column Associations
	 *
	 * Before Matrix 2.2, Matrix would associate Matrix columns to fields via the fields’ col_ids setting.
	 * But now (on EE2 only), those associations are made via the exp_matrix_cols.field_id column.
	 * This function populates that field_id column accordingly, and also duplicates any Matrix columns that belong to more than one field (via MSM field duplication)
	 */
	private function _update_field_col_associations()
	{
		global $PREFS, $DB, $FF;

		$log = array();

		// make sure FF 1.4.5+ is installed if the ASCIIs-to-entities setting is enabled
		if ($PREFS->ini('auto_convert_high_ascii') == 'y' && version_compare(FF_VERSION, '1.4.5', '<'))
		{
			$log[] = 'Please install FieldFrame 1.4.5';
		}
		else
		{
			// get each of the Matrix fields
			$matrix_ftype_id = $FF->_get_ftype('matrix')->_fieldtype_id;

			$query = $DB->query('SELECT field_id, site_id, ff_settings
			                     FROM exp_weblog_fields
			                     WHERE field_type = "ftype_id_'.$matrix_ftype_id.'"
			                     ORDER BY site_id');

			if ($query->num_rows)
			{
				$col_fields = array();

				foreach ($query->result as $field)
				{
					$field_id = $field['field_id'];
					$field_name = "field_id_{$field_id}";

					$log[] = "<strong>Opening {$field_name}</strong>";

					// unserialize the field settings
					$field_settings = $FF->_unserialize($field['ff_settings']);

					$new_col_ids = array();

					// make sure the col_ids setting is in-tact
					if (isset($field_settings['col_ids']))
					{
						foreach (array_unique(array_filter($field_settings['col_ids'])) as $col_id)
						{
							$col_name = "col_id_{$col_id}";

							// get the column data
							$col = $DB->query('SELECT * FROM exp_matrix_cols WHERE col_id = '.$col_id);

							if ($col->num_rows)
							{
								$data = $col->row;

								// make sure another field isn't already using this column
								if (! array_key_exists($col_id, $col_fields))
								{
									$col_fields[$col_id] = $field_name;
									$new_col_ids[] = $col_id;

									$log[] = "{$col_name} stays put";
								}
								else
								{
									// duplicate it
									unset($data['col_id']);
									$data['site_id'] = $field['site_id'];

									$DB->query($DB->insert_string('exp_matrix_cols', $data));

									// get the new col_id
									$new_col_id = $DB->insert_id;

									$new_col_name = 'col_id_'.$new_col_id;

									// add the new data column
									$DB->query("ALTER TABLE exp_matrix_data ADD {$new_col_name} TEXT");

									// migrate the data
									$DB->query("UPDATE exp_matrix_data
									            SET {$new_col_name} = {$col_name}, {$col_name} = NULL
									            WHERE field_id = ".$field_id);

									$new_col_ids[] = $new_col_id;

									$log[] = "{$col_name} is already being used by {$col_fields[$col_id]}. Duplicated to {$new_col_name}";
								}
							}
						}
					}

					// update the field settings with the new col_ids array
					$field_settings['col_ids'] = $new_col_ids;

					$field_data = array('ff_settings' => $FF->_serialize($field_settings));

					$DB->query($DB->update_string('exp_weblog_fields', $field_data, 'field_id = '.$field_id));
				}
			}
		}

		foreach ($log as $item)
		{
			echo "<p>{$item}</p><hr/>";
		}

		echo '<p><a href="'.BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=extensions_manager">Back to Extensions Manager</a>';
		die();
	}
}
