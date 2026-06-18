<?php
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../includes/config.php';

$opts = getopt('', ['customers:', 'complaints:', 'orders:', 'apply', 'dry-run']);
$customersFile = $opts['customers'] ?? '/home/augi/.openclaw/workspace/zakaznici_applefix.csv';
$complaintsFile = $opts['complaints'] ?? '/home/augi/.openclaw/workspace/reklamace import.csv';
$ordersFile = $opts['orders'] ?? '/home/augi/.openclaw/workspace/zakazky_applefix - oprava.csv';
$apply = isset($opts['apply']);
$dry = !$apply;

function n($v){ $v=trim((string)$v); $v=preg_replace('/\s+/u',' ',$v); return $v; }
function nk($v){ $v=mb_strtolower(n($v),'UTF-8'); $v=strtr($v,['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z']); $v=preg_replace('/[^a-z0-9]+/u',' ',$v); return trim($v); }
function pd($v){ $v=n($v); if($v==='')return ''; if(preg_match('/^[0-9]+([,.][0-9]+)?E[+-]?[0-9]+$/i',$v)){ $v=(string)sprintf('%.0f',(float)str_replace(',','.',$v)); } $d=preg_replace('/\D+/','',$v); if(str_starts_with($d,'00'))$d=substr($d,2); if(strlen($d)===9)$d='420'.$d; return $d; }
function pstore($v){ $d=pd($v); return $d!==''?'+'.$d:null; }
function splitName($full){ $full=n($full); if($full==='') return ['Neznámý','-']; $parts=preg_split('/\s+/u',$full); if(count($parts)===1) return [$parts[0],'-']; $last=array_pop($parts); return [implode(' ',$parts),$last]; }

function readCsv($file){
  $h=fopen($file,'rb'); if(!$h) throw new RuntimeException("Cannot open $file");
  $first=fgets($h); rewind($h); $del=(substr_count($first,';')>=substr_count($first,','))?';':',';
  $head=fgetcsv($h,0,$del); $rows=[]; while(($r=fgetcsv($h,0,$del))!==false){ if($r===[null])continue; $rows[]=$r; } fclose($h); return [$head,$rows,$del];
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

[$ch,$crows]=readCsv($customersFile);
$idx=[]; foreach($ch as $i=>$v){ $idx[nk($v)]=$i; }
$inserted=0;$updated=0;

if($apply){ $pdo->beginTransaction(); }

$selByPhone=$pdo->prepare("SELECT id FROM customers WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(', '') LIKE ? LIMIT 2");
$selByEmail=$pdo->prepare("SELECT id FROM customers WHERE LOWER(email)=LOWER(?) LIMIT 2");
$selByName=$pdo->prepare("SELECT id FROM customers WHERE LOWER(CONCAT(first_name,' ',last_name))=LOWER(?) LIMIT 2");
$ins=$pdo->prepare("INSERT INTO customers (customer_type,first_name,last_name,company,phone,email) VALUES (?,?,?,?,?,?)");
$upd=$pdo->prepare("UPDATE customers SET customer_type=?,first_name=?,last_name=?,company=?,phone=?,email=? WHERE id=?");

foreach($crows as $r){
  $fullname=n($r[$idx['jmeno_a_prijmeni'] ?? 0] ?? '');
  $company=n($r[$idx['firma'] ?? 1] ?? '');
  $phoneRaw=n($r[$idx['telefonni_cislo'] ?? 2] ?? '');
  $email=n($r[$idx['e_mailova_adresa'] ?? 3] ?? '');
  [$fn,$ln]=splitName($fullname);
  $ctype=$company!==''?'company':'private';
  $phone=pstore($phoneRaw);
  $id=null;
  $d=pd($phoneRaw);
  if($d!==''){
    $selByPhone->execute(['%'.$d]); $ids=$selByPhone->fetchAll(PDO::FETCH_COLUMN); if(count($ids)===1)$id=(int)$ids[0];
  }
  if(!$id && $email!==''){
    $selByEmail->execute([$email]); $ids=$selByEmail->fetchAll(PDO::FETCH_COLUMN); if(count($ids)===1)$id=(int)$ids[0];
  }
  if(!$id && $fullname!==''){
    $selByName->execute([$fullname]); $ids=$selByName->fetchAll(PDO::FETCH_COLUMN); if(count($ids)===1)$id=(int)$ids[0];
  }

  if($id){
    if($apply) $upd->execute([$ctype,$fn,$ln,$company?:null,$phone,$email?:null,$id]);
    $updated++;
  } else {
    if($apply) $ins->execute([$ctype,$fn,$ln,$company?:null,$phone,$email?:null]);
    $inserted++;
  }
}

// Build customer index for pairing
$customers=$pdo->query("SELECT id,first_name,last_name,phone FROM customers")->fetchAll(PDO::FETCH_ASSOC);
$byPhone=[];$byName=[];
foreach($customers as $c){ $d=pd($c['phone']??''); if($d!=='') $byPhone[$d][]=(int)$c['id']; $k=nk(($c['first_name']??'').' '.($c['last_name']??'')); if($k!=='') $byName[$k][]=(int)$c['id']; }

// Complaints import
[$hh,$rows]=readCsv($complaintsFile); $hidx=[]; foreach($hh as $i=>$v){ $hidx[nk($v)]=$i; }
$checkComp=$pdo->prepare("SELECT id FROM complaints WHERE complaint_code=? LIMIT 1");
$insComp=$pdo->prepare("INSERT INTO complaints (complaint_code,customer_id,phone,device,serial_number,complaint_reason,complaint_status) VALUES (?,?,?,?,?,?,?)");
$updComp=$pdo->prepare("UPDATE complaints SET customer_id=?,phone=?,device=?,serial_number=?,complaint_reason=?,complaint_status=? WHERE id=?");
$compI=0;$compU=0;

foreach($rows as $r){
  $code=n($r[$hidx['kod']??0]??''); if($code==='') continue;
  $name=n($r[$hidx['zakaznik']??1]??'');
  $phoneRaw=n($r[$hidx['telefon']??2]??'');
  $device=n($r[$hidx['zarizeni']??3]??'');
  $sn=n($r[$hidx['imei_sn']??4]??'');
  if(preg_match('/^[0-9]+([,.][0-9]+)?E[+-]?[0-9]+$/i',$sn)) $sn=(string)sprintf('%.0f',(float)str_replace(',','.',$sn));
  $reason=n($r[$hidx['duvod_reklamace']??5]??'');
  $status=n($r[$hidx['stav_reklamace']??6]??'');
  $d=pd($phoneRaw);
  $cid=null;
  if($d!=='' && isset($byPhone[$d]) && count($byPhone[$d])===1) $cid=$byPhone[$d][0];
  if(!$cid){ $k=nk($name); if($k!=='' && isset($byName[$k]) && count($byName[$k])===1) $cid=$byName[$k][0]; }
  if(!$cid){
    [$fn,$ln]=splitName($name);
    if($apply){ $ins->execute(['private',$fn,$ln,null,pstore($phoneRaw),null]); $cid=(int)$pdo->lastInsertId(); }
    else { $cid=0; }
  }
  $checkComp->execute([$code]); $id=$checkComp->fetchColumn();
  if($id){ if($apply)$updComp->execute([$cid,pstore($phoneRaw),$device,$sn?:null,$reason?:null,$status?:null,$id]); $compU++; }
  else { if($apply)$insComp->execute([$code,$cid,pstore($phoneRaw),$device,$sn?:null,$reason?:null,$status?:null]); $compI++; }
}

// Re-link orders by CSV (order_code -> resolved customer by phone/name)
[$oh,$orows]=readCsv($ordersFile); $oidx=[]; foreach($oh as $i=>$v){ $oidx[nk($v)]=$i; }
$updOrder=$pdo->prepare("UPDATE orders SET customer_id=? WHERE order_code=?");
$rel=0;
foreach($orows as $r){
  $code=n($r[$oidx['kod']??0]??''); if($code==='') continue;
  $name=n($r[$oidx['zakaznik']??1]??'');
  $phoneRaw=n($r[$oidx['telefon']??2]??'');
  $cid=null; $d=pd($phoneRaw);
  if($d!=='' && isset($byPhone[$d]) && count($byPhone[$d])===1) $cid=$byPhone[$d][0];
  if(!$cid){ $k=nk($name); if($k!=='' && isset($byName[$k]) && count($byName[$k])===1) $cid=$byName[$k][0]; }
  if($cid){ if($apply)$updOrder->execute([$cid,$code]); $rel++; }
}

if($apply){ $pdo->commit(); }

$cntC=$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$cntO=$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$cntR=$pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
$linked=$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_id IS NOT NULL")->fetchColumn();

echo ($apply?"APPLY":"DRY")."\n";
echo "customers_updated=$updated customers_inserted=$inserted\n";
echo "complaints_inserted=$compI complaints_updated=$compU\n";
echo "orders_relinked=$rel\n";
echo "counts customers=$cntC orders=$cntO complaints=$cntR linked_orders=$linked\n";
