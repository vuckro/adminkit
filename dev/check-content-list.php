<?php
/** Run: php dev/check-content-list.php. No WordPress/database writes. */
define( 'ABSPATH', __DIR__ );
class AdminKit_Settings { public static $enabled = false; public static $choices = array(); public static function get( $key ) { return $key === 'active_items_first_enabled' ? self::$enabled : (self::$choices[$key] ?? true); } }
require dirname( __DIR__ ) . '/inc/wp-core/class-content-list.php';
class Test_Redirect extends RuntimeException {}
class Test_Query {
 public $vars; public $main = true;
 public function __construct( $vars ) { $this->vars = $vars; }
 public function get( $key ) { return $this->vars[$key] ?? ''; }
 public function is_main_query() { return $this->main; }
}
$screen=(object) array('base'=>'edit','post_type'=>'post');
$wpdb=(object) array('posts'=>'wp_posts');
$hooks=0; $referer=''; $published=1;
function apply_filters( $key, $value ) { return $value; }
function add_action( $hook, $callback ) { global $hooks; ++$hooks; }
function add_filter( $hook, $callback, $priority, $args ) {}
function get_current_screen() { global $screen; return $screen; }
function is_admin() { return true; }
function wp_get_referer() { global $referer; return $referer; }
function wp_parse_url( $url, $component ) { return parse_url($url,$component); }
function wp_count_posts( $type, $permission ) { global $published; return (object) array('publish'=>$published); }
function self_admin_url( $path ) { return 'http://test/wp-admin/'.$path; }
function add_query_arg( $args, $url ) { return $url.'?'.http_build_query($args); }
function wp_safe_redirect( $url ) { throw new Test_Redirect($url); }
AdminKit_Content_List::init();
if($hooks) throw new RuntimeException('Disabled module registered hooks.');
AdminKit_Settings::$enabled=true; AdminKit_Content_List::init();
if($hooks!==1) throw new RuntimeException('Enabled module missing hook.');
$_SERVER['REQUEST_METHOD']='GET'; $_GET=array();
foreach(array('post','page','book') as $type){
 $screen->post_type=$type;
 try { AdminKit_Content_List::on_content_screen(); throw new RuntimeException('Missing published redirect.'); }
 catch(Test_Redirect $e){if(strpos($e->getMessage(),'post_status=publish')===false)throw $e;}
 $query=new Test_Query(array('post_type'=>$type));
 if(strpos(AdminKit_Content_List::published_first('wp_posts.post_date DESC',$query),'CASE WHEN')!==0)throw new RuntimeException('Missing published priority.');
 foreach(array('post_status'=>'draft','s'=>'search') as $key=>$value){$query->vars[$key]=$value;if(AdminKit_Content_List::published_first('native',$query)!=='native')throw new RuntimeException('Explicit query changed.');unset($query->vars[$key]);}
 $query->main=false;if(AdminKit_Content_List::published_first('native',$query)!=='native')throw new RuntimeException('Secondary query changed.');
}
$screen->post_type='post';
foreach(array(array('post_status'=>'all'),array('s'=>'search'),array('book_genre'=>'fiction'),array('orderby'=>'title')) as $params){$_GET=$params;AdminKit_Content_List::on_content_screen();}
$_GET=array();$referer='http://test/wp-admin/edit.php?post_status=publish';AdminKit_Content_List::on_content_screen();
$referer='';$published=0;AdminKit_Content_List::on_content_screen();
$published=1;$_GET=array();AdminKit_Settings::$choices=array('active_items_first_content_default'=>false,'active_items_first_sort'=>true);
AdminKit_Content_List::on_content_screen();
if(strpos(AdminKit_Content_List::published_first('native',new Test_Query(array('post_type'=>'post'))),'CASE WHEN')!==0)throw new RuntimeException('Turning landing off also disabled sorting');
AdminKit_Settings::$choices=array('active_items_first_sort'=>false,'active_items_first_content_default'=>true);
if(AdminKit_Content_List::published_first('native',new Test_Query(array('post_type'=>'post')))!=='native')throw new RuntimeException('Disabled sort still changed query');
try { AdminKit_Content_List::on_content_screen(); throw new RuntimeException('Turning sorting off also disabled landing'); } catch(Test_Redirect $expected) {}
$views = AdminKit_Content_List::preserve_all_view_link( array( 'all' => '<a href="edit.php">All</a>', 'publish' => '<a href="edit.php?post_status=publish">Published</a>' ) );
if ( strpos( $views['all'], 'edit.php?all_posts=1' ) === false ) throw new RuntimeException( 'Single-author All link missing all_posts=1 for no-referrer safety' );
$cpt_views = AdminKit_Content_List::preserve_all_view_link( array( 'all' => '<a href="edit.php?post_type=page">All</a>' ) );
if ( strpos( $cpt_views['all'], 'edit.php?post_type=page&amp;all_posts=1' ) === false ) throw new RuntimeException( 'CPT All link missing all_posts=1' );
echo "OK: opt-in post/page/CPT priority; explicit filters/search, All navigation (incl. no-referrer), secondary queries and empty lists preserved.\n";
