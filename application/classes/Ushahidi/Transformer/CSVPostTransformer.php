<?php defined('SYSPATH') or die('No direct script access');

/**
 * Ushahidi CSV Transformer
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

use Ushahidi\Core\Tool\MappingTransformer;
use Ushahidi\Core\Entity\PostRepository;

class Ushahidi_Transformer_CSVPostTransformer implements MappingTransformer
{
	protected $columnNames;
	protected $map;
	protected $fixedValues;
	protected $repo;
	protected $unmapped;

	public function setColumnNames(Array $columnNames)
	{
		$this->columnNames = $columnNames;
	}

	public function setRepo(PostRepository $repo)
	{
		$this->repo = $repo;
	}

	// MappingTransformer
	public function setMap(Array $map)
	{
		$this->map = $map;
	}

	// MappingTransformer
	public function setFixedValues(Array $fixedValues)
	{
		$this->fixedValues = $fixedValues;
	}

	/**
	 * This function transforms values in the record read from the CSV,
	 * according to specific syntax that's provided in CSV file column names.
	 * the syntax is like this: "name->{transformSpec}"
	 * i.e.
	 *  - given: $record[x] == "1,2,3" and $this->columnNames[x] == "somename->{explodeCommas}"
	 *  - explode(",", $record[x]) will be applied to the value in the record and as a result ...
	 *  - $record[x] == array("1","2","3")
	 *
	 * if an unknown transformation specification is provided, the value is unchanged
	 */
	public function transformValues(&$record)
	{
		foreach($this->columnNames as $index=>$columnName) {
			$transformMatch = array();
			if (preg_match('/^.+(?=->)->\{(.+)(?=\})\}$/', $columnName, $transformMatch)) {
				$record[$index] = $this->transformValue($transformMatch[1], $record[$index]);
			}
		}
	}

	public function transformValue($transformSpec, $value) {
		switch($transformSpec) {
			case 'explodeCommas':
				return explode(',', $value);
			default:
				return $value;
		}
	}

	// Transformer
	public function interact(Array $record)
	{
		$record = array_values($record);

		// Trim and remove empty values
		foreach ($record as $key => $val)
		{
			$record[$key] = trim($val);

			if (empty($record[$key])) {
				unset($record[$key]);
			}
		}

		// Transform values according to specs in column names
		$this->transformValues($record);

		$columns = $this->map;

		// Don't import columns marked as NULL
		foreach ($columns as $index => $column) {
			if ($column === NULL) {
				unset($columns[$index]);
				unset($record[$index]);
			}
		}

		// Remap record columns
		$record = array_combine($columns, $record);

		// Merge multi-value columns
		$this->mergeMultiValueFields($record);

		// Filter post fields from the record
		$post_entity = $this->repo->getEntity();
		$post_fields = array_intersect_key($record, $post_entity->asArray());

		// Remove post fields from the record and leave form values
		foreach ($post_fields as $key => $val)
		{
			unset($record[$key]);
		}

		// Put values in array
		array_walk($record, function (&$val) {
			if ($this->isLocation($val)) {
				$val = [$val];
			}

			if (! is_array($val)) {
				$val = [$val];
			}
		});

		$form_values = ['values' => $record];


		return array_merge_recursive($post_fields,
						   $form_values,
						   $this->fixedValues);
	}

	/**
	 * Multi-value columns use dot notation to add sub-keys
	 * e.g. 'location.lat' refers to a field called 'location'
	 * and 'lat' is a sub-key of the field.
	 *
	 * @param array &$record
	 */
	private function mergeMultiValueFields(&$record)
	{
		foreach ($record as $column => $val)
		{
			$keys = explode('.', $column);

			// Get column name
			$column_name = array_shift($keys);

			// Assign sub-key to multi-value column
			if (! empty($keys))
			{
				unset($record[$column]);

				foreach ($keys as $key)
				{
					$record[$column_name][$key] = $val;
				}
			}
		}
	}

	private function isLocation($value)
	{
		return is_array($value) &&
			array_key_exists('lon', $value) &&
			array_key_exists('lat', $value);
	}
}
