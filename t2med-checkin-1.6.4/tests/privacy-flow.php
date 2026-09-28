#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Checkin {
    // In-process HTTP double: every request terminates here; no socket or server.
    function curl_init(string $url): object { return (object) ['url'=>$url]; }
    function curl_setopt_array(object $h, array $options): bool { $h->options=$options; return true; }
    function curl_exec(object $h): bool {
        $fake=$GLOBALS['fake'];
        if (str_contains($h->url,'/cdn/rest/')) {
            if ($h->options[CURLOPT_CUSTOMREQUEST] === 'PUT') {
                $body=$h->options[CURLOPT_POSTFIELDS];
                if (!preg_match('/Content-Type: application\/octet-stream\r\n\r\n(.*)\r\n--checkin-/s', $body, $m)) throw new \RuntimeException('Missing PDF stream');
                $fake->uploadPdf('synthetic', $m[1], 'synthetic'); $bytes='';
            } else { $bytes=$fake->documentBytes('synthetic'); }
        } else {
            $path=substr($h->url,strpos($h->url,'/aps/rest')+9);
            $result=$fake->request($h->options[CURLOPT_CUSTOMREQUEST],$path,
                isset($h->options[CURLOPT_POSTFIELDS]) ? json_decode($h->options[CURLOPT_POSTFIELDS],true) : null);
            if (!array_is_list($result)) $result=['successful'=>true]+$result;
            $bytes=json_encode($result,JSON_THROW_ON_ERROR);
        }
        ($h->options[CURLOPT_WRITEFUNCTION])($h,$bytes); return true;
    }
    function curl_getinfo(object $h,int $option): mixed { return $option === CURLINFO_RESPONSE_CODE ? 200 : 'application/json'; }
    function curl_errno(object $h): int { return 0; }
}
namespace {
ob_start(); require __DIR__.'/photo-privacy.php'; ob_end_clean();
$nativeYaml=function_exists('yaml_parse');
if (!$nativeYaml) {
    $fixtures=json_decode(stream_get_contents(STDIN),true,128,JSON_THROW_ON_ERROR);
    if (!isset($fixtures['datenschutz.yaml'])) throw new RuntimeException('Use ruby tests/forms-data.rb | php tests/privacy-flow.php [tcpdf.php] [synthetic.pdf] [native-request.json]');
    function yaml_parse(string $source): mixed { return $GLOBALS['fixtures']['datenschutz.yaml']; }
}
if (!empty($argv[1])) require $argv[1];
Checkin\PrivacyPdf::dependencies();
$temp=sys_get_temp_dir().'/checkin154-privacy-'.bin2hex(random_bytes(10)); mkdir($temp,0700);
mkdir($temp.'/pending',0700); mkdir($temp.'/forms',0700);
try {
    $key=random_bytes(32); file_put_contents($temp.'/key',$key); chmod($temp.'/key',0600);
    $source=file_get_contents(dirname(__DIR__).'/fragebogenpi/_yaml/datenschutz.yaml');
    file_put_contents($temp.'/forms/datenschutz.yaml',$source);
    $data=Checkin\Toml::read(dirname(__DIR__).'/config.example.toml');
    $data['app']['state_dir']=$temp; $data['app']['secret_file']=$temp.'/key';
    $data['t2med']['doctor_role_id']=ref('a')['objectId']['id'];$data['t2med']['treatment_location_id']=ref('b')['objectId']['id'];
    $data['questionnaires']['forms_dir']=$temp.'/forms'; $data['questionnaires']['enabled']=false; $data['selfie']['enabled']=false;
    $config=new Checkin\Config($data);$loader=new Checkin\PrivacyForm($config);$form=$loader->load();
    check($form['version']==='1.3.4' && $form['hash']===hash('sha256',$source),'Template version and exact source hash');
    $tooNew=$data;$tooNew['privacy']['minimum_version']='1.5';
    fails('PRIVACY_TEMPLATE_OLD',fn()=>(new Checkin\PrivacyForm(new Checkin\Config($tooNew)))->load());
    copy($temp.'/forms/datenschutz.yaml',$temp.'/forms/dsgv.yaml');
    fails('PRIVACY_TEMPLATE',fn()=>$loader->load());unlink($temp.'/forms/dsgv.yaml');
    ini_set('session.use_cookies','0');session_cache_limiter('');session_save_path($temp);session_start();
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt('{"user":"TEST","password":""}','aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    $auth=['auth'=>base64_encode($iv.$tag.$cipher),'auth_started'=>time(),'auth_until'=>time()+3600,'csrf'=>'SYNTHETIC'];
    $_SESSION=$auth;
    $session=new Checkin\SessionStore($config);$flow=new Checkin\Flow($config,$session);
    $after=new ReflectionMethod(Checkin\Flow::class,'afterForms');
    function startPrivacy(bool $medical=false): array {
        $_SESSION['flow']=['id'=>bin2hex(random_bytes(16)),'stage'=>'photo_pending','checkin_complete'=>true,
            'patient'=>['ref'=>ref('c')],'category'=>'waiting','message'=>'Sie werden aufgerufen.',
            'forms'=>$medical ? [['token'=>'medical','key'=>'ana.yaml']] : [],'forms_seen'=>[],'last_activity'=>time()];
        $GLOBALS['after']->invoke($GLOBALS['flow'],new Checkin\T2med($GLOBALS['config'],$GLOBALS['fake']));
        return ['flowId'=>$_SESSION['flow']['id'],'formToken'=>$_SESSION['flow']['privacy']['token'] ?? ''];
    }
    $fake=new FakePrivacy($config,'','');
    $input=startPrivacy(true);
    check($flow->state()['stage']==='privacy' && isset($_SESSION['flow']['forms'][0]),'Privacy precedes medical forms');
    fails('FORM_TOKEN',fn()=>$flow->act('privacy_submit',array_replace($input,['formToken'=>'OLD'])+$answers,[]));
    fails('FLOW_ID',fn()=>$flow->act('privacy_submit',array_replace($input,['flowId'=>'OLD'])+$answers,[]));
    fails('PRIVACY_INPUT',fn()=>$flow->act('privacy_submit',$input+['email'=>false,'sms'=>false,'strokes'=>[]],[]));
    check($flow->state()['stage']==='privacy' && $fake->writes===0,'Invalid signature stays editable without writes');
    file_put_contents($temp.'/forms/datenschutz.yaml',$source."\n# changed\n");
    fails('PRIVACY_CHANGED',fn()=>$flow->act('privacy_submit',$input+$answers,[]));
    file_put_contents($temp.'/forms/datenschutz.yaml',$source);
    $state=$flow->act('privacy_submit',$input+$answers,[]);
    check($state['stage']==='questionnaire' && $fake->writes===1 && glob($temp.'/pending/*')===[],'Verified PDF, journal cleaned, then medical form');
    check($fake->emailAllowed===false && $fake->emailWrites===1 && $fake->rows[0]['symbol']==='SMS-erlaubt.png','Signed will applied after PDF verification');
    check(array_search('/praxis/verweis/dokumentverweis/update',$fake->calls)<array_search('/praxis/patient/detailsbearbeiten/aktualisiere/details',$fake->calls),'No consent before PDF write');
    check(str_contains($fake->doc['text'],'Formularversion: 1.3.4') && str_contains($fake->doc['text'],'E-Mail-Einwilligung: NEIN; SMS-Einwilligung: JA'),'Chart stores version and optional choices');
    check(str_starts_with($fake->pdf,'%PDF-') && strlen($fake->pdf)>1000,'Actual TCPDF generated');
    if (!empty($argv[2])) file_put_contents($argv[2],$fake->pdf);
    if (!empty($argv[3])) file_put_contents($argv[3],json_encode($fake->lastWrite,JSON_THROW_ON_ERROR));
    startPrivacy();check($flow->state()['stage']==='done' && !isset($_SESSION['flow']['patient']),'Adequate stored PDF skips prompt for existing patient');
    $fake=new FakePrivacy($config,'','');$input=startPrivacy();
    check($flow->state()['stage']==='privacy','Privacy works with medical questionnaires disabled');
    $state=$flow->act('privacy_decline',$input,[]);
    check($state['stage']==='done' && str_contains($state['message'],$config->get('privacy.declined_message')) && $fake->writes===0,'Decline stores no consent and preserves reception notice');
    check($_SESSION['auth']===$auth['auth'],'Staff session remains logged in');
    $input=startPrivacy();check($flow->state()['stage']==='privacy','Decline does not suppress next visit prompt');
    $_SESSION['flow']['last_activity']=time()-$config->get('privacy.timeout_seconds')-1;
    check($flow->state()['stage']==='idle' && !isset($_SESSION['flow']) && $fake->writes===0,'Idle timeout clears patient without PDF');
    $input=startPrivacy();$fake->seed('1.5');$before=$fake->writes;
    fails('PRIVACY_CHANGED',fn()=>$flow->act('privacy_submit',$input+$answers,[]));
    check($fake->writes===$before && $fake->emailWrites===0 && $fake->pinWrites===0,'Concurrent signed will is not silently discarded or overwritten');
    $fake=new FakePrivacy($config,'','');$input=startPrivacy();$fake->patientName='Changed';
    fails('PRIVACY_PATIENT_CHANGED',fn()=>$flow->act('privacy_submit',$input+$answers,[]));
    check($flow->state()['stage']==='blocked' && $fake->writes===0,'Changed patient name stops before write');
    $fake=new FakePrivacy($config,'','');$input=startPrivacy();$fake->reject=true;
    fails('REST_REJECTED',fn()=>$flow->act('privacy_submit',$input+$answers,[]));
    $state=$flow->state();$journal=$temp.'/pending/'.$input['formToken'].'.json.enc';
    check($state['stage']==='blocked' && $state['checkin_complete'] && $state['error_area']==='privacy' && is_file($journal),'Rejected PDF keeps check-in and encrypted recovery copy');
    check(!str_contains(file_get_contents($journal),'Erika') && !str_contains(file_get_contents($journal),'%PDF-'),'No plaintext patient or PDF in recovery file');
    fails('FLOW_BLOCKED',fn()=>$flow->act('privacy_submit',$input+$answers,[]));
    check($fake->writes===1,'No automatic retry of uncertain document');
    foreach(['rejectEmail','rejectPin'] as $failure) {
        $fake=new FakePrivacy($config,'','');$input=startPrivacy();$fake->$failure=true;
        fails('REST_REJECTED',fn()=>$flow->act('privacy_submit',$input+$answers,[]));
        $flow->state();$savedWrites=$fake->writes;
        check($flow->state()['error_diagnostic']['report_id']===$input['formToken'],'Partial consent exposes encrypted journal identifier');
        check(is_file($temp.'/pending/'.$input['formToken'].'.json.enc'),'Partial consent keeps encrypted journal');
        fails('PRIVACY_PENDING',fn()=>(new Checkin\T2med($config,$fake))->privacySufficient(ref('c'),'1.3.4'));
        $first=['ref'=>ref('a'),'fachinformationstypTO'=>['fachinformationstyp'=>75],'content'=>['text'=>"Datenschutz | Formularversion: 9.0\nCheck-in-Dokument: ".str_repeat('b',32)]];
        array_unshift($fake->rows,$first);
        fails('PRIVACY_PENDING',fn()=>(new Checkin\T2med($config,$fake))->privacySufficient(ref('c'),'1.3.4'));
        fails('FLOW_BLOCKED',fn()=>$flow->act('privacy_submit',$input+$answers,[]));
        check($fake->writes===$savedWrites,'No duplicate PDF on partial consent');
    }
    echo "$count photo/privacy/PDF/flow checks passed. YAML: ",$nativeYaml?'native':'Ruby/Psych adapter (target PHP-YAML not tested)',".\n";
} finally {
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
    foreach(['pending','forms','requests'] as $dir){if(!is_dir($temp.'/'.$dir))continue;foreach(glob($temp.'/'.$dir.'/*') as $file)if(is_file($file))unlink($file);rmdir($temp.'/'.$dir);}
    foreach(glob($temp.'/*') as $file)if(is_file($file))unlink($file);rmdir($temp);
}
}
