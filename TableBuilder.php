<?php
namespace MyPlugin\Schema;

use wpdb;

defined('ABSPATH') || exit;

/**
 * Class TableBuilder
 *
 * A utility class to create custom MySQL tables with foreign key support.
 * Intended as an alternative to dbDelta() for strict relational schemas.
 */
class TableBuilder {
    protected wpdb $db;
    protected string $table_name;
    protected array $columns = [];
    protected array $indexes = [];
    protected array $foreign_keys = [];
    protected string $charset_collate;
    protected string $engine = 'InnoDB';
    protected ?string $version = null;
    protected array $errors = [];

    /**
     * Constructor
     */
    public function __construct(string $table_name) {
        global $wpdb;
        $this->db = $wpdb;

        if (!$this->is_valid_identifier($table_name)) {
            throw new \InvalidArgumentException("Invalid table name: $table_name");
        }

        $this->table_name = $wpdb->prefix . $table_name;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $this->charset_collate = $wpdb->get_charset_collate();
    }

    /**
     * Add a column definition
     */
    public function add_column(string $name, string $definition): self {
        if (!$this->is_valid_identifier($name)) {
            throw new \InvalidArgumentException("Invalid column name: $name");
        }

        if (preg_match('/;|--|\/\*/', $definition)) {
            throw new \InvalidArgumentException("Column definition contains forbidden characters.");
        }

        $this->columns[$name] = [
            'definition' => sprintf('`%s` %s', $name, trim($definition)),
            'name'       => $name,
        ];
        return $this;
    }

    /**
     * Set primary key
     */
    public function add_primary(string $column): self {
        if (!$this->is_valid_identifier($column)) {
            throw new \InvalidArgumentException("Invalid column name: $column");
        }

        $this->indexes['PRIMARY'] = [
            'definition' => sprintf('PRIMARY KEY (`%s`)', $column),
            'columns' => [$column],
        ];

        return $this;
    }

    /**
     * Add an index
     */
    public function add_index(string $name, array $columns): self {
        if (!$this->is_valid_identifier($name)) {
            throw new \InvalidArgumentException("Invalid index name: $name");
        }

        foreach ($columns as $column) {
            if (!$this->is_valid_identifier($column)) {
                throw new \InvalidArgumentException("Invalid column name in index: $column");
            }
        }

        $cols = implode('`,`', $columns);
        $this->indexes[$name] = [
            'definition' => sprintf('KEY `%s` (`%s`)', $name, $cols),
            'columns' => $columns,
        ];
        return $this;
    }

    /**
     * Add a foreign key constraint
     */
    public function add_foreign_key(string $column, string $ref_table, string $ref_column, string $on_delete = 'CASCADE', string $on_update = 'RESTRICT'): self {
        if (!$this->is_valid_identifier($column) || 
            !$this->is_valid_identifier($ref_table) || 
            !$this->is_valid_identifier($ref_column)) {
            throw new \InvalidArgumentException("Invalid identifier in foreign key definition");
        }

        $on_delete = $this->sanitize_fk_action($on_delete);
        $on_update = $this->sanitize_fk_action($on_update);

        $constraint_name = sprintf('fk_%s_%s_%s', $this->get_short_table_name(), $column, substr(md5($ref_table . $ref_column), 0, 6));

        $this->foreign_keys[$constraint_name] = [
            'definition' => sprintf(
                'CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s',
                $constraint_name, $column, $ref_table, $ref_column, $on_delete, $on_update
            ),
            'name' => $constraint_name,
            'column' => $column,
            'ref_table' => $ref_table,
            'ref_column' => $ref_column,
        ];
        return $this;
    }

    /**
     * Set storage engine
     */
    public function set_engine(string $engine): self {
        $allowed_engines = ['InnoDB', 'MyISAM', 'MEMORY', 'ARCHIVE'];
        if (!in_array($engine, $allowed_engines)) {
            throw new \InvalidArgumentException("Unsupported engine: $engine");
        }
        $this->engine = $engine;
        return $this;
    }

    /**
     * Set schema version
     */
    public function set_version(string $version): self {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new \InvalidArgumentException("Version must be in format x.y.z");
        }
        $this->version = $version;
        return $this;
    }

    /**
     * Create or update the table
     */
    public function createOrUpdate(): bool {
        $this->errors = [];

        try {
            $table_exists = $this->table_exists();
            $current_version = get_option($this->table_name . '_version');

            if (!$table_exists) {
                return $this->create_table();
            }

            if ($this->version !== null && version_compare($this->version, $current_version ?: '0.0.0', '>')) {
                return $this->apply_alterations($current_version);
            }

            return true;
        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }

    /**
     * Get recorded errors
     */
    public function getErrors(): array {
        return $this->errors;
    }

    protected function table_exists(): bool {
        $table = $this->db->get_var(
            $this->db->prepare("SHOW TABLES LIKE %s", $this->table_name)
        );
        return $table === $this->table_name;
    }

    protected function is_valid_identifier(string $name): bool {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) && strlen($name) <= 64;
    }

    protected function sanitize_fk_action(string $action): string {
        $allowed_actions = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT'];
        $action = strtoupper(trim($action));

        if (in_array($action, $allowed_actions)) {
            return $action;
        }

        throw new \InvalidArgumentException("Invalid foreign key action: $action");
    }

    protected function get_short_table_name(): string {
        $prefix_len = strlen($this->db->prefix);
        return substr($this->table_name, $prefix_len);
    }

    protected function create_table(): bool {
        $this->db->query('START TRANSACTION');
        try {
            $definitions = array_merge(
                array_column($this->columns, 'definition'),
                array_column($this->indexes, 'definition'),
                array_column($this->foreign_keys, 'definition')
            );

            $sql = sprintf(
                "CREATE TABLE `%s` (
%s
) ENGINE=%s %s",
                $this->table_name,
                implode(",\n", $definitions),
                $this->engine,
                $this->charset_collate
            );

            $result = $this->db->query($sql);

            if ($result === false) {
                throw new \RuntimeException("Table creation failed: " . $this->db->last_error);
            }

            if ($this->version !== null) {
                update_option($this->table_name . '_version', $this->version);
            }

            $this->db->query('COMMIT');
            return true;
        } catch (\Exception $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }

    protected function apply_alterations(?string $old_version): bool {
        $this->db->query('START TRANSACTION');
        $altered = false;

        try {
            $existing_columns = $this->db->get_col("DESCRIBE `{$this->table_name}`", 0);
            $existing_indexes = array_column(
                $this->db->get_results("SHOW INDEX FROM `{$this->table_name}`", ARRAY_A),
                'Key_name'
            );
            $existing_fks = $this->db->get_col(
                $this->db->prepare("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL", $this->table_name)
            );

            foreach ($this->columns as $col) {
                if (!in_array($col['name'], $existing_columns)) {
                    $sql = sprintf("ALTER TABLE `%s` ADD COLUMN %s", $this->table_name, $col['definition']);
                    if ($this->db->query($sql) === false) {
                        throw new \RuntimeException("Failed to add column {$col['name']}: " . $this->db->last_error);
                    }
                    $altered = true;
                }
            }

            foreach ($this->indexes as $name => $index) {
                if (!in_array($name, $existing_indexes)) {
                    $sql = sprintf("ALTER TABLE `%s` ADD %s", $this->table_name, $index['definition']);
                    if ($this->db->query($sql) === false) {
                        error_log("Warning: Failed to add index $name: " . $this->db->last_error);
                    } else {
                        $altered = true;
                    }
                }
            }

            foreach ($this->foreign_keys as $name => $fk) {
                if (!in_array($name, $existing_fks)) {
                    $sql = sprintf("ALTER TABLE `%s` ADD %s", $this->table_name, $fk['definition']);
                    if ($this->db->query($sql) === false) {
                        throw new \RuntimeException("Failed to add foreign key $name: " . $this->db->last_error);
                    }
                    $altered = true;
                }
            }

            if ($altered && $this->version !== null) {
                update_option($this->table_name . '_version', $this->version);
            }

            $this->db->query('COMMIT');
            return $altered;
        } catch (\Exception $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }
}
