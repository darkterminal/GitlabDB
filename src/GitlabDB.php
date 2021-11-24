<?php

namespace GitlabDB;

use Exception;
use League\Flysystem\Filesystem;
use RoyVoetman\FlysystemGitlab\Client;
use RoyVoetman\FlysystemGitlab\GitlabAdapter;

class GitlabDB {

    public $file, $content = [];
    private $where, $select, $merge, $update;
    private $delete = false;
    private $last_indexes = [];
    private $order_by = [];
    protected $dir;
    private $json_opts = [];

    const ASC = 1;
    const DESC = 0;
    const AND = "AND";
    const OR = "OR";

    protected $client;
    protected $adapter;
    protected $filesystem;

    public function __construct( array $options, string $path = '' )
    {
        $this->dir = $path;
        $this->client = new Client( $options['project_id'], $options['branch'], $options['cloud_url'], $options['personal_access_token'] );
        $this->adapter = new GitlabAdapter( $this->client );
        $this->filesystem = new Filesystem($this->adapter);

        $this->json_opts['encode'] = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
    }

    private function check_file()
    {
        // Checks if JSON file exists, if not create
        if ( !$this->filesystem->fileExists( $this->file ) ) {
            $this->filesystem->write( $this->file, '[]' );
        }

        // Read content of JSON file
        $content = $this->filesystem->read($this->file);
        $content = json_decode($content, true);

        // Check if its arrays of jSON
        if (!is_array($content) && is_object($content) ) {
            throw new \Exception('An array of json is required: Json data enclosed with []');
            return false;
        }
        // An invalid jSON file
        elseif (!is_array($content) && !is_object($content)) {
            throw new \Exception('json is invalid');
            return false;
        } else {
            return true;
        }
    }

    public function select($args = '*')
    {
        /**
         * Explodes the selected columns into array
         *
         * @param type $args Optional. Default *
         * @return type object
         */

        // Explode to array
        $this->select = explode(',', $args);
        // Remove whitespaces
        $this->select = array_map('trim', $this->select);
        // Remove empty values
        $this->select = array_filter($this->select);

        return $this;
    }

    public function from($file)
    {
        /**
         * Loads the jSON file
         *
         * @param type $file. Accepts file path to jSON file
         * @return type object
         */

        $this->file = sprintf('%s/%s.json', $this->dir, str_replace('.json', '', $file)); // Adding .json extension is no longer necessary

        // Reset where
        $this->where([]);
        $this->content = '';

        // Reset order by
        $this->order_by = [];

        if (
            $this->check_file()
        ) {
            $this->content = (array) json_decode($this->filesystem->read($this->file));
        }
        return $this;
    }

    public function where(array $columns, $merge = 'OR')
    {
        $this->where = $columns;
        $this->merge = $merge;
        return $this;
    }

    public function insert($file, array $values): array
    {
        $this->from($file);

        if (
            !empty($this->content[0])
        ) {
            $nulls = array_diff_key((array) $this->content[0], $values);
            if ($nulls) {
                $nulls = array_map(function () {
                    return '';
                }, $nulls);
                $values = array_merge($values, $nulls);
            }
        }

        if (
            !empty($this->content) && array_diff_key($values, (array) $this->content[0])
        ) {
            throw new Exception('Columns must match as of the first row');
        } else {
            $this->content[] = (object) $values;
            $this->last_indexes = [(count($this->content) - 1)];
            $this->commit();
        }
        return $this->last_indexes;
    }

    public function commit()
    {
        $this->filesystem->write($this->file, (!$this->content ? '[]' : json_encode($this->content, $this->json_opts['encode'])));
    }

    public static function regex(string $pattern, int $preg_match_flags = 0): object
    {
        $c = new \stdClass();
        $c->is_regex = true;
        $c->value = $pattern;
        $c->options = $preg_match_flags;

        return $c;
    }

    private function where_result()
    {
        $this->flush_indexes();

        if (
            $this->merge == "AND"
        ) {
            return $this->where_and_result();
        } else {
            // Filter array
            $r = array_filter($this->content, function ($row, $index) {
                    $row = (array) $row; // Convert first stage to array if object

                    // Check for rows intersecting with the where values.
                    if (array_uintersect_uassoc($row, $this->where, array($this, "intersect_value_check"), "strcasecmp") /*array_intersect_assoc( $row, $this->where )*/) {
                        $this->last_indexes[] =  $index;
                        return true;
                    }

                    return false;
                },
                    ARRAY_FILTER_USE_BOTH
                );

            // Make sure every  object is turned to array here.
            return array_values(obj_to_array($r));
        }
    }

    private function where_and_result()
    {
        /*
			Validates the where statement values
		*/
        $r = [];

        // Loop through the db rows. Ge the index and row
        foreach ($this->content as $index => $row) {

            // Make sure its array data type
            $row = (array) $row;


            //check if the row = where['col'=>'val', 'col2'=>'val2']
            if (!array_udiff_uassoc($this->where, $row, array($this, "intersect_value_check"), "strcasecmp")) {
                $r[] = $row;
                // Append also each row array key
                $this->last_indexes[] = $index;
            } else continue;
        }
        return $r;
    }

    private function flush_indexes($flush_where = false)
    {
        $this->last_indexes = array();
        if ($flush_where)
        $this->where = array();
    }

    private function intersect_value_check($a, $b)
    {
        if (
            $b instanceof \stdClass
        ) {
            if ($b->is_regex) {
                return !preg_match($b->value, (string)$a, $_, $b->options);
            }

            return -1;
        }

        if (
            $a instanceof \stdClass
        ) {
            if ($a->is_regex) {
                return !preg_match($a->value, (string)$b, $_, $a->options);
            }

            return -1;
        }

        return strcasecmp((string)$a, (string)$b);
    }

    public function delete()
    {
        $this->delete = true;
        return $this;
    }

    public function update(array $columns)
    {
        $this->update = $columns;
        return $this;
    }

    private function _update()
    {
        if (
            !empty($this->last_indexes) && !empty($this->where)
        ) {
            foreach ($this->content as $i => $v) {
                if (in_array($i, $this->last_indexes)) {
                    $content = (array) $this->content[$i];
                    if (!array_diff_key($this->update, $content)) {
                        $this->content[$i] = (object) array_merge($content, $this->update);
                    } else
                        throw new Exception('Update method has an off key');
                } else
                continue;
            }
        } elseif (!empty($this->where) && empty($this->last_indexes)) {
            null;
        } else {
            foreach ($this->content as $i => $v) {
                $content = (array) $this->content[$i];
                if (!array_diff_key($this->update, $content))
                $this->content[$i] = (object) array_merge($content, $this->update);
                else
                throw new Exception('Update method has an off key ');
            }
        }
    }

    public function trigger()
    {
        $content = (!empty($this->where) ? $this->where_result() : $this->content);
        $return = false;
        if (
            $this->delete
        ) {
            if (!empty($this->last_indexes) && !empty($this->where)) {
                $this->content = array_filter($this->content, function ($index) {
                    return !in_array($index, $this->last_indexes);
                }, ARRAY_FILTER_USE_KEY);

                $this->content = array_values($this->content);
            } elseif (empty($this->where) && empty($this->last_indexes)) {
                $this->content = array();
            }

            $return = true;
            $this->delete = false;
        } elseif (!empty($this->update)) {
            $this->_update();
            $this->update = [];
        } else
        $return = false;
        $this->commit();
        return $this;
    }

    public function order_by($column, $order = self::ASC)
    {
        $this->order_by = [$column, $order];
        return $this;
    }

    private function _process_order_by($content)
    {
        if (
            $this->order_by && $content && in_array($this->order_by[0], array_keys((array) $content[0]))
        ) {
            /*
				* Check if order by was specified
				* Check if there's actually a result of the query
				* Makes sure the column  actually exists in the list of columns
			*/

            list($sort_column, $order_by) = $this->order_by;
            $sort_keys = [];
            $sorted = [];

            foreach ($content as $index => $value) {
                $value = (array) $value;
                // Save the index and value so we can use them to sort
                $sort_keys[$index] = $value[$sort_column];
            }

            // Let's sort!
            if ($order_by == self::ASC) {
                asort($sort_keys);
            } elseif ($order_by == self::DESC) {
                arsort($sort_keys);
            }

            // We are done with sorting, lets use the sorted array indexes to pull back the original content and return new content
            foreach ($sort_keys as $index => $value) {
                $sorted[$index] = (array) $content[$index];
            }

            $content = $sorted;
        }

        return $content;
    }

    public function get()
    {
        if ($this->where != null) {
            $content = $this->where_result();
        } else {
            $content = $this->content;
        }

        if ($this->select && !in_array('*', $this->select)) {
            $r = [];
            foreach ($content as $id => $row) {
                $row = (array) $row;
                foreach ($row as $key => $val) {
                    if (in_array($key, $this->select)) {
                        $r[$id][$key] = $val;
                    } else
                        continue;
                }
            }
            $content = $r;
        }

        // Finally, lets do sorting :)
        $content = $this->_process_order_by($content);

        $this->flush_indexes(true);
        return $content;
    }

    public function to_mysql(string $from, string $to, bool $create_table = true): bool
    {
        $this->from($from); // Reads the JSON file
        if (
            $this->content
        ) {
            $table = pathinfo($to, PATHINFO_FILENAME); // Get filename to use as table

            $sql = "-- PHP-GitlabDB JSON to MySQL Dump\n--\n\n";
            if ($create_table) {
                // Should create table, generate a CREATE TABLE statement using the column of the first row
                $first_row = (array) $this->content[0];
                $columns = array_map(function ($column) use ($first_row) {
                    return sprintf("\t`%s` %s", $column, $this->_to_mysql_type(gettype($first_row[$column])));
                }, array_keys($first_row));

                $sql = sprintf("%s-- Table Structure for `%s`\n--\n\nCREATE TABLE `%s` \n(\n%s\n);\n", $sql, $table, $table, implode(",\n", $columns));
            }

            foreach ($this->content as $row) {
                $row = (array) $row;
                $values = array_map(function ($vv) {
                    $vv = (is_array($vv) || is_object($vv) ? serialize($vv) : $vv);
                    return sprintf("'%s'", addslashes((string)$vv));
                }, array_values($row));

                $cols = array_map(function ($col) {
                    return sprintf("`%s`", $col);
                }, array_keys($row));
                $sql .= sprintf("INSERT INTO `%s` ( %s ) VALUES ( %s );\n", $table, implode(', ', $cols), implode(', ', $values));
            }
            file_put_contents($to, $sql);
            return true;
        } else
        return false;
    }

    private function _to_mysql_type($type)
    {
        if (
            $type == 'bool'
        )
        $return = 'BOOLEAN';
        elseif ($type == 'integer')
        $return = 'INT';
        elseif ($type == 'double')
        $return = strtoupper($type);
        else
        $return = 'VARCHAR( 255 )';
        return $return;
    }

    public function to_xml($from, $to)
    {
        $this->from($from);
        if (
            $this->content
        ) {
            $element = pathinfo($from, PATHINFO_FILENAME);
            $xml = '
			<?xml version="1.0"?>
				<' . $element . '>
';

            foreach ($this->content as $index => $value) {
                $xml .= '
				<DATA>';
                foreach ($value as $col => $val) {
                    $xml .= sprintf('
					<%s>%s</%s>', $col, $val, $col);
                }
                $xml .= '
				</DATA>
				';
            }
            $xml .= '</' . $element . '>';

            $xml = trim($xml);
            file_put_contents($to, $xml);
            return true;
        }
        return false;
    }

}
