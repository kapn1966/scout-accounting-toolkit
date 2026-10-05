<?php
/**
 * Plugin Name: 403TO Transaction Inbox
 * Description: Compact unified transaction register for OFX bank transactions, supporting evidence, categories, splits, and bulk review.
 * Version: 0.7.1
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;
final class TO403_Transaction_Inbox {
 const V='0.7.1',CAP='review_403to_payments',ACT='to403_transaction_inbox_action',NONCE='to403_transaction_inbox',AJAX_ACCEPT='to403_transaction_inbox_accept',AJAX_ACTION='to403_transaction_inbox_ajax',UNIFORM='Program income - uniforms';
 private static $i; private $pdo; private $accountDirection='';
 static function instance(){return self::$i??=new self();}
 private function __construct(){foreach(['403to_transaction_inbox','403to_payment_review','403to_inbox_review'] as $s)add_shortcode($s,[$this,'render']);add_action('admin_post_'.self::ACT,[$this,'handle']);add_action('wp_ajax_'.self::AJAX_ACCEPT,[$this,'ajaxAccept']);add_action('wp_ajax_'.self::AJAX_ACTION,[$this,'ajaxAction']);add_action('wp_enqueue_scripts',[$this,'assets']);}
 static function activate(){add_role('403to_treasurer','403TO Treasurer',['read'=>true,self::CAP=>true]);foreach(['403to_treasurer','administrator'] as $n){$r=get_role($n);if($r)$r->add_cap(self::CAP);}}
 function assets(){
  if(!is_user_logged_in()||!current_user_can(self::CAP))return;
  wp_enqueue_style('to403-transaction-inbox',plugins_url('assets/register.css',__FILE__),[],@filemtime(__DIR__.'/assets/register.css')?:self::V);
  wp_enqueue_script('to403-transaction-inbox',plugins_url('assets/register.js',__FILE__),[],@filemtime(__DIR__.'/assets/register.js')?:self::V,true);
  wp_localize_script('to403-transaction-inbox','to403TransactionInbox',['ajaxUrl'=>admin_url('admin-ajax.php'),'acceptAction'=>self::AJAX_ACCEPT,'actionAction'=>self::AJAX_ACTION]);
 }
 private function db(){if($this->pdo)return $this->pdo;foreach(['TO403_PAYMENTS_DB_HOST','TO403_PAYMENTS_DB_NAME','TO403_PAYMENTS_DB_USER','TO403_PAYMENTS_DB_PASSWORD'] as $c)if(!defined($c)||constant($c)==='')throw new RuntimeException('Missing wp-config.php constant: '.$c);$this->pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',TO403_PAYMENTS_DB_HOST,TO403_PAYMENTS_DB_NAME),TO403_PAYMENTS_DB_USER,TO403_PAYMENTS_DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);return $this->pdo;}
 private function access(){if(!is_user_logged_in()||!current_user_can(self::CAP))wp_die('You do not have permission to review transactions.','403TO Transaction Register',['response'=>403]);}
 private function accounts($direction=''){ $defaults=$direction==='expense'?['Admin & operations','Bank Service Charges','Boating - all except keelboat expenses','Direct Program Subsidy','facility or hall rental','Group activities and events','Keelboat related equipment, maintenance and storage','Printing and Reproduction','Program equipment and misc program expense','Rent Expense','Storage costs','Training and certification','Travel Expense - non-section','Beavers - Camp (not food)','Beavers - Camp food','Beavers - program (not camp)','Beavers - transportation','Cubs - Camp (not food)','Cubs - Camp food','Cubs - program (not camp)','Cubs - transportation','Fundraising Costs','National & International Scouting Events','Sea Scouts - Camp (not food)','Sea Scouts - Camp food','Sea Scouts - program (not camp)','Sea Scouts - transportation','Sea Venturers - Camp (not food)','Sea Venturers - Camp food','Sea Venturers - program','Sea Venturers - Transportation','Uniform Items - all sections']:($direction==='income'?['Direct Public Support – Business Contributions','Direct Public Support – Corporate Contributions','Direct Public Support – Individual Contributions','Fundraising','National & International Scouting events','Program Income - Beavers','Program Income - Cubs','Program Income - Sea Scouts','Program Income - Sea Venturers','Program Income - uniforms','Program Income - unspecified','Program Income – Membership Dues']:array_merge(['Direct Public Support – Business Contributions','Direct Public Support – Corporate Contributions','Direct Public Support – Individual Contributions','Fundraising','National & International Scouting events','Program Income - Beavers','Program Income - Cubs','Program Income - Sea Scouts','Program Income - Sea Venturers','Program Income - uniforms','Program Income - unspecified','Program Income – Membership Dues'],['Admin & operations','Bank Service Charges','Boating - all except keelboat expenses','Direct Program Subsidy','facility or hall rental','Group activities and events','Keelboat related equipment, maintenance and storage','Printing and Reproduction','Program equipment and misc program expense','Rent Expense','Storage costs','Training and certification','Travel Expense - non-section','Beavers - Camp (not food)','Beavers - Camp food','Beavers - program (not camp)','Beavers - transportation','Cubs - Camp (not food)','Cubs - Camp food','Cubs - program (not camp)','Cubs - transportation','Fundraising Costs','National & International Scouting Events','Sea Scouts - Camp (not food)','Sea Scouts - Camp food','Sea Scouts - program (not camp)','Sea Scouts - transportation','Sea Venturers - Camp (not food)','Sea Venturers - Camp food','Sea Venturers - program','Sea Venturers - Transportation','Uniform Items - all sections']));$a=(array)apply_filters('to403_transaction_inbox_accounts',apply_filters('to403_payment_review_accounts',$defaults),$direction);$a=array_values(array_unique(array_filter(array_map('trim',$a))));natcasesort($a);return array_values($a);}
 private function amap(){return (array)apply_filters('to403_transaction_inbox_account_map',apply_filters('to403_payment_review_account_map',['Beavers'=>'Program Income - Beavers','Cubs'=>'Program Income - Cubs','Sea Scouts'=>'Program Income - Sea Scouts','Sea Venturers'=>'Program Income - Sea Venturers','Venturers'=>'Program Income - Sea Venturers']));}
 private function payload($r){$x=json_decode((string)($r['payload_json']??''),true);return is_array($x)?$x:[];}
 private function norm($v){$v=trim((string)$v);if(function_exists('iconv')){$x=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);if($x!==false)$v=$x;}return trim(preg_replace('/\s+/',' ',preg_replace('/[^a-z0-9]+/',' ',strtolower($v))));}
 private function cents($v){return (int)round(abs((float)$v)*100);}
 private function bank($id){$s=$this->db()->prepare("SELECT * FROM inbox_observations WHERE observation_id=? AND source_system IN ('ofx_operating','ofx_cg_funds','ofx_visa')");$s->execute([$id]);return $s->fetch()?:null;}
 private function accepted($id){$s=$this->db()->prepare("SELECT m.match_id,m.confidence,o.* FROM inbox_matches m JOIN inbox_observations o ON o.observation_id=CASE WHEN m.left_observation_id=? THEN m.right_observation_id ELSE m.left_observation_id END WHERE m.left_observation_id=? OR m.right_observation_id=? LIMIT 1");$s->execute([$id,$id,$id]);return $s->fetch()?:null;}
 private function candidates($b){
  if(!$b)return [];$amount=abs((float)$b['amount']);if($amount<=0)return [];$sources=[];
  if((float)$b['amount']>0&&$b['source_system']==='ofx_operating')$sources[]='interac_email';
  if((float)$b['amount']<0)$sources[]='gravity_forms';
  if(!$sources)return [];$marks=implode(',',array_fill(0,count($sources),'?'));
  $q=$this->db()->prepare("SELECT * FROM inbox_observations WHERE source_system IN ($marks) AND amount=? AND transaction_date BETWEEN DATE_SUB(?,INTERVAL 7 DAY) AND DATE_ADD(?,INTERVAL 7 DAY) ORDER BY transaction_date,observation_id");$q->execute(array_merge($sources,[$amount,$b['transaction_date'],$b['transaction_date']]));$out=[];
  foreach($q->fetchAll()as$r){$rp=$this->payload($r);$days=abs((strtotime($r['transaction_date'])-strtotime($b['transaction_date']))/86400);$score=max(0,100-$days*10);$exact=false;if($r['source_system']==='interac_email'){$bp=$this->payload($b);$hay=' '.$this->norm(($bp['name']??'').' '.($bp['memo']??'').' '.$b['description']).' ';$payer=$this->norm($rp['payer_name']??'');$exact=$payer!==''&&strpos($hay,' '.$payer.' ')!==false;if($exact)$score+=100;}$out[]=['r'=>$r,'p'=>$rp,'score'=>$score,'exact_name'=>$exact,'confidence'=>$days<=1?'HIGH':($days<=3?'MEDIUM':'LOW')];}
  usort($out,function($a,$b){return$b['score']<=>$a['score'];});return$out;
 }
 private function suggest($b,$e){
  if($e){$ep=$this->payload($e);$mapped=trim((string)($ep['export_account']??''));if($mapped!=='')return$mapped;}
  $bp=$this->payload($b);$ep=$e?$this->payload($e):[];
  $t=$this->norm(($bp['memo']??'').' '.$b['description'].' '.($e['description']??'').' '.($ep['payment_message']??'').' '.($ep['section']??'').' '.($ep['recipient']??'').' '.($ep['recipient_email']??'').' '.($ep['to_email']??'').' '.($ep['mailbox']??''));
  foreach(['hat','hats','shirt','shirts','uniform','uniforms','necker','neckers','scarf','scarves'] as $k)if(preg_match('/(^| )'.preg_quote($k,'/').'( |$)/',$t))return self::UNIFORM;
  $section=$this->norm($ep['section']??'');
  foreach($this->amap() as $label=>$account)if($section===$this->norm($label))return $account;
  foreach(['Beavers'=>'beaver','Cubs'=>'cub','Sea Scouts'=>'sea scout','Sea Venturers'=>'sea venturer','Venturers'=>'venturer'] as $label=>$needle)if(strpos(' '.$t.' ',' '.$needle.' ')!==false||strpos($t,$needle.'s')!==false)return $this->amap()[$label]??'';
  return '';
 }
 private function allocations($id){$s=$this->db()->prepare('SELECT * FROM inbox_allocations WHERE bank_observation_id=? ORDER BY line_number');$s->execute([$id]);return $s->fetchAll();}
 private function review($id){$s=$this->db()->prepare('SELECT * FROM inbox_reviews WHERE bank_observation_id=?');$s->execute([$id]);return $s->fetch()?:null;}
 private function rows(){$w="source_system IN ('ofx_operating','ofx_cg_funds','ofx_visa')";$a=[];$acct=sanitize_key(wp_unslash($_GET['account']??''));if(in_array($acct,['ofx_operating','ofx_cg_funds','ofx_visa'],true)){$w.=' AND source_system=?';$a[]=$acct;}$s=$this->db()->prepare("SELECT * FROM inbox_observations WHERE $w ORDER BY transaction_date DESC,observation_id DESC");$s->execute($a);return $s->fetchAll();}
 private function status($b,$a,$r){if(($r['review_status']??'')==='excluded')return'excluded';if(($r['review_status']??'')==='exported')return'exported';if(count($a)>1)return'split';if(count($a)===1&&$this->cents($a[0]['amount'])===$this->cents($b['amount']))return'ready';return'needs-category';}
 private function hidden($op){wp_nonce_field(self::NONCE);echo'<input type="hidden" name="action" value="'.self::ACT.'"><input type="hidden" name="operation" value="'.esc_attr($op).'"><input type="hidden" name="return_url" value="'.esc_url(remove_query_arg(['to403_notice','to403_message'])).'">';}
 private function redirect($t,$m){$u=esc_url_raw(wp_unslash($_POST['return_url']??home_url('/')));wp_safe_redirect(add_query_arg(['to403_notice'=>$t,'to403_message'=>rawurlencode($m)],$u));exit;}
 function handle(){$this->access();check_admin_referer(self::NONCE);try{$op=sanitize_key(wp_unslash($_POST['operation']??''));if($op==='save-category'){$this->saveCategory((int)$_POST['bank_id'],sanitize_text_field(wp_unslash($_POST['category']??'')));$this->redirect('success','Category saved.');}if($op==='save-split'){$this->saveSplit((int)$_POST['bank_id']);$this->redirect('success','Split saved.');}if($op==='accept'){$this->accept((int)$_POST['bank_id'],(int)$_POST['evidence_id']);$this->redirect('success','Match accepted.');}if($op==='bulk')$this->bulk();throw new RuntimeException('Unknown action.');}catch(Throwable $e){$this->redirect('error',$e->getMessage());}}
 function ajaxAccept(){
  try{
   $this->access();
   check_ajax_referer(self::NONCE,'nonce');
   $id=(int)($_POST['bank_id']??0);$eid=(int)($_POST['evidence_id']??0);
   $this->accept($id,$eid);
   $e=$this->accepted($id);$ep=$e?$this->payload($e):[];
   wp_send_json_success(['bank_id'=>$id,'evidence_id'=>$eid,'payer'=>$ep['payer_name']??'','description'=>$e['description']??'','section'=>$ep['section']??'','message'=>'Match accepted.']);
  }catch(Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],400);}
 }
 function ajaxAction(){
  try{
   $this->access();
   check_ajax_referer(self::NONCE,'nonce');
   $op=sanitize_key(wp_unslash($_POST['operation']??''));
   if($op==='save-category'){
    $id=(int)($_POST['bank_id']??0);$cat=sanitize_text_field(wp_unslash($_POST['category']??''));
    $this->saveCategory($id,$cat);
    wp_send_json_success(['operation'=>$op,'bank_id'=>$id,'category'=>$cat,'status'=>'ready','message'=>'Category saved.']);
   }
   if($op==='save-split'){
    $id=(int)($_POST['bank_id']??0);$this->saveSplit($id);$a=$this->allocations($id);
    wp_send_json_success(['operation'=>$op,'bank_id'=>$id,'line_count'=>count($a),'status'=>'ready','message'=>'Split saved.']);
   }
   if($op==='bulk'){
    $result=$this->bulkResult();
    wp_send_json_success(array_merge(['operation'=>$op,'message'=>sprintf('Accepted matches: %d. Saved categories: %d. Skipped: %d. Errors: %d.',$result['matched'],$result['categorized'],$result['skipped'],$result['errors'])],$result));
   }
   throw new RuntimeException('Unknown Ajax action.');
  }catch(Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],400);}
 }
 private function saveLines($id,$lines){$b=$this->bank($id);if(!$b)throw new RuntimeException('Transaction not found.');$sum=array_sum(array_column($lines,1));if(!$lines||$sum!==$this->cents($b['amount']))throw new RuntimeException('Category amount or split total must equal the transaction amount.');$d=$this->db();$d->beginTransaction();try{$d->prepare('DELETE FROM inbox_allocations WHERE bank_observation_id=?')->execute([$id]);$q=$d->prepare('INSERT INTO inbox_allocations(bank_observation_id,line_number,export_account,amount,allocation_note,created_by) VALUES(?,?,?,?,?,?)');foreach($lines as $i=>$l)$q->execute([$id,$i+1,$l[0],$l[1]/100,$l[2]?:null,get_current_user_id()]);$d->prepare("INSERT INTO inbox_reviews(bank_observation_id,review_status,reviewed_by) VALUES(?,'ready',?) ON DUPLICATE KEY UPDATE review_status='ready',reviewed_by=VALUES(reviewed_by),updated_at=CURRENT_TIMESTAMP")->execute([$id,get_current_user_id()]);$d->commit();}catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw$e;}}
 private function saveCategory($id,$cat){if(!in_array($cat,$this->accounts(),true))throw new RuntimeException('Choose a valid category.');$b=$this->bank($id);$this->saveLines($id,[[$cat,$this->cents($b['amount']),'']]);}
 private function saveSplit($id){$aa=(array)($_POST['split_account']??[]);$vv=(array)($_POST['split_amount']??[]);$nn=(array)($_POST['split_note']??[]);$l=[];foreach($aa as $i=>$a){$a=sanitize_text_field(wp_unslash($a));$v=sanitize_text_field(wp_unslash($vv[$i]??''));$n=sanitize_text_field(wp_unslash($nn[$i]??''));if($a===''&&$v===''&&$n==='')continue;if(!in_array($a,$this->accounts(),true)||!preg_match('/^\d+(\.\d{1,2})?$/',$v)||(float)$v<=0)throw new RuntimeException('Complete every split line.');$l[]=[$a,(int)round((float)$v*100),$n];}$this->saveLines($id,$l);}
 private function accept($id,$eid){$valid=null;foreach($this->candidates($this->bank($id)) as $c)if((int)$c['r']['observation_id']===$eid){$valid=$c;break;}if(!$valid)throw new RuntimeException('Evidence is no longer a candidate.');$x=[$id,$eid];sort($x);$d=$this->db();$d->beginTransaction();try{$q=$d->prepare('SELECT match_id FROM inbox_matches WHERE left_observation_id IN (?,?) OR right_observation_id IN (?,?) LIMIT 1 FOR UPDATE');$q->execute([$x[0],$x[1],$x[0],$x[1]]);if(!$q->fetch())$d->prepare('INSERT INTO inbox_matches(left_observation_id,right_observation_id,confidence) VALUES(?,?,?)')->execute([$x[0],$x[1],$valid['confidence']]);$d->commit();}catch(Throwable$e){if($d->inTransaction())$d->rollBack();throw$e;}}
 private function bulkResult(){
  $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['selected']??[])))));
  if(!$ids)throw new RuntimeException('Select at least one transaction.');
  $shown=(array)($_POST['displayed_category']??[]);$matched=$categorized=$skip=$errors=0;$processed=[];
  foreach($ids as $id){
   $changed=false;
   try{
    $b=$this->bank($id);if(!$b)throw new RuntimeException('Transaction not found.');
    if($b['source_system']!=='ofx_visa'&&!$this->accepted($id)){
     $c=$this->candidates($b);
     if($c){$this->accept($id,(int)$c[0]['r']['observation_id']);$matched++;$changed=true;}
    }
    $cat=sanitize_text_field(wp_unslash($shown[$id]??''));
    if($cat===''){
     $a=$this->allocations($id);$e=$b['source_system']==='ofx_visa'?null:$this->accepted($id);$c=$e?[]:$this->candidates($b);
     $cat=count($a)===1?($a[0]['export_account']??''):$this->suggest($b,$e?:($c[0]['r']??null));
    }
    if($cat!==''&&in_array($cat,$this->accounts(),true)){$this->saveCategory($id,$cat);$categorized++;$changed=true;}
    if(!$changed)$skip++;
    $processed[]=['bank_id'=>$id,'changed'=>$changed,'category'=>$cat];
   }catch(Throwable $e){$errors++;$processed[]=['bank_id'=>$id,'changed'=>false,'error'=>$e->getMessage()];}
  }
  return ['matched'=>$matched,'categorized'=>$categorized,'skipped'=>$skip,'errors'=>$errors,'processed'=>$processed];
 }
 private function bulk(){$r=$this->bulkResult();$this->redirect($r['errors']?'error':'success',sprintf('Accepted matches: %d. Saved categories: %d. Skipped: %d. Errors: %d.',$r['matched'],$r['categorized'],$r['skipped'],$r['errors']));}
 function render(){try{$this->access();$view=isset($_GET['to403_view'])?sanitize_key(wp_unslash($_GET['to403_view'])):'transactions';if($view==='claims')return $this->renderClaims();$rows=$this->rows();ob_start();echo'<div class="to403-register"><h2>403TO Transaction Inbox</h2>';$this->tabs('transactions');echo'<p class="help">Choose a category in the register. Use Split only when one bank transaction needs multiple category lines.</p>';$this->notice();$this->filters();echo'<div class="scroll"><table><thead><tr><th><input type="checkbox" class="all"></th><th>Date</th><th>Account</th><th class="money">Amount</th><th>Description / evidence</th><th>Category</th><th>Status</th><th></th></tr></thead><tbody>';foreach($rows as$b)$this->row($b);echo'</tbody></table></div>';foreach($rows as$b)$this->renderModal($b);echo'<form id="to403-bulk-form" class="bulk" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->hidden('bulk');echo'<button type="submit" class="primary accept-selected">Accept selected</button><span class="bulk-help">Accepts each displayed evidence match and saves each displayed category.</span></form></div>';return ob_get_clean();}catch(Throwable$e){return'<div class="msg error">'.esc_html($e->getMessage()).'</div>';}}
 private function filters(){echo'<form method="get" class="filters"><label>Account <select name="account"><option value="">All accounts</option><option value="ofx_operating"'.selected($_GET['account']??'','ofx_operating',false).'>Operating</option><option value="ofx_cg_funds"'.selected($_GET['account']??'','ofx_cg_funds',false).'>CG Funds</option><option value="ofx_visa"'.selected($_GET['account']??'','ofx_visa',false).'>GC Visa</option></select></label><button>Filter</button></form>';}
 private function account_label($source){return ['ofx_operating'=>'Operating','ofx_cg_funds'=>'CG Funds','ofx_visa'=>'GC Visa'][$source]??$source;}
 private function evidence_label($r,$p){if(($r['source_system']??'')==='gravity_forms')return trim((string)($p['payee']??$p['submitter_name']??'Gravity Forms'));return trim((string)($p['payer_name']??'Interac'));}
 private function evidence_source($r){return($r['source_system']??'')==='gravity_forms'?'Gravity Forms':'Interac';}
 private function receipt_links($p){$urls=$p['receipt_urls']??[];if(!is_array($urls))$urls=[$urls];$html='';foreach($urls as$u){$u=esc_url($u);if($u!=='')$html.=' · <a href="'.$u.'" target="_blank" rel="noopener noreferrer">Receipt</a>'; }return$html;}
 private function row($b){
  $this->accountDirection=(float)$b['amount']<0?'expense':'income';
  $id=(int)$b['observation_id'];$isVisa=$b['source_system']==='ofx_visa';$e=$isVisa?null:$this->accepted($id);$c=($isVisa||$e)?[]:$this->candidates($b);$a=$this->allocations($id);$r=$this->review($id);$st=$this->status($b,$a,$r);$p=$this->payload($b);$cat=count($a)===1?$a[0]['export_account']:$this->suggest($b,$e?:($c[0]['r']??null));
  echo'<tr class="bank '.$st.'" data-bank-id="'.$id.'"><td><input class="check" type="checkbox" name="selected[]" form="to403-bulk-form" value="'.$id.'"></td><td>'.esc_html($b['transaction_date']).'</td><td>'.$this->account_label($b['source_system']).'</td><td class="money">'.((float)$b['amount']>=0?'+':'−').'$'.number_format(abs((float)$b['amount']),2).'</td><td><strong>'.esc_html($p['memo']??$b['description']).'</strong><br><small>'.esc_html($p['name']??$b['description']).' · FITID '.esc_html($p['fitid']??'').'</small></td><td><form class="category" data-bank-id="'.$id.'" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->hidden('save-category');echo'<input type="hidden" name="bank_id" value="'.$id.'"><select name="category"><option value="">Choose category</option>';$this->options($cat);echo'</select><button type="submit" class="category-save">Save</button></form></td><td><span class="pill">'.esc_html(strtoupper(str_replace('-',' ',$st))).'</span></td><td><button type="button" class="split-open" data-id="split-'.$id.'">'.(count($a)>1?'Edit split':'Split').'</button></td></tr>';
  if($e){$ep=$this->payload($e);echo'<tr class="evidence"><td></td><td colspan="7">└─ <b>MATCHED</b> · Interac · '.esc_html($ep['payer_name']??'').' · '.esc_html($e['description']).' · '.esc_html($ep['section']??'').'</td></tr>';}
  elseif($c){
   $top=$c[0];$alts=array_slice($c,1);
   echo'<tr class="evidence suggested best"><td></td><td colspan="7">└─ <b>'.esc_html($top['exact_name']?'EXACT PAYER NAME':$top['confidence'].' '.(int)$top['score']).'</b> · Interac · '.esc_html($top['p']['payer_name']??'').' · '.esc_html($top['r']['description']).' <form class="inline ajax-accept" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->hidden('accept');echo'<input type="hidden" name="bank_id" value="'.$id.'"><input type="hidden" name="evidence_id" value="'.(int)$top['r']['observation_id'].'"><button type="submit" class="accept-match">Accept</button></form>';
   if($alts)echo' <button type="button" class="candidates-toggle" aria-expanded="false" data-target="alternative-'.$id.'">Show alternatives ('.count($alts).')</button>';
   echo'</td></tr>';
   if($alts){foreach($alts as$x){echo'<tr class="evidence suggested alternative alternative-'.$id.'" hidden><td></td><td colspan="7">└─ <b>'.esc_html($x['exact_name']?'EXACT PAYER NAME':$x['confidence'].' '.(int)$x['score']).'</b> · Interac · '.esc_html($x['p']['payer_name']??'').' · '.esc_html($x['r']['description']).' <form class="inline ajax-accept" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->hidden('accept');echo'<input type="hidden" name="bank_id" value="'.$id.'"><input type="hidden" name="evidence_id" value="'.(int)$x['r']['observation_id'].'"><button type="submit" class="accept-match">Accept</button></form></td></tr>';}}
  }
 }

 private function tabs($active){$base=remove_query_arg('to403_view');$transactions=$base;$claims=add_query_arg('to403_view','claims',$base);echo'<nav class="to403-tabs" aria-label="Transaction Inbox views"><a class="'.($active==='transactions'?'active':'').'" href="'.esc_url($transactions).'">Transactions</a><a class="'.($active==='claims'?'active':'').'" href="'.esc_url($claims).'">Expense Claims</a></nav>';}
 private function claims(){$q=$this->db()->query("SELECT o.*,CASE WHEN EXISTS(SELECT 1 FROM inbox_matches m WHERE m.left_observation_id=o.observation_id OR m.right_observation_id=o.observation_id) THEN 1 ELSE 0 END AS is_matched FROM inbox_observations o WHERE o.source_system='gravity_forms' ORDER BY o.transaction_date DESC,o.observation_id DESC");return$q->fetchAll();}
 private function renderClaims(){$rows=$this->claims();ob_start();echo'<div class="to403-register"><h2>403TO Transaction Inbox</h2>';$this->tabs('claims');echo'<p class="help">Expense claims are evidence submissions and remain visible whether or not they are matched to a bank or credit-card transaction.</p><div class="scroll"><table class="claims"><thead><tr><th>Date</th><th>GF Entry #</th><th>Claimant</th><th>Payee</th><th>Description</th><th>Payment Method</th><th class="money">Amount</th><th>Submitted Category</th><th>Receipt Link</th><th>Matched</th></tr></thead><tbody>';foreach($rows as$r){$p=$this->payload($r);$receipts=array_values(array_filter((array)($p['receipt_urls']??[])));echo'<tr><td>'.esc_html($r['transaction_date']).'</td><td>#'.esc_html($r['gf_entry_id']?:($p['entry_id']??'')).'</td><td>'.esc_html($p['submitter_name']??'').'</td><td>'.esc_html($p['payee']??'').'</td><td>'.esc_html($p['description']??$r['description']).'</td><td>'.esc_html($p['payment_method']??'').'</td><td class="money">$'.number_format(abs((float)$r['amount']),2).'</td><td>'.esc_html($p['submitted_category']??'').'</td><td>';if($receipts){foreach($receipts as$i=>$url){if($i)echo'<br>';echo'<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">Receipt '.($i+1).'</a>';}}else echo'—';echo'</td><td><span class="pill '.($r['is_matched']?'matched':'unmatched').'">'.($r['is_matched']?'MATCHED':'UNMATCHED').'</span></td></tr>';}if(!$rows)echo'<tr><td colspan="10">No Gravity Forms expense claims found.</td></tr>';echo'</tbody></table></div></div>';return ob_get_clean();}
 private function renderModal($b){$this->accountDirection=(float)$b['amount']<0?'expense':'income';$id=(int)$b['observation_id'];$isVisa=$b['source_system']==='ofx_visa';$e=$isVisa?null:$this->accepted($id);$c=($isVisa||$e)?[]:$this->candidates($b);$a=$this->allocations($id);$cat=count($a)===1?$a[0]['export_account']:$this->suggest($b,$e?:($c[0]['r']??null));$this->modal($b,$a,$cat);}
 private function options($sel){foreach($this->accounts($this->accountDirection)as$a)echo'<option value="'.esc_attr($a).'"'.selected($sel,$a,false).'>'.esc_html($a).'</option>';}
 private function modal($b,$a,$cat){$id=(int)$b['observation_id'];$lines=$a?:[['export_account'=>$cat,'amount'=>number_format(abs((float)$b['amount']),2,'.',''),'allocation_note'=>''],['export_account'=>'','amount'=>'','allocation_note'=>'']];echo'<div class="modal" id="split-'.$id.'" hidden><div class="backdrop" data-close></div><div class="panel"><form class="split" data-total="'.number_format(abs((float)$b['amount']),2,'.','').'" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->hidden('save-split');echo'<input type="hidden" name="bank_id" value="'.$id.'"><header><h3>Split $'.number_format(abs((float)$b['amount']),2).'</h3><button type="button" data-close>Close</button></header><div class="lines">';foreach($lines as$l)$this->line($l);echo'</div><p class="summary">Allocated $<span class="allocated">0.00</span> · Remaining $<span class="remaining">0.00</span> · <span class="split-state">Check split lines</span></p><footer><button type="button" class="add">Add line</button><button type="submit" class="primary save" disabled>Save split</button></footer></form></div></div>';}
 private function line($l){echo'<div class="line"><select name="split_account[]"><option value="">Choose category</option>';$this->options($l['export_account']??'');echo'</select><input name="split_amount[]" value="'.esc_attr($l['amount']??'').'" placeholder="0.00"><input name="split_note[]" value="'.esc_attr($l['allocation_note']??'').'" placeholder="Line note"><button type="button" class="remove">Remove</button></div>';}
 private function notice(){if(isset($_GET['to403_notice'],$_GET['to403_message']))echo'<div class="msg '.esc_attr(sanitize_key(wp_unslash($_GET['to403_notice']))).'">'.esc_html(sanitize_text_field(rawurldecode(wp_unslash($_GET['to403_message'])))).'</div>';}
}
register_activation_hook(__FILE__,['TO403_Transaction_Inbox','activate']);TO403_Transaction_Inbox::instance();
