<?php
/**
 * Plugin Name: 403TO Transaction Inbox Tools
 * Description: Adds a Tools tab to 403TO Transaction Inbox for inbox summaries, Gravity Forms backfill, cut-over cleanup, reset controls, and planned Wave export notes.
 * Version: 0.1.1
 * Author: 403TO
 */
if (!defined('ABSPATH')) exit;

final class TO403_Inbox_Tools {
    const VERSION='0.1.1';
    const VIEW='tools';
    private static $instance;
    public static function instance(){return self::$instance?:self::$instance=new self();}
    private function __construct(){
        add_filter('pre_do_shortcode_tag',[$this,'intercept'],10,4);
        add_action('wp_footer',[$this,'tab_script'],99);
        add_action('admin_post_to403_tools_gf_preview',[$this,'gf_preview']);
        add_action('admin_post_to403_tools_gf_import',[$this,'gf_import']);
        add_action('admin_post_to403_tools_cutover_preview',[$this,'cutover_preview']);
        add_action('admin_post_to403_tools_purge',[$this,'purge']);
        add_action('admin_post_to403_tools_reset_preview',[$this,'reset_preview']);
        add_action('admin_post_to403_tools_reset',[$this,'reset']);
        add_action('wp_enqueue_scripts',[$this,'assets']);
    }
    public function assets(){
        if(!is_singular())return;
        wp_enqueue_style('to403-inbox-tools',plugins_url('assets/tools.css',__FILE__),[],self::VERSION);
    }
    public function intercept($return,$tag,$attr,$m){
        if($tag!=='403to_transaction_inbox' || $this->view()!==self::VIEW)return $return;
        return $this->render();
    }
    private function view(){return isset($_GET['to403_view'])?sanitize_key(wp_unslash($_GET['to403_view'])):'transactions';}
    public function tab_script(){
        if(is_admin())return;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
          document.querySelectorAll('.to403-tabs').forEach(function(nav){
            if(nav.querySelector('[data-to403-tools]'))return;
            var a=document.createElement('a');
            var u=new URL(window.location.href);u.searchParams.set('to403_view','tools');
            a.href=u.toString();a.textContent='Tools';a.dataset.to403Tools='1';
            if(new URL(window.location.href).searchParams.get('to403_view')==='tools')a.className='active';
            nav.appendChild(a);
          });
        });
        </script><?php
    }
    private function access(){
        if(!is_user_logged_in() || !current_user_can('manage_options'))wp_die('You do not have permission to use Finance Tools.',403);
    }
    private function db(){
        if(defined('TO403_INBOX_DSN')&&defined('TO403_INBOX_DB_USER')&&defined('TO403_INBOX_DB_PASSWORD')){
            return new PDO(TO403_INBOX_DSN,TO403_INBOX_DB_USER,TO403_INBOX_DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        }
        if(class_exists('TO403_Transaction_Inbox')){
            try{$r=new ReflectionClass('TO403_Transaction_Inbox');$o=$r->getMethod('instance')->invoke(null);$m=$r->getMethod('db');$m->setAccessible(true);$db=$m->invoke($o);if($db instanceof PDO)return $db;}catch(Throwable $e){}
        }
        throw new RuntimeException('Transaction Inbox database connection is unavailable. Configure TO403_INBOX_DSN, TO403_INBOX_DB_USER and TO403_INBOX_DB_PASSWORD, or update the Transaction Inbox plugin to expose its PDO connection.');
    }
    private function base_url($view='tools'){$url=remove_query_arg(['to403_tools_notice','to403_tools_data']);return add_query_arg('to403_view',$view,$url);}
    private function tabs(){
        $base=remove_query_arg('to403_view');
        echo '<nav class="to403-tabs" aria-label="Transaction Inbox views">';
        echo '<a href="'.esc_url($base).'">Transactions</a>';
        echo '<a href="'.esc_url(add_query_arg('to403_view','claims',$base)).'">Expense Claims</a>';
        echo '<a class="active" data-to403-tools="1" href="'.esc_url(add_query_arg('to403_view','tools',$base)).'">Tools</a></nav>';
    }
    private function notice(){
        if(empty($_GET['to403_tools_notice']))return;
        $type=isset($_GET['to403_tools_type'])?sanitize_key($_GET['to403_tools_type']):'success';
        echo '<div class="to403-tools-notice '.esc_attr($type).'">'.esc_html(wp_unslash($_GET['to403_tools_notice'])).'</div>';
        if(!empty($_GET['to403_tools_data'])){
            $data=json_decode(base64_decode(sanitize_text_field(wp_unslash($_GET['to403_tools_data']))),true);
            if(is_array($data)){$this->results($data);}
        }
    }
    private function card_start($title,$class=''){echo '<section class="to403-tool-card '.esc_attr($class).'"><h3>'.esc_html($title).'</h3>';}
    private function card_end(){echo '</section>';}
    private function action_form($action,$fields=[],$submit='Run',$class='button button-primary'){
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr($action).'">';wp_nonce_field($action);
        foreach($fields as $name=>$html)echo $html;
        echo '<button type="submit" class="'.esc_attr($class).'">'.esc_html($submit).'</button></form>';
    }
    public function render(){
        try{$this->access();ob_start();echo '<div class="to403-register to403-tools"><h2>403TO Transaction Inbox</h2>';$this->tabs();$this->notice();
        $this->card_start('Inbox Summary');$this->summary();$this->card_end();
        echo '<div class="to403-tools-grid">';
        $this->card_start('Gravity Forms Backfill');echo '<p>Preview or import active expense-claim entries created on or after the selected date. Existing inbox observations are refreshed through the installed Gravity Forms Wave Sync import routine.</p>';
        $date='<label>Import claims created on or after <input required type="date" name="from_date" value="'.esc_attr(gmdate('Y-m-d',strtotime('-30 days'))).'"></label>';
        $this->action_form('to403_tools_gf_preview',['from_date'=>$date],'Preview');$this->action_form('to403_tools_gf_import',['from_date'=>$date],'Import','button button-primary');$this->card_end();
        $this->card_start('Interac Email Import');echo '<p>The current command-line processor advances by IMAP UID. A future update will add a date-aware processor entry point, then this panel will preview and import Interac deposit emails received on or after a selected date.</p><label>Planned start date <input type="date" disabled></label><button class="button" disabled>Not yet connected</button>';$this->card_end();
        $this->card_start('OFX Import');echo '<p>The existing command-line OFX ingester imports supported bank statement files using idempotent source keys. A future update will place a validated multi-file OFX uploader here and show inserted, refreshed and rejected counts.</p><input type="file" disabled multiple accept=".ofx,.txt"><button class="button" disabled>Not yet connected</button>';$this->card_end();
        $this->card_start('Cut-over Cleanup');echo '<p>Preview or remove inbox data dated before the selected clean start date. Cleanup covers observations and dependent matches and allocations. Import batch history is preserved.</p>';
        $cut='<label>Clean start date <input required type="date" name="cutover_date"></label>';
        $this->action_form('to403_tools_cutover_preview',['cutover_date'=>$cut],'Preview cleanup');
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="to403_tools_purge">';wp_nonce_field('to403_tools_purge');echo $cut.'<label class="confirm"><input required type="checkbox" name="confirm" value="yes"> I understand records before this date will be deleted.</label><button class="button button-danger" type="submit">Delete records before date</button></form>';$this->card_end();
        $this->card_start('Wave Export','planned');echo '<p><strong>Not yet implemented.</strong> No records can be sent to Wave from this area.</p><p>Planned work includes export preview, date and review-status selection, allocation validation against the Wave chart of accounts, duplicate-export protection, Wave transaction tracking, export history, audit logging, and explicit human approval before each export.</p>';$this->card_end();
        echo '</div>';
        $this->card_start('Reset Inbox','danger-zone');echo '<p>Reset removes all imported inbox observations, their allocations and matches. Import batch history is preserved. It does not delete Gravity Forms entries, email messages, OFX source files, legacy payment records, or anything already in Wave.</p>';
        $this->action_form('to403_tools_reset_preview',[],'Preview full reset');
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="to403_tools_reset">';wp_nonce_field('to403_tools_reset');echo '<label class="confirm"><input required type="checkbox" name="confirm" value="RESET"> I understand all imported inbox data will be deleted.</label><label>Type <code>RESET INBOX</code> <input required type="text" name="confirmation_text" autocomplete="off"></label><button class="button button-danger" type="submit">Reset Inbox</button></form>';$this->card_end();
        echo '</div>';return ob_get_clean();}catch(Throwable $e){return '<div class="msg error">'.esc_html($e->getMessage()).'</div>';}
    }
    private function summary(){
        try{$rows=$this->db()->query("SELECT source_system,COUNT(*) AS n,MIN(transaction_date) AS oldest,MAX(transaction_date) AS newest FROM inbox_observations GROUP BY source_system ORDER BY source_system")->fetchAll();echo '<table class="to403-summary"><thead><tr><th>Source</th><th>Count</th><th>Oldest</th><th>Newest</th></tr></thead><tbody>';$total=0;foreach($rows as$r){$total+=(int)$r['n'];echo '<tr><td>'.esc_html($r['source_system']).'</td><td>'.number_format_i18n((int)$r['n']).'</td><td>'.esc_html($r['oldest']).'</td><td>'.esc_html($r['newest']).'</td></tr>';}echo '<tr class="total"><th>Total observations</th><th>'.number_format_i18n($total).'</th><td></td><td></td></tr></tbody></table>';}catch(Throwable$e){echo '<p class="error">'.esc_html($e->getMessage()).'</p>';}
    }
    private function results($data){echo '<div class="to403-tools-results"><h3>Results</h3><dl>';foreach($data as$k=>$v){if(is_array($v))$v=wp_json_encode($v);echo '<dt>'.esc_html($k).'</dt><dd>'.esc_html((string)$v).'</dd>';}echo '</dl></div>';}
    private function redirect($message,$type='success',$data=[]){$args=['to403_view'=>'tools','to403_tools_notice'=>$message,'to403_tools_type'=>$type];if($data)$args['to403_tools_data']=base64_encode(wp_json_encode($data));wp_safe_redirect(add_query_arg($args,remove_query_arg(['to403_tools_notice','to403_tools_type','to403_tools_data'],wp_get_referer()?:home_url('/'))));exit;}
    private function posted_date($name){$v=isset($_POST[$name])?sanitize_text_field(wp_unslash($_POST[$name])):'';$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);$e=DateTimeImmutable::getLastErrors();if(!$d || (is_array($e)&&($e['warning_count']||$e['error_count'])))throw new RuntimeException('A valid date is required.');return$d->format('Y-m-d');}
    private function guard($action){$this->access();check_admin_referer($action);}
    private function gf_entries($from){
        if(!class_exists('GFAPI'))throw new RuntimeException('Gravity Forms is not active.');
        $form_id=0;if(class_exists('GF_Wave_Settings')&&method_exists('GF_Wave_Settings','get')){$s=GF_Wave_Settings::get();$form_id=(int)($s['form_id']??0);}if(!$form_id)throw new RuntimeException('The Gravity Forms Wave Sync form ID is not configured.');
        $form=GFAPI::get_form($form_id);if(!$form)throw new RuntimeException('Configured Gravity Form could not be loaded.');
        $entries=[];$offset=0;do{$page=GFAPI::get_entries($form_id,['status'=>'active','start_date'=>$from.' 00:00:00'],null,['offset'=>$offset,'page_size'=>200]);if(is_wp_error($page))throw new RuntimeException($page->get_error_message());$entries=array_merge($entries,$page);$offset+=count($page);}while(count($page)===200);return[$form,$entries];
    }
    public function gf_preview(){try{$this->guard('to403_tools_gf_preview');$from=$this->posted_date('from_date');[$form,$entries]=$this->gf_entries($from);$ids=array_map(fn($e)=>(int)$e['id'],$entries);$existing=0;if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$q=$this->db()->prepare("SELECT COUNT(*) FROM inbox_observations WHERE source_system='gravity_forms' AND gf_entry_id IN ($ph)");$q->execute($ids);$existing=(int)$q->fetchColumn();}$this->redirect('Gravity Forms preview completed.','success',['From date'=>$from,'Entries found'=>count($entries),'Already in inbox'=>$existing,'Missing from inbox'=>max(0,count($entries)-$existing)]);}catch(Throwable$e){$this->redirect($e->getMessage(),'error');}}
    public function gf_import(){try{$this->guard('to403_tools_gf_import');$from=$this->posted_date('from_date');[$form,$entries]=$this->gf_entries($from);if(!class_exists('GF_Wave_Sync')||!method_exists('GF_Wave_Sync','instance'))throw new RuntimeException('Gravity Forms Wave Sync 0.7.1 or later is required.');$sync=GF_Wave_Sync::instance();if(!method_exists($sync,'after_submission'))throw new RuntimeException('The installed Gravity Forms Wave Sync plugin does not expose its import routine.');$ok=0;$failed=0;foreach($entries as$entry){try{$sync->after_submission($entry,$form);$ok++;}catch(Throwable$e){$failed++;}}$this->redirect('Gravity Forms backfill completed.',$failed?'warning':'success',['From date'=>$from,'Entries processed'=>$ok,'Entries failed'=>$failed]);}catch(Throwable$e){$this->redirect($e->getMessage(),'error');}}
    private function counts($where='',$params=[]){$db=$this->db();$q=$db->prepare("SELECT source_system,COUNT(*) n FROM inbox_observations $where GROUP BY source_system ORDER BY source_system");$q->execute($params);$out=[];$ids=[];foreach($q->fetchAll()as$r)$out['Observations: '.$r['source_system']]=(int)$r['n'];$idq=$db->prepare("SELECT observation_id FROM inbox_observations $where");$idq->execute($params);$ids=array_map('intval',$idq->fetchAll(PDO::FETCH_COLUMN));$out['Total observations']=count($ids);$out['Allocations']=$this->dependent_count($db,'inbox_allocations','bank_observation_id',$ids);$out['Matches']=$this->match_count($db,$ids);return$out;}
    private function dependent_count($db,$table,$col,$ids){if(!$ids)return 0;$ph=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("SELECT COUNT(*) FROM $table WHERE $col IN ($ph)");$q->execute($ids);return(int)$q->fetchColumn();}
    private function match_count($db,$ids){if(!$ids)return 0;$ph=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("SELECT COUNT(*) FROM inbox_matches WHERE left_observation_id IN ($ph) OR right_observation_id IN ($ph)");$q->execute(array_merge($ids,$ids));return(int)$q->fetchColumn();}
    public function cutover_preview(){try{$this->guard('to403_tools_cutover_preview');$d=$this->posted_date('cutover_date');$this->redirect('Cut-over cleanup preview completed.','success',array_merge(['Delete records before'=>$d],$this->counts('WHERE transaction_date < ?',[$d])));}catch(Throwable$e){$this->redirect($e->getMessage(),'error');}}
    private function delete_ids($db,$ids){if(!$ids)return;$ph=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("DELETE FROM inbox_matches WHERE left_observation_id IN ($ph) OR right_observation_id IN ($ph)");$q->execute(array_merge($ids,$ids));$q=$db->prepare("DELETE FROM inbox_allocations WHERE bank_observation_id IN ($ph)");$q->execute($ids);$q=$db->prepare("DELETE FROM inbox_observations WHERE observation_id IN ($ph)");$q->execute($ids);}
    public function purge(){try{$this->guard('to403_tools_purge');if(($_POST['confirm']??'')!=='yes')throw new RuntimeException('Confirmation is required.');$d=$this->posted_date('cutover_date');$db=$this->db();$q=$db->prepare('SELECT observation_id FROM inbox_observations WHERE transaction_date < ?');$q->execute([$d]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));$db->beginTransaction();$this->delete_ids($db,$ids);$db->commit();$this->redirect('Cut-over cleanup completed.','success',['Deleted observations'=>count($ids),'Clean start date'=>$d]);}catch(Throwable$e){if(isset($db)&&$db->inTransaction())$db->rollBack();$this->redirect($e->getMessage(),'error');}}
    public function reset_preview(){try{$this->guard('to403_tools_reset_preview');$this->redirect('Full reset preview completed.','success',$this->counts());}catch(Throwable$e){$this->redirect($e->getMessage(),'error');}}
    public function reset(){try{$this->guard('to403_tools_reset');if(($_POST['confirm']??'')!=='RESET'||trim((string)($_POST['confirmation_text']??''))!=='RESET INBOX')throw new RuntimeException('Reset confirmation did not match.');$db=$this->db();$ids=array_map('intval',$db->query('SELECT observation_id FROM inbox_observations')->fetchAll(PDO::FETCH_COLUMN));$db->beginTransaction();$this->delete_ids($db,$ids);$db->commit();$this->redirect('Inbox reset completed.','success',['Deleted observations'=>count($ids)]);}catch(Throwable$e){if(isset($db)&&$db->inTransaction())$db->rollBack();$this->redirect($e->getMessage(),'error');}}
}
TO403_Inbox_Tools::instance();
