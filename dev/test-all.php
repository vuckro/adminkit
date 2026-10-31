<?php
/** Single offline quality gate. Requires PHP 7.4+, Node, gettext, Git and rsync. */
declare(strict_types=1);
$root=dirname(__DIR__);chdir($root);$started=microtime(true);$failures=array();$checks=0;
/** Drain both pipes concurrently, including a verbose failing child, without deadlocks. */
function ak_test_run(array$command):array{
 $pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
 if(!is_resource($process))return array(1,'Cannot start '.$command[0]);
 fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$output='';
 while(!feof($pipes[1])||!feof($pipes[2])){
  $read=array();foreach(array($pipes[1],$pipes[2])as$pipe)if(!feof($pipe))$read[]=$pipe;
  if(!$read)break;$write=null;$except=null;
  if(stream_select($read,$write,$except,5)>0)foreach($read as$pipe)$output.=stream_get_contents($pipe);
 }
 fclose($pipes[1]);fclose($pipes[2]);return array(proc_close($process),$output);
}
function ak_test_group(string$label,array$commands):void{
 global$failures,$checks;$bad=false;
 foreach($commands as$command){++$checks;list($code,$out)=ak_test_run($command);if($code!==0){$bad=true;$failures[]=$label.' / '.implode(' ',$command);fwrite(STDERR,"FAIL: ".implode(' ',$command)."\n".$out."\n");}}
 echo($bad?'FAIL':'PASS').': '.$label.' ('.count($commands).")\n";
}
$php=array();$js=array();
$filter=new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),static function($entry){return!$entry->isDir()||!in_array($entry->getFilename(),array('.git','node_modules','vendor'),true);});
foreach(new RecursiveIteratorIterator($filter)as$file){if($file->getExtension()==='php')$php[]=$file->getPathname();if($file->getExtension()==='js')$js[]=$file->getPathname();}
sort($php);sort($js);
ak_test_group('PHP syntax',array_map(static function($file){return array(PHP_BINARY,'-l',$file);},$php));
ak_test_group('JavaScript syntax',array_map(static function($file){return array('node','--check',$file);},$js));
ak_test_group('Generated tokens',array(array(PHP_BINARY,'tokens/build.php','--check')));
$tests=glob('dev/check*.php');sort($tests);$tests[]='dev/editor-gate-check.php';
ak_test_group('Regression checks (including i18n/modules/packaging)',array_map(static function($file){return array(PHP_BINARY,$file);},$tests));
ak_test_group('CSS contracts and adapter budgets',array(array(PHP_BINARY,'dev/css-contract.php'),array(PHP_BINARY,'dev/adapter-audit.php')));
ak_test_group('Real jsdom settings modules',array(array('node','dev/smoke/boot.test.js'),array('node','dev/smoke/saving.test.js'),array('node','dev/smoke/design.test.js'),array('node','dev/smoke/users.test.js'),array('node','dev/smoke/navigation.test.js')));
$elapsed=round(microtime(true)-$started,1);
if($failures){fwrite(STDERR,count($failures)." failed checks out of $checks ($elapsed s).\n");exit(1);}
echo "OK: all $checks checks passed ($elapsed s). External host drift/browser checks remain separate.\n";
