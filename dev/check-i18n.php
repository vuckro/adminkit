<?php
/** Catalog drift plus the PHP-bootstrap contract used by build-free JavaScript. */
$root=dirname(__DIR__);chdir($root);
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/i18n.php').' --check';
passthru($command,$status);if($status)exit($status);
$php='';foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('inc',FilesystemIterator::SKIP_DOTS))as$file)if($file->getExtension()==='php')$php.=file_get_contents($file->getPathname())."\n";
preg_match_all('/[\'"]([A-Za-z][A-Za-z0-9_]*)[\'"]\s*=>/',$php,$matches);$keys=array_fill_keys($matches[1],true);
$brands=array('AdminKit','Lucide','Geist (AdminKit)','Inter (Google Fonts)','Plus Jakarta Sans (Google Fonts)','DM Sans (Google Fonts)','Manrope (Google Fonts)','Outfit (Google Fonts)','Poppins (Google Fonts)','Space Grotesk (Google Fonts)','IBM Plex Sans (Google Fonts)');
preg_match_all('/[\'"]([A-Za-z][A-Za-z0-9_]*)[\'"]\s*=>\s*(?:__|esc_html__|esc_attr__)\(/',$php,$translated);
$translated_keys=array_fill_keys($translated[1],true);
$technical=array_fill_keys(array('pendingClass','readyClass','cards','sections','role_levels','role_descriptions','ajaxUrl'),true);
$checked=0;
foreach(array('assets/js','inc/integrations')as$directory){
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$file){
  if($file->getExtension()!=='js')continue;$source=file_get_contents($file->getPathname());
  // Remove comments before checking UI literals (preserve source strings and regexes).
  $source=preg_replace('~/\*.*?\*/~s','',$source);
  if(preg_match('/\b(?:__|_x|_n|_nx)\s*\(/',$source))throw new RuntimeException('JS gettext needs an extractor/runtime loader; use the existing PHP bootstrap: '.$file->getPathname());
  preg_match_all('/\b(I|S|L|DATA|D|C)\.([A-Za-z][A-Za-z0-9_]*)/',$source,$uses,PREG_SET_ORDER);
  foreach($uses as$use){if($use[1]==='I'&&basename($file)==='profile-account.js')continue;if(!isset($keys[$use[2]]))throw new RuntimeException('Missing PHP bootstrap key '.$use[2].' for '.$file->getPathname());if(in_array($use[1],array('I','S','L','DATA'),true)&&!isset($translated_keys[$use[2]])&&!isset($technical[$use[2]]))throw new RuntimeException('JS copy is not a translated PHP bootstrap value: '.$use[2]);++$checked;}
  $literal='(?:\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")';
  preg_match_all('/(?:\btext|\btitle|\blabel|\bplaceholder|[\'"]aria-label[\'"])\s*:\s*('.$literal.')/',$source,$strings);
  preg_match_all('/(?:\btextContent\s*=|\.(?:title|placeholder)\s*=|\bcreateTextNode\s*\(|\b(?:alert|confirm)\s*\()\s*('.$literal.')/',$source,$extra);
  $strings[1]=array_merge($strings[1],$extra[1]);
  foreach($strings[1]as$quoted){$text=substr($quoted,1,-1);if(preg_match('/[A-Za-z]/',$text)&&!in_array($text,$brands,true)&&!preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i',$text))throw new RuntimeException('Untranslated JS UI literal '.$quoted.' in '.$file->getPathname());}
  if(preg_match('/\b(?:I|S|L|DATA)\.[A-Za-z0-9_]+\s*\|\|\s*[\'"][A-Za-z]/',$source))throw new RuntimeException('Untranslated JS fallback in '.$file->getPathname());
 }
}
echo "OK: $checked JS bootstrap references; UI copy is centralized in translated PHP; brand names are explicit exceptions.\n";
