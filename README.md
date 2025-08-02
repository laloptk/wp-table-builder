# TableBuilder for WordPress Plugins

A small, developer-friendly PHP utility to **create custom MySQL tables with foreign key constraints** in WordPress — without relying on `dbDelta()`.

## 🧠 Why this exists

WordPress provides the `dbDelta()` function to create and update database tables. However, `dbDelta()`:

- **Does not support foreign key constraints**
- Is designed to be lenient for broad compatibility, often ignoring important changes
- Can be verbose and hard to read/maintain

This `TableBuilder` class offers an **alternative approach for plugin developers who need stricter, relational table definitions**, especially when building systems that benefit from referential integrity.

---

## ✅ What it does

- Builds valid SQL `CREATE TABLE` statements in a **safe and clean way**
- Supports:
  - Columns with types, nullability, default values
  - Primary keys
  - Unique and non-unique indexes
  - Foreign key constraints
  - Table options (`ENGINE`, `CHARSET`, etc.)
- Automatically escapes and quotes identifiers
- Optional `IF NOT EXISTS` clause
- Outputs SQL string or executes it via `$wpdb->query`

---

## 🚫 What it doesn't do

- **No auto-updates**: It doesn’t compare schemas or alter existing tables. You must handle versioning yourself.
- **No schema introspection**: It doesn’t read or validate against current DB state.
- **No support for composite keys (yet)**.
- Not intended for use with multisite global table registration (though it may work).
- Not tested with non-MySQL/MariaDB databases.

---

## 🛠 Example usage

```php
use MyPlugin\Framework\DB\TableBuilder;

$builder = new TableBuilder('myplugin_notes');
$builder
    ->add_column('id', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT')
    ->add_column('user_id', 'BIGINT UNSIGNED NOT NULL')
    ->add_column('content', 'TEXT NOT NULL')
    ->add_column('created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP')
    ->set_primary_key('id')
    ->add_index('user_id_index', ['user_id'])
    ->add_foreign_key('user_id', $wpdb->prefix . 'users', 'ID', 'CASCADE', 'CASCADE')
    ->set_table_options('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$sql = $builder->get_sql();         // For debugging
$success = $builder->execute();    // Run query with $wpdb->query()
```

---

## ⚙️ Best practices

- Always validate the SQL before executing in production.
- Use version control to track table schema changes.
- Avoid relying on this in plugins that must run on database engines other than MySQL/MariaDB.
- Use `dbDelta()` when you **don’t need** foreign keys or strict control.

---

## 📦 Integration

This class is framework-agnostic and can be dropped into any plugin or mu-plugin. Just make sure it's loaded before activation hooks run.

Recommended: Use during plugin activation:

```php
register_activation_hook(__FILE__, function () {
    (new MyPlugin\DB\MyTableMigrator())->create_tables();
});
```

---

## 🔒 Security

- Always sanitize table and column names using `sanitize_key()` or similar before passing dynamic values.
- Does **not** support user-generated input for column definitions or SQL values. This is a **developer tool**, not for runtime input.

---


---

## 🔄 What gets updated

When using `createOrUpdate()` with a version string, the builder will automatically:

- ✅ Add new **columns**
- ✅ Add new **indexes**
- ✅ Add new **foreign key constraints**

But it will **NOT**:

- ❌ Drop or rename existing columns
- ❌ Modify column types or definitions
- ❌ Remove or rename indexes or foreign keys
- ❌ Handle complex data migrations or content changes

If you need those, consider combining `TableBuilder` with your own versioned migration callbacks.

Example strategy:

```php
$migrations = [
    '1.2.0' => function () {
        // Populate new column or clean up old data
    },
    '1.3.0' => function () {
        // Drop old column or restructure
    },
];
```

You can run these after `createOrUpdate()` to maintain full control.

## 📋 License

MIT — free to use and modify. No warranty.

---

## ✍️ Author

Originally crafted by [Eduardo Sanchez Hidalgo](https://eduardos-portfolio.netlify.app/) with the help of ChatGPT to fill a real-world need in WordPress plugin development.