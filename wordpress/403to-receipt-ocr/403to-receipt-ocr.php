<?php
/**
 * Plugin Name: 403TO Receipt OCR
 * Description: Camera-first receipt OCR suggestions for a configured Gravity Form. Uses local Tesseract, supports Gravity Forms multi-file uploads, and can suggest a configured 403TO credit-card payment type.
 * Version: 1.2.1
 * Author: 403TO
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

final class GF_403TO_Receipt_OCR {
    const VERSION = '1.2.1';
    const OPTION = '403to_receipt_ocr_settings';
    const ACTION = '403to_receipt_ocr';

    public static function instance() {
        static $instance = null;
        if ($instance === null) $instance = new self();
        return $instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'ajax_ocr']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'ajax_ocr']);
    }

    public static function defaults() {
        return [
            'submitter_name_field_id' => '1',
			'submitter_email_field_id' => '2',
			'logged_in_prefill_mode' => 'readonly',
			'enabled' => '1',
            'form_id' => '9',
            'upload_field_id' => '7',
            'payee_field_id' => '4',
            'date_field_id' => '8',
            'amount_field_id' => '9',
            'payment_field_id' => '6',
            'credit_card_choice' => 'Charge on 403TO credit card',
            'card_mappings' => "2445|GC Visa control account\n5084|403TO group card\n3556|403TO group card",
            'reimbursement_choice' => 'reimbursement of Scouter (please avoid this)',
            'card_context_chars' => '40',
            'tesseract_path' => '/usr/bin/tesseract',
            'pdftoppm_path' => '/usr/bin/pdftoppm',
            'language' => 'eng',
            'max_files' => '10',
            'max_file_mb' => '8',
            'rate_limit' => '12',
            'debug_log' => '0',
        ];
    }

    public static function settings() {
        return wp_parse_args((array)get_option(self::OPTION, []), self::defaults());
    }

    public function admin_menu() {
        add_options_page('403TO Receipt OCR', '403TO Receipt OCR', 'manage_options', '403to-receipt-ocr', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('403to_receipt_ocr', self::OPTION, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        $d = self::defaults();
        $out = [];
        foreach (['enabled','debug_log'] as $key) $out[$key] = empty($input[$key]) ? '0' : '1';
        foreach (['form_id','upload_field_id','payee_field_id','date_field_id','amount_field_id','payment_field_id','card_context_chars','max_files','max_file_mb','rate_limit'] as $key) {
            $out[$key] = (string)max(1, absint($input[$key] ?? $d[$key]));
        }
        foreach (['tesseract_path','pdftoppm_path','credit_card_choice','reimbursement_choice'] as $key) $out[$key] = sanitize_text_field($input[$key] ?? $d[$key]);
        $out['language'] = preg_replace('/[^a-zA-Z0-9_+\-]/', '', (string)($input['language'] ?? 'eng')) ?: 'eng';
        $lines = preg_split('/\R/', (string)($input['card_mappings'] ?? ''));
        $clean = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            $suffix = preg_replace('/\D/', '', $parts[0] ?? '');
            if (strlen($suffix) !== 4) continue;
            $label = sanitize_text_field($parts[1] ?? '');
            $clean[] = $suffix . ($label !== '' ? '|' . $label : '');
        }
        $out['card_mappings'] = implode("\n", array_values(array_unique($clean)));
        return $out;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::settings(); ?>
<div class="wrap">
	<h1>403TO Receipt OCR</h1>
	<p>OCR suggestions remain visible and editable. Credit-card detection only selects the configured payment choice when no payment option is already selected.</p>
	<form method="post" action="options.php"><?php settings_fields('403to_receipt_ocr'); ?>
		<table class="form-table" role="presentation"><?php
        $this->checkbox_row('Enabled', 'enabled', $s);
        $this->text_row('Gravity Form ID', 'form_id', $s, 'number');
        $this->text_row('Upload field ID', 'upload_field_id', $s, 'number');
        $this->text_row('Payee field ID', 'payee_field_id', $s, 'number');
        $this->text_row('Date field ID', 'date_field_id', $s, 'number');
        $this->text_row('Amount field ID', 'amount_field_id', $s, 'number');
        $this->text_row('Payment type radio field ID', 'payment_field_id', $s, 'number');
        $this->text_row('Credit-card choice label/value', 'credit_card_choice', $s);
        $this->textarea_row('Card suffix mappings', 'card_mappings', $s, 'One per line as 5084|Internal label. Labels stay in WordPress settings and are not shown on the public form.');
        $this->text_row('Card context distance (characters)', 'card_context_chars', $s, 'number');
        $this->text_row('Tesseract path', 'tesseract_path', $s);
        $this->text_row('pdftoppm path', 'pdftoppm_path', $s);
        $this->text_row('Tesseract language', 'language', $s);
        $this->text_row('Maximum files per OCR request', 'max_files', $s, 'number');
        $this->text_row('Maximum size per file (MB)', 'max_file_mb', $s, 'number');
        $this->text_row('OCR requests per IP per 10 minutes', 'rate_limit', $s, 'number');
        $this->checkbox_row('Write diagnostic messages to the PHP error log', 'debug_log', $s);
        ?>
		</table><?php submit_button(); ?>
	</form>
	<h2>Server checks</h2>
	<ul>
		<li>Tesseract: <strong><?php echo esc_html($this->command_available($s['tesseract_path']) ? 'available' : 'not available'); ?>
			</strong>
		</li>
		<li>pdftoppm: <strong><?php echo esc_html($this->command_available($s['pdftoppm_path']) ? 'available' : 'not available'); ?>
			</strong>
		</li>
		<li>PHP process execution: <strong><?php echo esc_html(function_exists('proc_open') ? 'available' : 'not available'); ?>
			</strong>
		</li>
	</ul>
</div><?php
    }

    private function text_row($label, $key, $s, $type='text') {
        printf('<tr><th scope="row"><label for="receipt_ocr_403to_%1$s">%2$s</label></th><td><input class="regular-text" type="%3$s" id="receipt_ocr_403to_%1$s" name="%4$s[%1$s]" value="%5$s"></td></tr>', esc_attr($key), esc_html($label), esc_attr($type), esc_attr(self::OPTION), esc_attr($s[$key]));
    }
    private function textarea_row($label, $key, $s, $help='') {
        printf('<tr><th scope="row"><label for="receipt_ocr_403to_%1$s">%2$s</label></th><td><textarea class="large-text code" rows="5" id="receipt_ocr_403to_%1$s" name="%3$s[%1$s]">%4$s</textarea>%5$s</td></tr>', esc_attr($key), esc_html($label), esc_attr(self::OPTION), esc_textarea($s[$key]), $help ? '<p class="description">'.esc_html($help).'</p>' : '');
    }
    private function checkbox_row($label, $key, $s) {
        printf('<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[%3$s]" value="1" %4$s> Yes</label></td></tr>', esc_html($label), esc_attr(self::OPTION), esc_attr($key), checked($s[$key], '1', false));
    }

    public function enqueue() {
        $s = self::settings();
        if ($s['enabled'] !== '1' || !class_exists('GFForms')) return;
        wp_enqueue_style('403to-receipt-ocr', plugin_dir_url(__FILE__) . 'assets/receipt-ocr.css', [], self::VERSION);
        wp_enqueue_script('403to-receipt-ocr', plugin_dir_url(__FILE__) . 'assets/receipt-ocr.js', [], self::VERSION, true);
        wp_localize_script('403to-receipt-ocr', '_403TOReceiptOCR', [
            'ajaxUrl' => admin_url('admin-ajax.php'), 'action' => self::ACTION,
            'nonce' => wp_create_nonce(self::ACTION), 'formId' => (int)$s['form_id'],
            'uploadFieldId' => (int)$s['upload_field_id'], 'payeeFieldId' => (int)$s['payee_field_id'],
            'dateFieldId' => (int)$s['date_field_id'], 'amountFieldId' => (int)$s['amount_field_id'],
            'paymentFieldId' => (int)$s['payment_field_id'], 'creditCardChoice' => $s['credit_card_choice'], 'reimbursementChoice' => $s['reimbursement_choice'],
            'maxFiles' => (int)$s['max_files'],
            'messages' => [
                'reading'=>'Reading receipt…',
                'review'=>'Receipt details found. Please check the values before submitting.',
                'none'=>'The receipt could not be read confidently. Please enter the details manually.',
                'error'=>'OCR was unavailable. You can continue by entering the details manually.',
                'multiple'=>'Multiple receipts were read. The total is their combined amount; check all details carefully.',
                'card'=>'Charge on 403TO credit card was detected and selected. Please confirm it is correct.'
            ]
        ]);
    }

    public function ajax_ocr() {
        check_ajax_referer(self::ACTION, 'nonce');
        $s = self::settings();
        if ($s['enabled'] !== '1') wp_send_json_error(['message'=>'OCR is disabled.'], 503);
        if (!$this->allow_request((int)$s['rate_limit'])) wp_send_json_error(['message'=>'Too many OCR requests. Please enter the details manually.'], 429);
        if (empty($_FILES['receipts'])) wp_send_json_error(['message'=>'No receipt was received.'], 400);
        if (!function_exists('proc_open') || !$this->command_available($s['tesseract_path'])) wp_send_json_error(['message'=>'The OCR engine is unavailable.'], 503);
        $files = array_slice($this->normalize_files($_FILES['receipts']), 0, (int)$s['max_files']);
        $results = [];
        foreach ($files as $file) {
            try { $results[] = $this->process_upload($file, $s); }
            catch (Throwable $e) { $this->log('OCR file failed: '.$e->getMessage(), $s); $results[]=['success'=>false,'merchant'=>'','date'=>'','total'=>'','card_match'=>false,'warning'=>'Could not read this receipt.']; }
        }
        $successful = array_values(array_filter($results, fn($r)=>!empty($r['success'])));
        if (!$successful) wp_send_json_success(['success'=>false,'receipts'=>$results,'merchant'=>'','date'=>'','total'=>'','card_detected'=>false,'card_match'=>false,'card_suffix'=>'','warnings'=>['No receipt was read confidently.']]);
        
		$combined = count($successful)>1;
		$total = 0.0;
		$card_detected = false;
		$card_match = false;
		$suffix = '';

		foreach ($successful as $r) {

			if ($r['total'] !== '') {
				$total += (float) $r['total'];
			}

			if (!empty($r['card_detected'])) {
				$card_detected = true;

				if ($suffix === '' && !empty($r['card_suffix'])) {
					$suffix = $r['card_suffix'];
				}
			}

			if (!empty($r['card_match'])) {
				$card_match = true;
				$suffix = $r['card_suffix'];
			}
		}
        wp_send_json_success([
            'success'=>true, 'receipts'=>$results,
            'merchant'=>$combined?'':$successful[0]['merchant'], 'date'=>$combined?'':$successful[0]['date'],
            'total'=>$total>0?number_format($total,2,'.',''):'',
            'confidence'=>$combined?['total'=>0.75]:$successful[0]['confidence'],
            'card_detected'=>$card_detected,'card_match'=>$card_match,'card_suffix'=>$suffix,
            'warnings'=>$combined?['Multiple receipts were combined. Confirm the total and enter a suitable payee/date.']:[],
        ]);
    }

    private function process_upload($file, $s) {
        if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error '.(int)$file['error']);
        if ((int)$file['size']<=0 || (int)$file['size']>(int)$s['max_file_mb']*1024*1024) throw new RuntimeException('Invalid file size.');
        $check=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','pdf'=>'application/pdf']);
        $mime=$check['type']?:''; if(!in_array($mime,['image/jpeg','image/png','application/pdf'],true)) throw new RuntimeException('Unsupported file type.');
        $tmp=trailingslashit(get_temp_dir()).'403to-ocr-'.wp_generate_uuid4(); if(!wp_mkdir_p($tmp)) throw new RuntimeException('Could not create temporary directory.');
        try {
            $input=$tmp.'/input.'.($mime==='application/pdf'?'pdf':($mime==='image/png'?'png':'jpg'));
            if(!move_uploaded_file($file['tmp_name'],$input)) throw new RuntimeException('Could not stage upload.');
            if($mime==='application/pdf') {
                if(!$this->command_available($s['pdftoppm_path'])) throw new RuntimeException('PDF conversion is unavailable.');
                $prefix=$tmp.'/page'; $this->run([$s['pdftoppm_path'],'-f','1','-singlefile','-r','200','-png',$input,$prefix],30);
                $input=$prefix.'.png'; if(!is_file($input)) throw new RuntimeException('PDF conversion failed.');
            }
            $outbase=$tmp.'/ocr'; $this->run([$s['tesseract_path'],$input,$outbase,'-l',$s['language'],'--psm','6'],30);
            $text=is_file($outbase.'.txt')?(string)file_get_contents($outbase.'.txt'):''; if(trim($text)==='') throw new RuntimeException('No OCR text was returned.');
            return $this->parse_receipt($text,$s);
        } finally { $this->remove_tree($tmp); }
    }

    private function parse_receipt($text,$s) {
        $text=wp_check_invalid_utf8($text,true);
        $lines=array_values(array_filter(array_map('trim',preg_split('/\R/u',$text)),fn($v)=>$v!==''));
        $document_type=$this->classify_document($text);
        $merchant=$this->parse_merchant($lines,$text);
        $date=$this->parse_date($lines);
        $total_result=$this->parse_total($lines,$document_type);
        $card=$this->detect_card($text,$s);
        $total=$total_result['value'];
        return [
            'success'=>($merchant||$date||$total||$card['detected']),
            'merchant'=>$merchant,
            'date'=>$date,
            'total'=>$total,
            'document_type'=>$document_type,
            'confidence'=>[
                'merchant'=>$merchant?0.78:0.0,
                'date'=>$date?0.75:0.0,
                'total'=>$total_result['confidence'],
                'payment_type'=>$card['detected']?0.90:0.0,
            ],
            'card_detected'=>$card['detected'],
            'card_match'=>$card['match'],
            'card_suffix'=>$card['suffix'],
            'text_hash'=>hash('sha256',$text),
        ];
    }

    private function card_mappings($raw) {
        $out=[];
        foreach(preg_split('/\R/',(string)$raw) as $line){
            $parts=array_map('trim',explode('|',$line,2));
            $suffix=preg_replace('/\D/','',$parts[0]??'');
            if(strlen($suffix)===4)$out[$suffix]=$parts[1]??'';
        }
        return $out;
    }

    private function detect_card($text,$s) {
        $flat=strtolower(preg_replace('/\s+/u',' ',(string)$text));
        $distance=max(10,min(100,(int)$s['card_context_chars']));
        $keywords='(?:credit\s*card|card\s*(?:no\.?|number)?|ending\s+in|ends?\s+in|visa|mastercard|master\s*card|amex|american\s+express)';
        $candidates=[];
        $patterns=[
            '/'.$keywords.'.{0,'.$distance.'}?(?:x{2,}|\*{2,}|#?\s*)?(\d{4})\b/i',
            '/(?:x{2,}|\*{2,})(\d{4}).{0,'.$distance.'}?'.$keywords.'/i',
            '/\b(\d{4})\b.{0,'.$distance.'}?'.$keywords.'/i',
        ];
        foreach($patterns as $pattern){
            if(preg_match_all($pattern,$flat,$matches)) foreach($matches[1] as $suffix)$candidates[]=$suffix;
        }
        $candidates=array_values(array_unique($candidates));
        if(!$candidates)return ['detected'=>false,'match'=>false,'suffix'=>'','label'=>''];
        $known=$this->card_mappings($s['card_mappings']);
        foreach($candidates as $suffix){
            if(isset($known[$suffix]))return ['detected'=>true,'match'=>true,'suffix'=>$suffix,'label'=>$known[$suffix]];
        }
        return ['detected'=>true,'match'=>false,'suffix'=>$candidates[0],'label'=>''];
    }

    private function classify_document($text) {
        $flat=strtolower((string)$text);
        if(preg_match('/\b(order confirmed|order confirmation|thank you for your order|order has been placed)\b/',$flat))return 'order_confirmation';
        if(preg_match('/\b(invoice|amount due|balance due)\b/',$flat))return 'invoice';
        if(preg_match('/\b(credit card receipt|merchant copy|terminal id|authorization code)\b/',$flat))return 'credit_card_slip';
        if(preg_match('/\b(receipt|subtotal|grand total|cashier)\b/',$flat))return 'receipt';
        return 'unknown';
    }

    private function parse_merchant($lines,$text) {
        $joined=implode("\n",array_slice($lines,0,20));
        if(preg_match('/(?:^|[|\-])\s*([A-Z][A-Za-z0-9 &\'’.-]{2,60})\s*(?:https?:\/\/|www\.)/m',$joined,$m))return sanitize_text_field(trim($m[1]));
        if(preg_match('/https?:\/\/(?:www\.)?([a-z0-9-]+)\.[a-z]{2,}/i',$text,$m)){
            $host=strtolower($m[1]);
            $known=['tshirtelephant'=>'T-Shirt Elephant'];
            if(isset($known[$host]))return $known[$host];
            if(!in_array($host,['google','gmail','webbrowsertools'],true))return sanitize_text_field(ucwords(str_replace('-',' ',$host)));
        }
        $reject='/\b(order confirmed|confirmation|thank you|receipt|invoice|transaction|merchant|terminal|cashier|customer|copy|visa|mastercard|interac|approved|subtotal|total|tax|hst|gst|expected ship date|track your order)\b/i';
        foreach(array_slice($lines,0,12) as $line){
            $clean=trim(preg_replace('/\s{2,}/',' ',$line));
            if(strlen($clean)<3||strlen($clean)>80||preg_match($reject,$clean))continue;
            if(preg_match('/[A-Za-z]{3}/',$clean)&&!preg_match('/^\d+[\d\s\-().]*$/',$clean))return sanitize_text_field($clean);
        }
        return '';
    }

    private function parse_total($lines,$document_type='unknown') {
        $best=null;$best_score=-999;$best_label='';
        $positive=[
            'charged'=>120,'amount paid'=>115,'payment total'=>110,'grand total'=>105,
            'order total'=>100,'amount due'=>95,'balance due'=>95,'total due'=>95,
            'total'=>80,'payment'=>60,
        ];
        foreach($lines as $index=>$line){
            $normalized=preg_replace('/(?<=\d)\s+(?=\d{3}(?:[,.]\d{2})\b)/','',$line);
            if(preg_match('/\b\d{1,3}(?:\.\d{1,3}){2,}\b/',$normalized))continue;
            if(!preg_match_all('/(?<![\d.])(?:CAD\s*)?\$?\s*(\d{1,6}(?:,\d{3})*(?:\.\d{2})|\d{1,6}(?:\.\d{3})*,\d{2})(?![\d.])/i',$normalized,$matches))continue;
            foreach($matches[1] as $raw){
                $value=$this->money_value($raw);
                if($value<=0||$value>100000)continue;
                $lower=strtolower($normalized);$score=$index/max(1,count($lines));$label='';
                foreach($positive as $phrase=>$bonus){if(stripos($lower,$phrase)!==false){$score+=$bonus;$label=$phrase;}}
                if($document_type==='order_confirmation'&&preg_match('/\b(charged|amount paid|order total)\b/i',$normalized))$score+=30;
                if($document_type==='invoice'&&preg_match('/\b(amount due|balance due|total due)\b/i',$normalized))$score+=30;
                if(preg_match('/\b(subtotal|sub-total|tax|hst|gst|pst|qst|change|cash|tender|savings?|discount|shipping)\b/i',$normalized))$score-=90;
                if(preg_match('/\b(unit price|price each|qty|quantity|sku|item|per unit)\b/i',$normalized))$score-=140;
                if(preg_match('/(?:https?:\/\/|www\.|\b(?:ip|page)\b)/i',$normalized))$score-=200;
                if($score>$best_score){$best_score=$score;$best=$value;$best_label=$label;}
            }
        }
        if($best===null)return ['value'=>'','confidence'=>0.0,'label'=>''];
        $confidence=$best_score>=100?0.97:($best_score>=80?0.92:($best_score>=50?0.80:0.55));
        return ['value'=>number_format($best,2,'.',''),'confidence'=>$confidence,'label'=>$best_label];
    }

    private function money_value($raw) {
        $raw=preg_replace('/\s+/','',trim($raw));
        if(strpos($raw,',')!==false&&strpos($raw,'.')!==false){
            if(strrpos($raw,'.')>strrpos($raw,','))$raw=str_replace(',','',$raw);
            else{$raw=str_replace('.','',$raw);$raw=str_replace(',','.',$raw);}
        }elseif(strpos($raw,',')!==false){$raw=str_replace(',','.',$raw);}
        return is_numeric($raw)?(float)$raw:0.0;
    }

    private function parse_date($lines) {
        $p1='/\b(20\d{2})[-\/.](0?[1-9]|1[0-2])[-\/.](0?[1-9]|[12]\d|3[01])\b/';$p2='/\b(0?[1-9]|[12]\d|3[01])[-\/.](0?[1-9]|1[0-2])[-\/.](20\d{2})\b/';$p3='/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2}),?\s+(20\d{2})\b/i';
        foreach($lines as $line){if(preg_match($p1,$line,$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return sprintf('%02d-%02d-%04d',$m[3],$m[2],$m[1]);if(preg_match($p2,$line,$m)&&checkdate((int)$m[2],(int)$m[1],(int)$m[3]))return sprintf('%02d-%02d-%04d',$m[1],$m[2],$m[3]);if(preg_match($p3,$line,$m)){$dt=DateTime::createFromFormat('!M j Y',substr($m[1],0,3).' '.$m[2].' '.$m[3]);if($dt)return $dt->format('d-m-Y');}}return '';
    }

    private function normalize_files($files){$out=[];if(!is_array($files['name']))return[$files];foreach($files['name'] as $i=>$name)$out[]=['name'=>$name,'type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i]??0];return$out;}
    private function allow_request($limit){$ip=$_SERVER['REMOTE_ADDR']??'unknown';$key='403to_ocr_'.md5($ip);$count=(int)get_transient($key);if($count>=$limit)return false;set_transient($key,$count+1,10*MINUTE_IN_SECONDS);return true;}
    private function command_available($path){return $path&&is_file($path)&&is_executable($path);}
    private function run($args,$timeout){$cmd=implode(' ',array_map('escapeshellarg',$args));$spec=[1=>['pipe','w'],2=>['pipe','w']];$p=proc_open($cmd,$spec,$pipes,null,['LC_ALL'=>'C','PATH'=>'/usr/local/bin:/usr/bin:/bin']);if(!is_resource($p))throw new RuntimeException('Could not start OCR process.');foreach($pipes as $pipe)stream_set_blocking($pipe,false);$start=time();$err='';do{$err.=stream_get_contents($pipes[2]);$status=proc_get_status($p);if(!$status['running'])break;if(time()-$start>$timeout){proc_terminate($p,9);throw new RuntimeException('OCR process timed out.');}usleep(100000);}while(true);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($p);if($exit!==0&&$exit!==-1)throw new RuntimeException('OCR command failed: '.trim($err));}
    private function remove_tree($dir){if(!is_dir($dir))return;foreach(array_diff(scandir($dir),['.','..']) as $item){$path=$dir.'/'.$item;is_dir($path)?$this->remove_tree($path):@unlink($path);}@rmdir($dir);}
    private function log($message,$s){if($s['debug_log']==='1')error_log('[403TO Receipt OCR] '.$message);}
}
add_action('plugins_loaded',['GF_403TO_Receipt_OCR','instance']);
