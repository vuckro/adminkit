<?php
/** Offline failure-injection checks; never loads or writes a WordPress site. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { public $code; public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; } }
function __( $text, $domain ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$options = array(); $fail_option = false; $transients = array();
function get_option( $key, $default = false ) { global $options; return $options[$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { global $options, $fail_option; if ($fail_option || get_option($key) === $value) return false; $options[$key] = $value; return true; }
function get_site_option( $key ) { return AdminKit_Menu_Store::DB_VERSION; }
function get_current_blog_id() { return 1; }
function current_time( $format ) { return '2026-10-31 12:00:00'; }
function get_transient( $key ) { global $transients; return $transients[$key] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[$key] = $value; }
function delete_transient( $key ) { global $transients; unset($transients[$key]); }
class Storage_Test_DB {
 public $base_prefix = 'wp_', $last_error = '', $rows = array(), $backup, $fail = '', $engine = 'InnoDB', $deletes = 0, $inserts = 0, $reads = 0;
 public function prepare($sql, ...$args) { return $sql; }
 public function get_row($sql, $format) { return array('Engine' => $this->engine); }
 public function query($sql) {
  if ($this->fail === $sql) return false;
  if ($sql === 'START TRANSACTION') $this->backup = $this->rows;
  if ($sql === 'ROLLBACK') $this->rows = $this->backup;
  return 0;
 }
 public function delete($table, $where, $format) { ++$this->deletes; if ($this->fail === 'delete') return false; $this->rows = array_values(array_filter($this->rows, function($row) use ($where) { return $row['blog_id'] !== $where['blog_id']; })); return 1; }
 public function insert($table, $row, $formats) { ++$this->inserts; if ($this->fail === 'insert' && $this->inserts === 2) return false; $this->rows[] = $row; return 1; }
 public function get_results($sql, $format) { ++$this->reads; return $this->fail === 'read' ? null : $this->rows; }
}
require dirname(__DIR__) . '/inc/class-storage.php';
require dirname(__DIR__) . '/inc/features/menu/class-menu-store.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
check(true === AdminKit_Storage::save_option('test', array('x' => false)), 'Initial option save failed');
check(true === AdminKit_Storage::save_option('test', array('x' => false)), 'No-op save must succeed');
$fail_option = true;
check(is_wp_error(AdminKit_Storage::save_option('test', array('x' => true))), 'Failed option write reported success');
$old = array(array('blog_id' => 1, 'parent' => '', 'slug' => 'old', 'position' => 0, 'icon' => ''), array('blog_id' => 2, 'parent' => '', 'slug' => 'other-site'));
foreach (array('delete', 'insert', 'COMMIT', 'START TRANSACTION') as $failure) {
 $wpdb = new Storage_Test_DB(); $wpdb->rows = $old; $wpdb->fail = $failure;
 check(is_wp_error(AdminKit_Menu_Store::save_config(array(array('slug'=>'new'), array('slug'=>'next')))), 'Failed menu write reported success: ' . $failure);
 check($wpdb->rows === $old, 'Previous layout lost: ' . $failure);
}
$wpdb = new Storage_Test_DB(); $wpdb->rows = $old; $wpdb->engine = 'MyISAM';
check(is_wp_error(AdminKit_Menu_Store::save_config(array())), 'Nontransactional save must fail closed');
check($wpdb->deletes === 0 && $wpdb->rows === $old, 'Nontransactional table mutated');
$wpdb->engine = 'InnoDB';
check(true === AdminKit_Menu_Store::save_config(array(array('slug'=>'new'))), 'Valid menu save failed');
check(count($wpdb->rows) === 2 && $wpdb->rows[0]['blog_id'] === 2 && $wpdb->rows[1]['slug'] === 'new', 'Site isolation failed');
$wpdb->fail = 'read';
AdminKit_Menu_Store::get_config(9);
check(!isset($transients[AdminKit_Menu_Store::CACHE_PREFIX . 9]), 'Query failure poisoned transient');
echo "OK: option no-op/failure, menu rollback, engine guard, site isolation and failed-read caching.\n";
