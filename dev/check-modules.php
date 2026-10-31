<?php
/** Registry and distribution integrity, independent of a running WordPress installation. */
define('ABSPATH',__DIR__.'/');define('ADMINKIT_PATH',dirname(__DIR__).'/');
require ADMINKIT_PATH.'inc/class-plugin.php';
$modules=AdminKit_Plugin::MODULES;
foreach($modules as$path=>$boot){
 if(strpos($path,'..')!==false||!is_file(ADMINKIT_PATH.'inc/'.$path))throw new RuntimeException('Missing/unsafe module: '.$path);
 require_once ADMINKIT_PATH.'inc/'.$path;
}
foreach($modules as$path=>$boot){if($boot!==null&&!is_callable($boot))throw new RuntimeException('Invalid module entrypoint: '.$path);}
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ADMINKIT_PATH.'inc',FilesystemIterator::SKIP_DOTS))as$file){
 $relative=substr($file->getPathname(),strlen(ADMINKIT_PATH.'inc/'));
 if($file->getExtension()==='php'&&$relative!=='class-plugin.php'&&strpos($relative,'integrations/')!==0&&!array_key_exists($relative,$modules))throw new RuntimeException('Unregistered module: '.$relative);
}
// A duplicate associative key in MODULES would disappear before iteration.
$source=file_get_contents(ADMINKIT_PATH.'inc/class-plugin.php');
$registry=substr($source,strpos($source,'const MODULES = array('));$registry=substr($registry,0,strpos($registry,"\n\t);"));
preg_match_all('/[\'"]([^\'"]+\.php)[\'"]\s*=>/',$registry,$paths);
if(count($paths[1])!==count($modules))throw new RuntimeException('Duplicate module registration');
function package_files(array$args):array{
 $command=array_merge(array(PHP_BINARY,ADMINKIT_PATH.'dev/package.php','--dry-run'),$args);$pipes=array();
 $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,ADMINKIT_PATH);
 if(!is_resource($process))throw new RuntimeException('Cannot start packager');
 fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($process)!==0)throw new RuntimeException('Package dry-run failed: '.$err.$out);
 preg_match_all('/^    (.+)$/m',$out,$matches);return$matches[1];
}
$head=package_files(array());$working=package_files(array('--working-tree'));
if(!$head||!$working)throw new RuntimeException('Empty package');
foreach($modules as$path=>$boot)if(!in_array('inc/'.$path,$working,true))throw new RuntimeException('Runtime module excluded from working package: '.$path);
foreach(array($head,$working)as$package){
 foreach(array('adminkit.php','uninstall.php','languages/adminkit.pot','languages/adminkit-fr_FR.po','languages/adminkit-fr_FR.mo','languages/adminkit-fr_FR.l10n.php')as$required){if(!in_array($required,$package,true))throw new RuntimeException('Package missing '.$required);}
 foreach($package as$file)if(preg_match('~^(?:dev|tokens|docs|\.git|\.github|node_modules|vendor)/|(?:^|/)(?:AGENTS|CLAUDE|README)\.md$|(?:^|/)baseline\.json$~',$file))throw new RuntimeException('Development file shipped: '.$file);
}
echo 'OK: '.count($modules).' callable/present modules, no orphan/duplicate files; HEAD and working-tree package dry-runs clean ('.count($working)." runtime files).\n";
