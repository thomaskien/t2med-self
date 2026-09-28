#!/usr/bin/env php
<?php
declare(strict_types=1);
ob_start(); require __DIR__ . '/photo-privacy.php'; ob_end_clean();
use Checkin\{AppError, Config, ConsentCheck, PrivacyForm, T2med, WriteJournal};
// Synthetic endpoint doubles only. No server, patient, credentials or real write.
$temp = sys_get_temp_dir() . '/checkin161-recovery-' . bin2hex(random_bytes(8));
mkdir($temp, 0700); mkdir($temp . '/pending', 0700);
file_put_contents($temp . '/key', random_bytes(32)); chmod($temp . '/key', 0600);
$data['app']['state_dir'] = $temp; $data['app']['secret_file'] = $temp . '/key';
$config = new Config($data);
class RecoveryFake extends FakePrivacy {
    public bool $normalize = false, $changeName = false, $lostPinReply = false;
    public bool $updated = false;
    public ?array $oldDoc = null;
    public function details(): array {
        $d = parent::details();
        if ($this->updated && $this->normalize) {
            $d['kontaktdatenDTO']['telefonnummern'][0]['ref'] = ref('a', 12);
            $d['kontaktdatenDTO']['telefonnummern'][0]['objectId'] = ref('a')['objectId'];
            $d['kontaktdatenDTO']['telefonnummern'][0]['revision'] = 12;
            $d['kontaktdatenDTO']['telefonnummern'][0]['validTelefonnummer'] = true;
            $d['adressdatenDTO']['postadresse']['ref'] = ref('b', 9);
            $d['adressdatenDTO']['postadresse']['validPostanschrift'] = true;
            $d['adressdatenDTO']['postfachadresse'] = new stdClass();
            $d['sichtbarFuerBenutzer'] = true;
        }
        if ($this->updated && $this->changeName) { $d['personendatenDTO']['namensdaten']['nachname'] = 'MustNotLogThisName'; }
        return $d;
    }
    public function request(string $method, string $path, mixed $body = null, bool $requireSuccess = true): array {
        if ($this->oldDoc && str_ends_with($path, '/dokumentationbearbeitenvorgang') && $body['objectId'] === ref('f')['objectId']) { return ['fachinformationRef' => ref('a')]; }
        if ($this->oldDoc && str_ends_with($path, '/dokumentverweis/find') && $body['dokumentverweisRef']['objectId'] === ref('a')['objectId']) { return ['dokumentverweisTO' => $this->oldDoc]; }
        $result = parent::request($method, $path, $body, $requireSuccess);
        if (str_ends_with($path, '/aktualisiere/details')) { $this->updated = true; }
        if ($this->lostPinReply && str_ends_with($path, '/symbolaendern')) { throw new AppError('REST_HTTP_502', 'Synthetic lost reply', 502); }
        return $result;
    }
}
function recoveryFixture(bool $sms = true, bool $disabled = false): array {
    global $config;
    $fake = new RecoveryFake($config, '', ''); $fake->emailAllowed = true; $fake->seed('1.3.4');
    $client = new T2med($config, $fake); $id = bin2hex(random_bytes(16));
    $plan = ['email' => true, 'sms' => $sms, 'previous_email' => false, 'pin' => $disabled ? '' : ($sms ? 'SMS-erlaubt.png' : 'SMS-nicht-erlaubt.png'), 'previous_pins' => []];
    $text = PrivacyForm::recordText('1.3.4', $id, str_repeat('a', 64), hash('sha256', $fake->pdf), $plan);
    if ($plan['pin'] !== '') { $text .= "\nSMS-Pin (Check-in): " . $plan['pin']; }
    $fake->doc['text'] = $fake->rows[0]['content']['text'] = $text;
    $payload = ['kind' => 'privacy', 'patient' => ref('c'), 'version' => '1.3.4', 'template_hash' => str_repeat('a', 64),
        'record_text' => $text, 'pdf_base64' => base64_encode($fake->pdf), 'consent' => $plan];
    $j = new WriteJournal($config, $id, $payload); $j->confirmed('pdf_verified'); unset($j);
    return [$fake, $client, $id];
}
function discardRecovery(string $id): void { global $temp; $path = $temp . '/pending/' . $id . '.json.enc'; if (is_file($path)) { unlink($path); } }
try {
    $fake = new RecoveryFake($config, '', ''); $fake->emailAllowed = false; $fake->normalize = true;
    $client = new T2med($config, $fake); $plan = $client->preparePrivacyConsent(ref('c'), ['email'=>true,'sms'=>true]);
    $fake->seed('1.3.4'); $id = bin2hex(random_bytes(16)); $j = new WriteJournal($config, $id, ['kind'=>'consent-test']);
    $before = $fake->details(); $before['kontaktdatenDTO']['benachrichtigungErlaubt'] = true;
    $client->savePrivacyConsent(ref('c'), $fake->rows[0], $plan, $j);
    check($before != $fake->details(), 'Raw DTO comparison would reject successful normalized write');
    check(ConsentCheck::differences($before, $fake->details()) === [], 'Known technical differences do not reject values');
    check($fake->emailAllowed && $fake->pinWrites === 1, 'Email verified then SMS pin reached'); $j->complete(); unset($j);
    foreach (['personendatenDTO','adressdatenDTO','kontaktdatenDTO','weitereDatenDTO'] as $group) {
        $changed = $before; $changed[$group]['UnexpectedPatientValue'] = 'MustNotLogThisValue';
        check(ConsentCheck::differences($before, $changed) === [$group], 'Unexpected fields fail closed with fixed group label');
    }
    $wrongType = $before; $wrongType['kontaktdatenDTO']['benachrichtigungErlaubt'] = 1;
    check(ConsentCheck::differences($before, $wrongType) === ['kontaktdatenDTO'], 'No loose boolean equality');
    $changed = $before; $changed['adressdatenDTO']['postadresse']['ort'] = 'Other place';
    check(ConsentCheck::differences($before, $changed) === ['adressdatenDTO'], 'Address content still guarded');
    $fake = new RecoveryFake($config,'',''); $fake->emailAllowed=false;$fake->changeName=true;
    $client = new T2med($config,$fake);$plan=$client->preparePrivacyConsent(ref('c'),['email'=>true,'sms'=>true]);$fake->seed('1.3.4');
    $id=bin2hex(random_bytes(16));$j=new WriteJournal($config,$id,['kind'=>'consent-test']);
    try { $client->savePrivacyConsent(ref('c'),$fake->rows[0],$plan,$j);throw new RuntimeException('Expected protected change'); }
    catch(AppError $e){check($e->tag==='CONSENT_DETAILS_CHANGED' && !str_contains(json_encode($e->diagnostic),'MustNotLog'),'Only fixed labels in error');}
    check($fake->pinWrites===0 && isset(WriteJournal::read($config,$id)['payload']['consent_observed']),'Side effect blocks pins and keeps encrypted evidence');
    unset($j);discardRecovery($id);

    foreach ([true,false] as $sms) {
        [$fake,$client,$id]=recoveryFixture($sms);$path=$temp.'/pending/'.$id.'.json.enc';$hash=hash_file('sha256',$path);
        $view=$client->inspectPrivacyRecovery(WriteJournal::read($config,$id));
        check($view['pin_missing'] && $view['legacy'] && hash_file('sha256',$path)===$hash,'Read-only check of old journal');
        check($fake->uploads===0 && $fake->writes===0 && $fake->emailWrites===0 && $fake->pinWrites===0,'No writes during check');
        $j=new WriteJournal($config,$id,null);fails('JOURNAL_BUSY',fn()=>new WriteJournal($config,$id,null));
        $client->completePrivacyRecovery($j);unset($j);
        check(!is_file($path) && $fake->pinWrites===1 && $fake->emailWrites===0 && $fake->writes===0 && $fake->uploads===0,'Only missing pin completed, no duplicate PDF or email');
        check($client->privacySufficient(ref('c'),'1.3.4'),'Pending no longer blocks after verified completion');
        fails('JOURNAL_READ',fn()=>new WriteJournal($config,$id,null));
    }
    [$fake,$client,$id]=recoveryFixture();$fake->lostPinReply=true;$j=new WriteJournal($config,$id,null);
    fails('REST_HTTP_502',fn()=>$client->completePrivacyRecovery($j));unset($j);
    check(is_file($temp.'/pending/'.$id.'.json.enc') && $fake->pinWrites===1,'Ambiguous write keeps journal');
    $fake->lostPinReply=false;$j=new WriteJournal($config,$id,null);$client->completePrivacyRecovery($j);unset($j);
    check($fake->pinWrites===1,'Explicit later retry reads matching pin, never resends it');
    [$fake,$client,$id]=recoveryFixture(true,true);$fake->rows[0]['symbol']='Other.png';$j=new WriteJournal($config,$id,null);
    $client->completePrivacyRecovery($j);unset($j);check($fake->pinWrites===0 && $fake->rows[0]['symbol']==='Other.png','Disabled SMS mapping leaves unrelated symbol');

    foreach (['email'=>'RECOVERY_EMAIL','pdf'=>'RECOVERY_DOCUMENT','owner'=>'PRIVACY_PATIENT','catalog'=>'SMS_PIN_MISSING',
        'foreign_pin'=>'RECOVERY_CONFLICT','another_document'=>'RECOVERY_CONFLICT','duplicate'=>'RECOVERY_DOCUMENT','protected'=>'CONSENT_DETAILS_CHANGED'] as $fault=>$code) {
        [$fake,$client,$id]=recoveryFixture();$j=new WriteJournal($config,$id,null);
        if($fault==='email')$fake->emailAllowed=false;
        if($fault==='pdf')$fake->corrupt=true;
        if($fault==='owner')$fake->badOwner=true;
        if($fault==='catalog')$fake->catalog=[];
        if($fault==='foreign_pin')$fake->rows[0]['symbol']='Other.png';
        if(in_array($fault,['another_document','duplicate'],true)){
            $row=$fake->rows[0];$row['ref']=ref('f');if($fault==='another_document')$row['content']['text']=str_replace($id,str_repeat('a',32),$row['content']['text']);$fake->rows[]=$row;
        }
        if($fault==='protected'){ $expected=$fake->details();$expected['weitereDatenDTO']['chroniker']=false;$j->annotate('consent_expected',$expected); }
        fails($code,fn()=>$client->completePrivacyRecovery($j));
        check($fake->pinWrites===0 && $fake->emailWrites===0 && is_file($temp.'/pending/'.$id.'.json.enc'),'Conflict stops without write/cleanup');
        unset($j);discardRecovery($id);
    }
    [$fake,$client,$id]=recoveryFixture();$second=bin2hex(random_bytes(16));$extra=new WriteJournal($config,$second,WriteJournal::read($config,$id)['payload']);unset($extra);
    fails('RECOVERY_CONFLICT',fn()=>$client->inspectPrivacyRecovery(WriteJournal::read($config,$id)));discardRecovery($second);discardRecovery($id);

    // Previously app-owned pin cleanup, never historical PDF/text removal.
    [$fake,$client,$id]=recoveryFixture(false);$j=new WriteJournal($config,$id,null);
    $oldText=PrivacyForm::recordText('1.0',str_repeat('b',32),str_repeat('c',64),hash('sha256',$fake->pdf),['email'=>true,'sms'=>true])."\nSMS-Pin (Check-in): SMS-erlaubt.png";
    $old=['ref'=>ref('f'),'fachinformationstypTO'=>['fachinformationstyp'=>75],'content'=>['text'=>$oldText],'symbol'=>'SMS-erlaubt.png'];
    $fake->rows[]=$old;$fake->oldDoc=['ref'=>ref('a'),'fachinformationstyp'=>75,'text'=>$oldText,'verweis'=>'cdn://old'];
    $plan=$j->data()['payload']['consent'];$plan['previous_pins']=[$old];$j->annotate('consent',$plan);
    $client->completePrivacyRecovery($j);unset($j);
    check($fake->pinWrites===2 && $fake->rows[1]['symbol']===null && $fake->rows[1]['content']['text']===$oldText,'Old managed pin cleared, old document unchanged');

    [$fake,$client,$id]=recoveryFixture();$j=new WriteJournal($config,$id,null);$path=$temp.'/pending/'.$id.'.json.enc';
    $original=file_get_contents($path);file_put_contents($path,$original.'x');fails('JOURNAL_CHANGED',fn()=>$j->complete());
    fails('JOURNAL_READ',fn()=>WriteJournal::read($config,$id));unset($j);discardRecovery($id);
    echo "$count focused consent/recovery checks passed (offline).\n";
} finally {
    unset($j);
    foreach(['pending','requests'] as $dir){if(!is_dir($temp.'/'.$dir))continue;foreach(glob($temp.'/'.$dir.'/*') as $file)unlink($file);rmdir($temp.'/'.$dir);}
    unlink($temp.'/key');rmdir($temp);
}
