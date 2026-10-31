<?php
/** Deterministic catalogs. Run --write to synchronize; --check never writes the worktree. */
declare(strict_types=1);
$root=dirname(__DIR__); chdir($root);
function ak_po_read(string $source):array {
 $entries=array();
 foreach(preg_split('/\n\s*\n/',trim($source))as$block){
  $entry=array('refs'=>array(),'msgstr'=>array());$field=null;$index=0;
  foreach(explode("\n",$block)as$line){
   if(strpos($line,'#: ')===0){$entry['refs']=array_merge($entry['refs'],preg_split('/\s+/',substr($line,3)));continue;}
   if(strpos($line,'#,')===0){$entry['flags']=trim(substr($line,2));if(strpos($line,'fuzzy')!==false)$entry['fuzzy']=true;}
   if(preg_match('/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+(".*")$/',$line,$m)){
    $field=$m[1];$index=isset($m[2])&&$m[2]!==''?(int)$m[2]:0;$value=json_decode($m[3],true,512,JSON_THROW_ON_ERROR);
    if($field==='msgstr')$entry[$field][$index]=$value;else$entry[$field]=$value;
   }elseif(preg_match('/^(".*")$/',$line,$m)&&$field){
    $value=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
    if($field==='msgstr')$entry[$field][$index].=$value;else$entry[$field].=$value;
   }
  }
  if(!isset($entry['msgid'])||$entry['msgid']==='')continue;
  $key=isset($entry['msgctxt'])?$entry['msgctxt']."\4".$entry['msgid']:$entry['msgid'];
  $entries[$key]=$entry;
 }
 return$entries;
}
function ak_command(array $command):string {
 $pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
 if(!is_resource($process))throw new RuntimeException('Cannot run '.$command[0]);
 fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
 if($status!==0)throw new RuntimeException(implode(' ',$command)."\n".$err);
 return$out;
}
function ak_quote(string $text):string {return json_encode($text,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function ak_po_header(bool $french):string {
 $fields=array('Project-Id-Version: AdminKit 1.0.0','Report-Msgid-Bugs-To: https://github.com/vuckro/adminkit/issues','POT-Creation-Date: 2026-10-31 00:00+0100','PO-Revision-Date: 2026-10-31 00:00+0100','Last-Translator: AdminKit','Language-Team: '.($french?'French':''),'Language: '.($french?'fr_FR':''),'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit','Plural-Forms: nplurals=2; plural=(n > 1);','X-Domain: adminkit');
 $out="# AdminKit — ".($french?'French translation.':'translation template.')."\n# Copyright (C) 2026 Waaskit\n# GPL-2.0-or-later\nmsgid \"\"\nmsgstr \"\"\n";
 foreach($fields as$field)$out.=ak_quote($field."\n")."\n";
 return$out."\n";
}
$temp=sys_get_temp_dir().'/adminkit-i18n-'.bin2hex(random_bytes(6));mkdir($temp,0700);
register_shutdown_function(static function()use($temp){foreach(glob($temp.'/*')?:array()as$file)unlink($file);rmdir($temp);});
try{
 $files=array('adminkit.php','uninstall.php');
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator('inc',FilesystemIterator::SKIP_DOTS));
 foreach($it as$file)if($file->getExtension()==='php')$files[]=str_replace('\\','/',$file->getPathname());sort($files);
 $command=array('xgettext','--language=PHP','--from-code=UTF-8','--keyword=','--keyword=__:1','--keyword=_e:1','--keyword=_x:1,2c','--keyword=_ex:1,2c','--keyword=_n:1,2','--keyword=_nx:1,2,4c','--keyword=esc_html__:1','--keyword=esc_attr__:1','--keyword=esc_html_e:1','--keyword=esc_attr_e:1','--keyword=esc_html_x:1,2c','--keyword=esc_attr_x:1,2c','--add-location=file','--no-wrap','--output='.$temp.'/source.pot');
 ak_command(array_merge($command,$files));
 $source=ak_po_read(file_get_contents($temp.'/source.pot'));ksort($source,SORT_STRING);
 // WordPress translates plugin metadata dynamically, outside explicit gettext calls.
 $header=file_get_contents('adminkit.php');
 foreach(array('Plugin Name','Description','Author') as $field){
  if(preg_match('/^ \* '.preg_quote($field,'/').':\s*(.+)$/m',$header,$match)){
   $message=trim($match[1]);if(!isset($source[$message]))$source[$message]=array('msgid'=>$message,'refs'=>array('adminkit.php'),'msgstr'=>array());
  }
 }
 ksort($source,SORT_STRING);
 $existing=ak_po_read(file_get_contents('languages/adminkit-fr_FR.po'));
 $missing=array();foreach($source as$key=>$entry)if(!isset($existing[$key])||empty($existing[$key]['msgstr'][0])||!empty($existing[$key]['fuzzy']))$missing[$key]=$entry['refs'];
 if(in_array('--missing',$argv,true)){echo json_encode($missing,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";exit;}
 if($missing)throw new RuntimeException('Missing French translations: '.implode(', ',array_keys($missing)));
 $pot=ak_po_header(false);$po=ak_po_header(true);$messages=array();
 foreach($source as$key=>$entry){
  $base='#: '.implode(' ',array_unique($entry['refs']))."\n";
  if(!empty($entry['flags']))$base.='#, '.$entry['flags']."\n";
  if(isset($entry['msgctxt']))$base.='msgctxt '.ak_quote($entry['msgctxt'])."\n";
  $base.='msgid '.ak_quote($entry['msgid'])."\n";
  if(isset($entry['msgid_plural']))$base.='msgid_plural '.ak_quote($entry['msgid_plural'])."\n";
  $pot.=$base;$po.=$base;
  $count=isset($entry['msgid_plural'])?2:1;$translations=array();
  for($i=0;$i<$count;$i++){
   if(!isset($existing[$key]['msgstr'][$i])||$existing[$key]['msgstr'][$i]==='')throw new RuntimeException('Missing plural translation: '.$key);
   $label='msgstr'.($count>1?'['.$i.']':'').' ';
   $pot.=$label."\"\"\n";$po.=$label.ak_quote($existing[$key]['msgstr'][$i])."\n";$translations[]=$existing[$key]['msgstr'][$i];
  }
  $pot.="\n";$po.="\n";$messages[$key]=implode("\0",$translations);
 }
 $l10n="<?php\n// Generated by php dev/i18n.php --write.\nreturn ".var_export(array('project-id-version'=>'AdminKit 1.0.0','language'=>'fr_FR','plural-forms'=>'nplurals=2; plural=(n > 1);','messages'=>$messages),true).";\n";
 file_put_contents($temp.'/fr.po',$po);ak_command(array('msgfmt','--check','--check-format','--output-file='.$temp.'/fr.mo',$temp.'/fr.po'));
 $artifacts=array('languages/adminkit.pot'=>rtrim($pot)."\n",'languages/adminkit-fr_FR.po'=>rtrim($po)."\n",'languages/adminkit-fr_FR.mo'=>file_get_contents($temp.'/fr.mo'),'languages/adminkit-fr_FR.l10n.php'=>$l10n);
 $drift=array();foreach($artifacts as$file=>$contents){if(in_array('--write',$argv,true))file_put_contents($file,$contents);elseif(!is_file($file)||file_get_contents($file)!==$contents)$drift[]=$file;}
 if($drift)throw new RuntimeException('i18n drift: '.implode(', ',$drift).'. Run php dev/i18n.php --write.');
 echo 'OK: '.count($source)." active messages; POT/PO/MO/PHP catalogs agree with source, no missing/dead entries.\n";
}catch(Throwable$error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
