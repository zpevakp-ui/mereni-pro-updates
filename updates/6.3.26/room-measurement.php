<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/layout.php';
require_login();

$roomId=(int)($_GET['room_id']??$_POST['room_id']??0);
if(!$roomId)redirect_to('index.php');

$stmt=$pdo->prepare("SELECT r.*,rp.id report_id,rp.report_number,rp.norm_required_lux,rp.norm_uniformity,rp.norm_label,rp.use_correction,rp.source_type,rp.correction_kd,j.name job_name,c.name customer_name FROM mp_rooms r JOIN mp_reports rp ON rp.id=r.report_id JOIN mp_jobs j ON j.id=rp.job_id JOIN mp_customers c ON c.id=j.customer_id WHERE r.id=?");$stmt->execute(array($roomId));$room=$stmt->fetch();if(!$room)redirect_to('index.php');

function fp_latest($pdo,$roomId){$s=$pdo->prepare("SELECT * FROM mp_floorplans WHERE room_id=? ORDER BY id DESC LIMIT 1");$s->execute(array($roomId));return $s->fetch();}
function fp_path($fp){return __DIR__.'/data/floorplans/'.$fp['stored_name'];}
function xlsx_col_index($ref){$letters=preg_replace('/[^A-Z]/','',strtoupper($ref));$n=0;for($i=0;$i<strlen($letters);$i++)$n=$n*26+(ord($letters[$i])-64);return $n-1;}
function xlsx_xml_doc($xml,$what){
 $dom=new DOMDocument();
 $old=libxml_use_internal_errors(true);
 $ok=$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS);
 libxml_clear_errors();libxml_use_internal_errors($old);
 if(!$ok)throw new Exception($what.' nelze přečíst.');
 return $dom;
}
function xlsx_rows($path){
 if(!class_exists('ZipArchive'))throw new Exception('Server nepodporuje čtení XLSX (chybí PHP ZipArchive).');
 if(!class_exists('DOMDocument'))throw new Exception('Server nepodporuje čtení XLSX (chybí PHP DOM).');
 $zip=new ZipArchive();if($zip->open($path)!==true)throw new Exception('Excel soubor se nepodařilo otevřít.');
 try{
  $shared=array();$ss=$zip->getFromName('xl/sharedStrings.xml');
  if($ss!==false){
   $dom=xlsx_xml_doc($ss,'Sdílené texty XLSX');$xp=new DOMXPath($dom);
   foreach($xp->query('//*[local-name()="si"]') as $si){$v='';foreach($xp->query('.//*[local-name()="t"]',$si) as $t)$v.=$t->textContent;$shared[]=$v;}
  }
  $sheetPath='xl/worksheets/sheet1.xml';
  $wb=$zip->getFromName('xl/workbook.xml');$rels=$zip->getFromName('xl/_rels/workbook.xml.rels');
  if($wb!==false&&$rels!==false){
   $wdom=xlsx_xml_doc($wb,'Sešit XLSX');$wx=new DOMXPath($wdom);$first=$wx->query('//*[local-name()="sheets"]/*[local-name()="sheet"][1]')->item(0);
   if($first){
    $rid=$first->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
    if($rid==='')$rid=$first->getAttribute('r:id');
    $rdom=xlsx_xml_doc($rels,'Vztahy XLSX');$rx=new DOMXPath($rdom);
    foreach($rx->query('//*[local-name()="Relationship"]') as $rel)if($rel->getAttribute('Id')===$rid){
     $target=str_replace('\\','/',$rel->getAttribute('Target'));$target=preg_replace('~^\.\./~','',$target);
     $sheetPath=(strpos($target,'xl/')===0)?$target:'xl/'.$target;break;
    }
   }
  }
  $sheet=$zip->getFromName($sheetPath);
  if($sheet===false)throw new Exception('V XLSX nebyl nalezen první list.');
  $dom=xlsx_xml_doc($sheet,'První list XLSX');$xp=new DOMXPath($dom);$rows=array();
  foreach($xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row){
   $out=array();
   foreach($xp->query('./*[local-name()="c"]',$row) as $c){
    $idx=xlsx_col_index($c->getAttribute('r'));$type=$c->getAttribute('t');$v='';
    if($type==='inlineStr'){foreach($xp->query('.//*[local-name()="is"]//*[local-name()="t"]',$c) as $t)$v.=$t->textContent;}
    else{$vn=$xp->query('./*[local-name()="v"]',$c)->item(0);if($vn){$raw=$vn->textContent;$v=($type==='s'&&isset($shared[(int)$raw]))?$shared[(int)$raw]:$raw;}}
    $out[$idx]=$v;
   }
   if($out){ksort($out);$rows[]=$out;}
  }
  return $rows;
 }finally{$zip->close();}
}
function php_point_on_segment($px,$py,$ax,$ay,$bx,$by,$eps=1.0e-9){$cross=($px-$ax)*($by-$ay)-($py-$ay)*($bx-$ax);if(abs($cross)>$eps)return false;$dot=($px-$ax)*($bx-$ax)+($py-$ay)*($by-$ay);if($dot<-$eps)return false;$len2=($bx-$ax)*($bx-$ax)+($by-$ay)*($by-$ay);return $dot<=$len2+$eps;}
function php_point_in_polygon($x,$y,$poly){$n=count($poly);if($n<3)return false;for($i=0,$j=$n-1;$i<$n;$j=$i++)if(php_point_on_segment($x,$y,$poly[$j][0],$poly[$j][1],$poly[$i][0],$poly[$i][1]))return true;$inside=false;for($i=0,$j=$n-1;$i<$n;$j=$i++){$xi=$poly[$i][0];$yi=$poly[$i][1];$xj=$poly[$j][0];$yj=$poly[$j][1];$inter=(($yi>$y)!==($yj>$y))&&($x<($xj-$xi)*($y-$yi)/(($yj-$yi)?:1.0e-12)+$xi);if($inter)$inside=!$inside;}return $inside;}
function correction_k2856_for_lux($lux){if($lux<=20)return 0.949;if($lux<=200)return 0.946;if($lux<=2000)return 0.951;if($lux<=20000)return 0.950;return 0.951;}
function correction_kd_for_room($room){if(empty($room['use_correction']))return 1.0;if(!empty($room['correction_kd']))return (float)$room['correction_kd'];$src=$room['source_type']??'';if($src==='LED 4000K')return 0.993;if($src==='LED 6000K')return 0.989;return 1.0;}

$error='';
if($_SERVER['REQUEST_METHOD']==='POST')try{
 verify_csrf();$action=$_POST['action']??'';
 if($action==='upload'){
  if(empty($_FILES['floorplan']['tmp_name'])||$_FILES['floorplan']['error']!==UPLOAD_ERR_OK)throw new Exception('Vyber obrázek půdorysu.');
  $info=getimagesize($_FILES['floorplan']['tmp_name']);if(!$info)throw new Exception('Soubor není platný obrázek.');
  $mime=$info['mime'];$ext=$mime==='image/png'?'png':($mime==='image/jpeg'?'jpg':'');if(!$ext)throw new Exception('Podporován je JPG nebo PNG.');
  $dir=__DIR__.'/data/floorplans';if(!is_dir($dir)&&!mkdir($dir,0775,true))throw new Exception('Nelze vytvořit složku pro půdorysy.');
  $stored=bin2hex(random_bytes(16)).'.'.$ext;if(!move_uploaded_file($_FILES['floorplan']['tmp_name'],$dir.'/'.$stored))throw new Exception('Nahrání se nezdařilo.');
  $pdo->prepare("INSERT INTO mp_floorplans(room_id,original_name,stored_name,mime_type,width_px,height_px) VALUES(?,?,?,?,?,?)")->execute(array($roomId,$_FILES['floorplan']['name'],$stored,$mime,$info[0],$info[1]));redirect_to('room-measurement.php?room_id='.$roomId.'&uploaded=1');
 }
 if($action==='import_xlsx'){
   if(empty($_FILES['xlsx_points']['tmp_name'])||$_FILES['xlsx_points']['error']!==UPLOAD_ERR_OK)throw new Exception('Vyber XLSX soubor s naměřenými hodnotami.');
   if(strtolower(pathinfo($_FILES['xlsx_points']['name'],PATHINFO_EXTENSION))!=='xlsx')throw new Exception('Import podporuje pouze soubory .xlsx.');
   $ms=$pdo->prepare("SELECT id,point_number FROM mp_measurements WHERE room_id=? ORDER BY point_number");$ms->execute(array($roomId));$existing=$ms->fetchAll();
   if(!$existing)throw new Exception('Nejdříve vytvoř měřicí síť a ulož půdorys a body. Potom importuj hodnoty z Excelu.');
   $byPoint=array();foreach($existing as $m)$byPoint[(int)$m['point_number']]=$m;
   $rows=xlsx_rows($_FILES['xlsx_points']['tmp_name']);if(count($rows)<2)throw new Exception('Excel neobsahuje žádné datové řádky.');
   $head=array_map(function($v){return trim(mb_strtolower((string)$v,'UTF-8'));},$rows[0]);
   $find=function($names)use($head){foreach($head as $i=>$h)foreach($names as $n)if($h===mb_strtolower($n,'UTF-8'))return $i;return null;};
   $ipoint=$find(array('bod','měřicí bod','merici bod','point'));
   $ilux=$find(array('naměřeno [lx]','namereno [lx]','hodnota [lx]','lux','lx'));
   if($ipoint===null||$ilux===null)throw new Exception('Chybí sloupce Bod nebo Naměřeno [lx]. Použij vzorový Excel.');
   $values=array();$unknown=array();$invalid=array();$duplicates=array();
   for($r=1;$r<count($rows);$r++){
    $row=$rows[$r];$braw=isset($row[$ipoint])?trim((string)$row[$ipoint]):'';$lraw=isset($row[$ilux])?str_replace(',','.',trim((string)$row[$ilux])):'';
    if($braw===''&&$lraw==='')continue;
    if(!preg_match('/^(?:M\s*)?(\d+)$/iu',$braw,$mm)||!is_numeric($lraw)||(float)$lraw<0){$invalid[]=$r+1;continue;}
    $pn=(int)$mm[1];if(!isset($byPoint[$pn])){$unknown[]='M'.$pn;continue;}
    if(isset($values[$pn])){$duplicates[]='M'.$pn;continue;}
    $values[$pn]=(float)$lraw;
   }
   if(!$values)throw new Exception('V Excelu nebyla nalezena žádná platná hodnota pro existující měřicí body.');
   $pdo->beginTransaction();$up=$pdo->prepare("UPDATE mp_measurements SET illuminance=? WHERE id=?");foreach($values as $pn=>$lux)$up->execute(array($lux,$byPoint[$pn]['id']));$pdo->commit();
   $missing=array();foreach($byPoint as $pn=>$m)if(!isset($values[$pn]))$missing[]='M'.$pn;
   $parts=array();if($unknown)$parts[]='neznámé body: '.implode(', ',array_unique($unknown));if($duplicates)$parts[]='duplicitní body: '.implode(', ',array_unique($duplicates));if($invalid)$parts[]='neplatné řádky: '.implode(', ',$invalid);if($missing)$parts[]='bez importované hodnoty: '.implode(', ',$missing);
   $_SESSION['xlsx_import_info']=$parts?implode('; ',$parts):'';
   redirect_to('room-measurement.php?room_id='.$roomId.'&imported='.count($values));
  }
 if($action==='save_editor'){
  $fp=fp_latest($pdo,$roomId);if(!$fp)throw new Exception('Nejdříve nahraj půdorys.');
  $scaleX=(float)($_POST['scale_x1']??0);$scaleY=(float)($_POST['scale_y1']??0);$scaleX2=(float)($_POST['scale_x2']??0);$scaleY2=(float)($_POST['scale_y2']??0);$scaleM=(float)($_POST['scale_real_m']??0);
  if($scaleM<=0||($scaleX===$scaleX2&&$scaleY===$scaleY2))throw new Exception('Nastav dva body měřítka a skutečnou vzdálenost.');
  $mode=($_POST['area_mode']??'rect')==='poly'?'poly':'rect';$polyJson=$_POST['area_polygon_json']??'';$poly=json_decode($polyJson,true);if(!is_array($poly))$poly=array();
  $ax=(float)($_POST['area_x1']??0);$ay=(float)($_POST['area_y1']??0);$ax2=(float)($_POST['area_x2']??0);$ay2=(float)($_POST['area_y2']??0);
  if($mode==='rect'&&($ax===$ax2||$ay===$ay2))throw new Exception('Nastav měřenou oblast.');if($mode==='poly'&&count($poly)<3)throw new Exception('Polygon musí mít nejméně 3 body.');
  $pdo->prepare("UPDATE mp_floorplans SET scale_x1=?,scale_y1=?,scale_x2=?,scale_y2=?,scale_real_m=?,area_mode=?,area_x1=?,area_y1=?,area_x2=?,area_y2=?,area_polygon_json=?,show_luminaires=? WHERE id=?")->execute(array($scaleX,$scaleY,$scaleX2,$scaleY2,$scaleM,$mode,$ax,$ay,$ax2,$ay2,json_encode($poly),!empty($_POST['show_luminaires'])?1:0,$fp['id']));
  $points=json_decode($_POST['points_json']??'[]',true);if(!is_array($points))$points=array();$pdo->beginTransaction();$pdo->prepare("DELETE FROM mp_measurements WHERE room_id=?")->execute(array($roomId));$ins=$pdo->prepare("INSERT INTO mp_measurements(room_id,point_number,illuminance,x_norm,y_norm) VALUES(?,?,?,?,?)");foreach($points as $i=>$p)$ins->execute(array($roomId,$i+1,isset($p['lux'])&&$p['lux']!==''?(float)$p['lux']:null,(float)$p['x'],(float)$p['y']));$pdo->commit();redirect_to('room-measurement.php?room_id='.$roomId.'&saved=1');
 }
 if($action==='save_luminaires'){
  $fp=fp_latest($pdo,$roomId);if(!$fp)throw new Exception('Nejdříve nahraj půdorys.');$lums=json_decode($_POST['luminaires_json']??'[]',true);if(!is_array($lums))$lums=array();$pdo->prepare("UPDATE mp_floorplans SET luminaires_json=?,show_luminaires=? WHERE id=?")->execute(array(json_encode($lums),!empty($_POST['show_luminaires'])?1:0,$fp['id']));redirect_to('room-measurement.php?room_id='.$roomId.'&lumsaved=1');
 }
}catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}

$fp=fp_latest($pdo,$roomId);$ms=$pdo->prepare("SELECT * FROM mp_measurements WHERE room_id=? ORDER BY point_number");$ms->execute(array($roomId));$measurements=$ms->fetchAll();
$points=array();foreach($measurements as $m)$points[]=array('x'=>$m['x_norm']===null?0.5:(float)$m['x_norm'],'y'=>$m['y_norm']===null?0.5:(float)$m['y_norm'],'lux'=>$m['illuminance']);
$poly=$fp?json_decode($fp['area_polygon_json']??'[]',true):array();if(!is_array($poly))$poly=array();$luminaires=$fp?json_decode($fp['luminaires_json']??'[]',true):array();if(!is_array($luminaires))$luminaires=array();
$kd=correction_kd_for_room($room);$useCorrection=!empty($room['use_correction']);

app_header('Měření · '.$room['name']);
?>
<div class="card"><h1><?php echo h($room['name']);?></h1><p><?php echo h($room['customer_name'].' · '.$room['job_name'].' · '.$room['report_number']);?></p><div class="actions"><a class="btn secondary" href="report.php?id=<?php echo $room['report_id'];?>">← Protokol</a><a class="btn secondary" href="measurements.php?room_id=<?php echo $roomId;?>">Tabulka hodnot</a></div></div>
<?php if($error):?><div class="notice error"><?php echo h($error);?></div><?php endif;?>
<?php if(isset($_GET['uploaded'])):?><div class="notice">Půdorys byl nahrán. Teď nastav měřítko.</div><?php endif;?><?php if(isset($_GET['imported'])):?><div class="notice">Importováno <?php echo (int)$_GET['imported'];?> naměřených hodnot z Excelu.</div><?php if(!empty($_SESSION['xlsx_import_info'])):?><div class="notice warning"><?php echo h($_SESSION['xlsx_import_info']); unset($_SESSION['xlsx_import_info']);?></div><?php endif;?><?php endif;?><?php if(isset($_GET['saved'])):?><div class="notice">Půdorys a měřicí body byly uloženy.</div><?php endif;?><?php if(isset($_GET['lumsaved'])):?><div class="notice">Svítidla byla uložena.</div><?php endif;?>

<?php if(!$fp):?>
<div class="card"><h2>1. Nahrát půdorys</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo h(csrf_token());?>"><input type="hidden" name="room_id" value="<?php echo $roomId;?>"><input type="hidden" name="action" value="upload"><input type="file" name="floorplan" accept="image/jpeg,image/png" required><button class="btn" type="submit">Nahrát půdorys</button></form></div>
<?php else:?>
<style>
.plan-layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:16px;align-items:start}.plan-card{overflow:auto}.plan-wrap{position:relative;display:inline-block;max-width:100%;user-select:none}.plan-wrap img{display:block;max-width:100%;height:auto}.overlay{position:absolute;inset:0;cursor:crosshair}.toolpanel{position:sticky;top:12px;max-height:calc(100vh - 24px);overflow:auto}.toolblock{border:1px solid #dbe3ea;border-radius:12px;background:#fff;margin:0 0 10px}.toolblock summary{cursor:pointer;font-weight:700;padding:10px 12px;list-style:none}.toolblock summary::-webkit-details-marker{display:none}.toolblock summary:after{content:'+';float:right}.toolblock[open] summary:after{content:'−'}.tool-content{padding:0 12px 12px}.compact-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.compact-grid label{margin:0}.compact-grid input,.compact-grid select{padding:7px 8px}.compact-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}.compact-actions .btn{padding:7px 10px}.mini-note{font-size:.82rem;color:#64748b;margin:5px 0}.point{position:absolute;width:12px;height:12px;border-radius:50%;border:2px solid #dc2626;background:#fff;transform:translate(-50%,-50%);cursor:move;z-index:8}.point span{position:absolute;left:9px;top:-13px;white-space:nowrap;color:#dc2626;font-weight:700;font-size:12px;text-shadow:0 1px 2px white}.scale-dot,.area-dot,.poly-dot{position:absolute;width:12px;height:12px;border-radius:50%;transform:translate(-50%,-50%);z-index:7}.scale-dot{background:#2563eb}.area-dot{background:#0f766e}.poly-dot{background:#0f766e;border:2px solid white}.luminaire{position:absolute;transform:translate(-50%,-50%) rotate(var(--rot));z-index:6;cursor:move}.luminaire.linear{width:30px;height:7px;background:#f59e0b;border:1px solid #92400e}.luminaire.rect{width:22px;height:14px;background:#fde68a;border:1px solid #92400e}.luminaire.circle{width:16px;height:16px;border-radius:50%;background:#fde68a;border:1px solid #92400e}.luminaire.selected{outline:3px solid rgba(37,99,235,.65)}.poly-svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:4}.poly-svg polygon{fill:rgba(13,148,136,.16);stroke:#0f766e;stroke-width:2;stroke-dasharray:7 5}.area-rect{position:absolute;border:2px dashed #0f766e;background:rgba(13,148,136,.08);z-index:3;pointer-events:none}.scale-line{position:absolute;height:2px;background:#2563eb;transform-origin:0 50%;z-index:5;pointer-events:none}.toolbar-status{font-size:.85rem;padding:7px 9px;background:#f8fafc;border-radius:8px;margin-bottom:8px}.rowcol{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.rowcol input{padding:7px}.lum-list{max-height:150px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;margin-top:8px}.lum-list button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:6px 8px}.lum-list button:hover,.lum-list button.active{background:#eff6ff}@media(max-width:1050px){.plan-layout{grid-template-columns:1fr}.toolpanel{position:static;max-height:none}}
</style>
<div class="plan-layout">
 <div class="card plan-card"><div id="planWrap" class="plan-wrap"><img id="planImg" src="floorplan-image.php?id=<?php echo (int)$fp['id'];?>" alt="Půdorys"><div id="overlay" class="overlay"></div><svg id="polySvg" class="poly-svg"><polygon id="polyShape" points=""></polygon></svg><div id="areaRect" class="area-rect"></div><div id="scaleLine" class="scale-line"></div></div></div>
 <div class="toolpanel">
  <div class="toolbar-status" id="status">Režim: výběr</div>
  <details class="toolblock" open><summary>1. Měřítko</summary><div class="tool-content"><p class="mini-note">Klikni na dva známé body a zadej jejich skutečnou vzdálenost.</p><div class="compact-actions"><button class="btn secondary" type="button" id="modeScale">Vybrat 2 body</button></div><label>Skutečná vzdálenost [m]<input id="scaleM" type="number" min="0.01" step="0.01" value="<?php echo h($fp['scale_real_m']??'');?>"></label></div></details>
  <details class="toolblock" open><summary>2. Měřená oblast</summary><div class="tool-content"><div class="compact-actions"><button class="btn secondary" type="button" id="modeRect">Obdélník</button><button class="btn secondary" type="button" id="modePoly">Polygon</button><button class="btn secondary" type="button" id="finishPoly">Dokončit polygon</button></div><p class="mini-note">Polygon: klikej po obvodu, potom „Dokončit polygon“.</p></div></details>
  <details class="toolblock" open><summary>3. Síť měřicích bodů</summary><div class="tool-content"><div class="compact-grid"><label>Řádky<input id="gridRows" type="number" min="1" max="30" value="3"></label><label>Sloupce<input id="gridCols" type="number" min="1" max="30" value="4"></label><label>Odsazení X [m]<input id="gridOffX" type="number" min="0" step="0.05" value="0.5"></label><label>Odsazení Y [m]<input id="gridOffY" type="number" min="0" step="0.05" value="0.5"></label></div><div class="compact-actions"><button class="btn" type="button" id="generateGrid">Vygenerovat síť</button><button class="btn secondary" type="button" id="clearPoints">Smazat body</button></div></div></details>
  <details class="toolblock" open><summary>4. Uložit body</summary><div class="tool-content"><form method="post" id="saveForm"><input type="hidden" name="csrf" value="<?php echo h(csrf_token());?>"><input type="hidden" name="room_id" value="<?php echo $roomId;?>"><input type="hidden" name="action" value="save_editor"><input type="hidden" name="scale_x1" id="sx1"><input type="hidden" name="scale_y1" id="sy1"><input type="hidden" name="scale_x2" id="sx2"><input type="hidden" name="scale_y2" id="sy2"><input type="hidden" name="scale_real_m" id="scaleReal"><input type="hidden" name="area_mode" id="areaMode"><input type="hidden" name="area_x1" id="ax1"><input type="hidden" name="area_y1" id="ay1"><input type="hidden" name="area_x2" id="ax2"><input type="hidden" name="area_y2" id="ay2"><input type="hidden" name="area_polygon_json" id="polyJson"><input type="hidden" name="points_json" id="pointsJson"><input type="hidden" name="show_luminaires" value="<?php echo !empty($fp['show_luminaires'])?1:0;?>"><button class="btn" type="submit">Uložit půdorys a body</button></form></div></details>
  <details class="toolblock tool-accordion"><summary>4b. Import hodnot z Excelu</summary><div class="tool-content"><p class="muted">Nejdříve vytvoř měřicí síť a klikni na „Uložit půdorys a body“. Excel obsahuje pouze sloupce Bod a Naměřeno [lx]. Hodnoty se přiřadí k existujícím bodům M1, M2, M3…; jejich poloha se nemění.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo h(csrf_token());?>"><input type="hidden" name="room_id" value="<?php echo $roomId;?>"><input type="hidden" name="action" value="import_xlsx"><input type="file" name="xlsx_points" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><div class="compact-actions"><button class="btn" type="submit">Importovat hodnoty XLSX</button><a class="btn secondary" href="download-xlsx-template.php">Stáhnout vzorový Excel</a></div></form></div></details>
  <details class="toolblock"><summary>5. Svítidla</summary><div class="tool-content"><div class="compact-grid"><label>Typ<select id="lumType"><option value="linear">Lineární</option><option value="rect">Obdélníkové</option><option value="circle">Kruhové</option></select></label><label>Otočení [°]<input id="lumRot" type="number" step="5" value="0"></label><label>Řádky<input id="lumRows" type="number" min="1" max="20" value="3"></label><label>Sloupce<input id="lumCols" type="number" min="1" max="20" value="4"></label></div><div class="compact-actions"><button class="btn secondary" type="button" id="genLum">Vygenerovat</button><button class="btn secondary" type="button" id="addLum">+ 1 svítidlo</button><button class="btn secondary" type="button" id="delLum">Smazat vybrané</button></div><div class="compact-actions"><button class="btn secondary" type="button" data-move="row">Posun řádku</button><button class="btn secondary" type="button" data-move="col">Posun sloupce</button><button class="btn secondary" type="button" data-move="all">Posun celé sítě</button></div><div class="compact-grid"><label>ΔX [%]<input id="moveX" type="number" step="0.5" value="1"></label><label>ΔY [%]<input id="moveY" type="number" step="0.5" value="0"></label></div><div class="compact-actions"><button class="btn secondary" type="button" id="applyMove">Použít posun</button><button class="btn secondary" type="button" id="rotateSelected">Otočit vybrané</button></div><div id="lumList" class="lum-list"></div><form method="post" id="lumForm"><input type="hidden" name="csrf" value="<?php echo h(csrf_token());?>"><input type="hidden" name="room_id" value="<?php echo $roomId;?>"><input type="hidden" name="action" value="save_luminaires"><input type="hidden" name="luminaires_json" id="luminairesJson"><label><input type="checkbox" name="show_luminaires" value="1" <?php echo !empty($fp['show_luminaires'])?'checked':'';?>> Zobrazit svítidla ve finálním půdorysu</label><button class="btn" type="submit">Uložit svítidla</button></form></div></details>
 </div>
</div>
<script>
const overlay=document.getElementById('overlay'),wrap=document.getElementById('planWrap'),img=document.getElementById('planImg'),statusEl=document.getElementById('status');
let mode='select',scalePts=[<?php if($fp['scale_x1']!==null)echo '{x:'.(float)$fp['scale_x1'].',y:'.(float)$fp['scale_y1'].'},{x:'.(float)$fp['scale_x2'].',y:'.(float)$fp['scale_y2'].'}';?>],areaMode='<?php echo h($fp['area_mode']??'rect');?>',rectPts=[<?php if($fp['area_x1']!==null)echo '{x:'.(float)$fp['area_x1'].',y:'.(float)$fp['area_y1'].'},{x:'.(float)$fp['area_x2'].',y:'.(float)$fp['area_y2'].'}';?>],poly=<?php echo json_encode($poly);?>,points=<?php echo json_encode($points);?>,lums=<?php echo json_encode($luminaires);?>,selectedLum=null,moveScope='all';
function pos(e){const r=overlay.getBoundingClientRect();return{x:Math.max(0,Math.min(1,(e.clientX-r.left)/r.width)),y:Math.max(0,Math.min(1,(e.clientY-r.top)/r.height))}}
function pct(v){return (v*100)+'%'} function dot(cls,p){const d=document.createElement('div');d.className=cls;d.style.left=pct(p.x);d.style.top=pct(p.y);overlay.appendChild(d)}
function render(){overlay.innerHTML='';scalePts.forEach(p=>dot('scale-dot',p));if(areaMode==='rect')rectPts.forEach(p=>dot('area-dot',p));else poly.forEach(p=>dot('poly-dot',{x:p[0],y:p[1]}));renderShapes();points.forEach((p,i)=>{const d=document.createElement('div');d.className='point';d.style.left=pct(p.x);d.style.top=pct(p.y);const raw=p.lux===null||p.lux===undefined||p.lux===''?'':Number(p.lux);let shown=raw;if(raw!==''&&<?php echo $useCorrection?'true':'false';?>){let k=raw<=20?.949:raw<=200?.946:raw<=2000?.951:raw<=20000?.950:.951;shown=Math.round(raw*k*<?php echo json_encode($kd);?>)}d.innerHTML='<span>M'+(i+1)+(shown!==''?' · '+shown+' lx':'')+'</span>';drag(d,p,()=>render());overlay.appendChild(d)});lums.forEach((l,i)=>{const d=document.createElement('div');d.className='luminaire '+(l.type||'linear')+(selectedLum===i?' selected':'');d.style.left=pct(l.x);d.style.top=pct(l.y);d.style.setProperty('--rot',(l.rot||0)+'deg');d.onclick=e=>{e.stopPropagation();selectedLum=i;render()};drag(d,l,()=>render());overlay.appendChild(d)});renderLumList()}
function renderShapes(){const sl=document.getElementById('scaleLine');if(scalePts.length===2){let dx=(scalePts[1].x-scalePts[0].x)*wrap.clientWidth,dy=(scalePts[1].y-scalePts[0].y)*wrap.clientHeight;sl.style.display='block';sl.style.left=pct(scalePts[0].x);sl.style.top=pct(scalePts[0].y);sl.style.width=Math.hypot(dx,dy)+'px';sl.style.transform='rotate('+Math.atan2(dy,dx)+'rad)'}else sl.style.display='none';const ar=document.getElementById('areaRect');if(areaMode==='rect'&&rectPts.length===2){let x=Math.min(rectPts[0].x,rectPts[1].x),y=Math.min(rectPts[0].y,rectPts[1].y),w=Math.abs(rectPts[1].x-rectPts[0].x),h=Math.abs(rectPts[1].y-rectPts[0].y);ar.style.display='block';ar.style.left=pct(x);ar.style.top=pct(y);ar.style.width=pct(w);ar.style.height=pct(h)}else ar.style.display='none';document.getElementById('polyShape').setAttribute('points',areaMode==='poly'?poly.map(p=>(p[0]*wrap.clientWidth)+','+(p[1]*wrap.clientHeight)).join(' '):'')}
function drag(el,obj,cb){el.onpointerdown=e=>{if(mode!=='select')return;e.stopPropagation();el.setPointerCapture(e.pointerId);el.onpointermove=ev=>{let p=pos(ev);obj.x=p.x;obj.y=p.y;cb()};el.onpointerup=()=>{el.onpointermove=null}}}
overlay.onclick=e=>{let p=pos(e);if(mode==='scale'){if(scalePts.length>=2)scalePts=[];scalePts.push(p);render()}else if(mode==='rect'){areaMode='rect';if(rectPts.length>=2)rectPts=[];rectPts.push(p);render()}else if(mode==='poly'){areaMode='poly';poly.push([p.x,p.y]);render()}};
function setMode(m,label){mode=m;statusEl.textContent='Režim: '+label}document.getElementById('modeScale').onclick=()=>setMode('scale','měřítko');document.getElementById('modeRect').onclick=()=>setMode('rect','obdélník');document.getElementById('modePoly').onclick=()=>setMode('poly','polygon');document.getElementById('finishPoly').onclick=()=>{if(poly.length<3)return alert('Polygon musí mít nejméně 3 body.');areaMode='poly';setMode('select','výběr');render()};
function metersPerNorm(){if(scalePts.length<2)return null;let dx=(scalePts[1].x-scalePts[0].x)*img.naturalWidth,dy=(scalePts[1].y-scalePts[0].y)*img.naturalHeight,px=Math.hypot(dx,dy),m=parseFloat(document.getElementById('scaleM').value);if(!px||!m)return null;return{mx:m/(px/img.naturalWidth),my:m/(px/img.naturalHeight)}}
function inside(x,y){if(areaMode==='rect'&&rectPts.length===2){let minx=Math.min(rectPts[0].x,rectPts[1].x),maxx=Math.max(rectPts[0].x,rectPts[1].x),miny=Math.min(rectPts[0].y,rectPts[1].y),maxy=Math.max(rectPts[0].y,rectPts[1].y);return x>=minx&&x<=maxx&&y>=miny&&y<=maxy}if(areaMode==='poly'&&poly.length>=3){let c=false;for(let i=0,j=poly.length-1;i<poly.length;j=i++){let xi=poly[i][0],yi=poly[i][1],xj=poly[j][0],yj=poly[j][1];if(((yi>y)!=(yj>y))&&(x<(xj-xi)*(y-yi)/(yj-yi)+xi))c=!c}return c}return false}
function bounds(){if(areaMode==='rect'&&rectPts.length===2)return{minx:Math.min(rectPts[0].x,rectPts[1].x),maxx:Math.max(rectPts[0].x,rectPts[1].x),miny:Math.min(rectPts[0].y,rectPts[1].y),maxy:Math.max(rectPts[0].y,rectPts[1].y)};if(poly.length>=3){let xs=poly.map(p=>p[0]),ys=poly.map(p=>p[1]);return{minx:Math.min(...xs),maxx:Math.max(...xs),miny:Math.min(...ys),maxy:Math.max(...ys)}}return null}
document.getElementById('generateGrid').onclick=()=>{let b=bounds(),mpn=metersPerNorm();if(!b||!mpn)return alert('Nejdříve nastav měřítko a měřenou oblast.');let rows=+document.getElementById('gridRows').value,cols=+document.getElementById('gridCols').value,ox=+document.getElementById('gridOffX').value/mpn.mx,oy=+document.getElementById('gridOffY').value/mpn.my;let minx=b.minx+ox,maxx=b.maxx-ox,miny=b.miny+oy,maxy=b.maxy-oy;if(minx>maxx||miny>maxy)return alert('Odsazení je větší než měřená oblast.');points=[];for(let r=0;r<rows;r++)for(let c=0;c<cols;c++){let x=cols===1?(minx+maxx)/2:minx+c*(maxx-minx)/(cols-1),y=rows===1?(miny+maxy)/2:miny+r*(maxy-miny)/(rows-1);if(inside(x,y))points.push({x,y,lux:''})}render()};document.getElementById('clearPoints').onclick=()=>{if(confirm('Smazat všechny měřicí body?')){points=[];render()}};
document.getElementById('saveForm').onsubmit=()=>{if(scalePts.length!==2){alert('Nastav měřítko.');return false}if(areaMode==='rect'&&rectPts.length!==2){alert('Nastav oblast.');return false}if(areaMode==='poly'&&poly.length<3){alert('Dokonči polygon.');return false}document.getElementById('sx1').value=scalePts[0].x;document.getElementById('sy1').value=scalePts[0].y;document.getElementById('sx2').value=scalePts[1].x;document.getElementById('sy2').value=scalePts[1].y;document.getElementById('scaleReal').value=document.getElementById('scaleM').value;document.getElementById('areaMode').value=areaMode;if(rectPts.length===2){document.getElementById('ax1').value=rectPts[0].x;document.getElementById('ay1').value=rectPts[0].y;document.getElementById('ax2').value=rectPts[1].x;document.getElementById('ay2').value=rectPts[1].y}document.getElementById('polyJson').value=JSON.stringify(poly);document.getElementById('pointsJson').value=JSON.stringify(points);return true};
function renderLumList(){let el=document.getElementById('lumList');el.innerHTML='';lums.forEach((l,i)=>{let b=document.createElement('button');b.type='button';b.className=selectedLum===i?'active':'';b.textContent='S'+(i+1)+' · '+(l.type||'linear')+' · '+(l.rot||0)+'°';b.onclick=()=>{selectedLum=i;render()};el.appendChild(b)})}
document.getElementById('genLum').onclick=()=>{let b=bounds();if(!b)return alert('Nejdříve nastav měřenou oblast.');let rows=+document.getElementById('lumRows').value,cols=+document.getElementById('lumCols').value,type=document.getElementById('lumType').value,rot=+document.getElementById('lumRot').value;lums=[];for(let r=0;r<rows;r++)for(let c=0;c<cols;c++){let x=b.minx+(c+1)*(b.maxx-b.minx)/(cols+1),y=b.miny+(r+1)*(b.maxy-b.miny)/(rows+1);if(inside(x,y))lums.push({x,y,type,rot,row:r,col:c})}selectedLum=null;render()};document.getElementById('addLum').onclick=()=>{let b=bounds()||{minx:.4,maxx:.6,miny:.4,maxy:.6};lums.push({x:(b.minx+b.maxx)/2,y:(b.miny+b.maxy)/2,type:document.getElementById('lumType').value,rot:+document.getElementById('lumRot').value,row:0,col:0});selectedLum=lums.length-1;render()};document.getElementById('delLum').onclick=()=>{if(selectedLum===null)return;lums.splice(selectedLum,1);selectedLum=null;render()};document.querySelectorAll('[data-move]').forEach(b=>b.onclick=()=>{moveScope=b.dataset.move;statusEl.textContent='Posun svítidel: '+moveScope});document.getElementById('applyMove').onclick=()=>{if(!lums.length)return;let dx=+document.getElementById('moveX').value/100,dy=+document.getElementById('moveY').value/100;if(moveScope==='all')lums.forEach(l=>{l.x+=dx;l.y+=dy});else if(selectedLum!==null){let s=lums[selectedLum];lums.forEach(l=>{if(moveScope==='row'&&l.row===s.row||moveScope==='col'&&l.col===s.col){l.x+=dx;l.y+=dy}})}render()};document.getElementById('rotateSelected').onclick=()=>{let rot=+document.getElementById('lumRot').value;if(selectedLum!==null)lums[selectedLum].rot=rot;else lums.forEach(l=>l.rot=rot);render()};document.getElementById('lumForm').onsubmit=()=>{document.getElementById('luminairesJson').value=JSON.stringify(lums);return true};
img.onload=render;window.onresize=render;render();
</script>
<?php endif;?>
<?php app_footer();?>
