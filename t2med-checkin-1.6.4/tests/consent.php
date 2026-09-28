#!/usr/bin/env php
<?php
declare(strict_types=1);
ob_start(); require __DIR__ . '/photo-privacy.php'; ob_end_clean();
// Offline only: synthetic full DTOs and chart entries, encrypted temporary journals.
$temp = sys_get_temp_dir() . '/checkin157-consent-' . bin2hex(random_bytes(8));
mkdir($temp, 0700); mkdir($temp . '/pending', 0700);
file_put_contents($temp . '/key', random_bytes(32)); chmod($temp . '/key', 0600);
$data['app']['state_dir'] = $temp; $data['app']['secret_file'] = $temp . '/key';
$config = new Checkin\Config($data);
function journal(array $plan): Checkin\WriteJournal {
    return new Checkin\WriteJournal($GLOBALS['config'], bin2hex(random_bytes(16)), ['kind'=>'consent-test', 'consent'=>$plan]);
}
function prepared(?bool $old, bool $email, bool $sms): array {
    $fake = new FakePrivacy($GLOBALS['config'], '', ''); $fake->emailAllowed = $old;
    $client = new Checkin\T2med($GLOBALS['config'], $fake);
    $plan = $client->preparePrivacyConsent(ref('c'), ['email'=>$email, 'sms'=>$sms]);
    $fake->seed('1.3.4');
    return [$fake, $client, $plan, $fake->rows[0]];
}
try {
    $nativeRequests=[];
    foreach ([true, false] as $email) foreach ([true, false] as $sms) {
        [$fake,$client,$plan,$row] = prepared(!$email, $email, $sms);
        $j=journal($plan);$client->savePrivacyConsent(ref('c'),$row,$plan,$j);$j->complete();
        $nativeRequests[]=['email'=>$fake->lastConsentWrite,'pin'=>$fake->lastPinWrite];
        check($fake->emailAllowed===$email && $fake->emailWrites===1,'Current email will replaces either previous value');
        check($fake->rows[0]['symbol']===($sms?'SMS-erlaubt.png':'SMS-nicht-erlaubt.png'),'Independent SMS yes/no mapping');
    }
    [$fake,$client,$plan,$row]=prepared(true,true,true);
    $j=journal($plan);$client->savePrivacyConsent(ref('c'),$row,$plan,$j);$j->complete();
    check($fake->emailWrites===0,'Already matching email needs no write');
    foreach (['rejectEmail'=>'REST_REJECTED','ignoreEmail'=>'CONSENT_VERIFY','rejectPin'=>'REST_REJECTED','ignorePin'=>'SMS_PIN_VERIFY'] as $fault=>$code) {
        [$fake,$client,$plan,$row]=prepared(false,true,true);$fake->$fault=true;
        fails($code,fn()=>$client->savePrivacyConsent(ref('c'),$row,$plan,journal($plan)));
        check($fake->emailWrites===1 && $fake->pinWrites<=1,'No automatic repeat on rejected or unconfirmed consent');
    }
    [$fake,$client,$plan,$row]=prepared(false,true,true);$fake->emailAllowed=true;
    fails('CONSENT_CHANGED',fn()=>$client->savePrivacyConsent(ref('c'),$row,$plan,journal($plan)));
    check($fake->emailWrites===0 && $fake->pinWrites===0,'Concurrent consent change not overwritten');
    [$fake,$client,$plan,$row]=prepared(true,true,true);$fake->rows[0]['symbol']='Adipositas.png';
    fails('SMS_PIN_CHANGED',fn()=>$client->savePrivacyConsent(ref('c'),$row,$plan,journal($plan)));
    check($fake->pinWrites===0,'Unrelated pin never overwritten');
    [$fake,$client,$plan,$row]=prepared(true,true,true);$fake->badOwner=true;
    fails('SMS_PIN_CHANGED',fn()=>$client->savePrivacyConsent(ref('c'),$row,$plan,journal($plan)));
    check($fake->pinWrites===0,'Wrong patient row never changed');
    [$fake,$client,$plan,$row]=prepared(false,true,true);$fake->wrongDetails=true;
    fails('CONSENT_PATIENT',fn()=>$client->savePrivacyConsent(ref('c'),$row,$plan,journal($plan)));
    check($fake->emailWrites===0,'Wrong patient details never sent');
    [$fake,$client,$plan,$row]=prepared(false,true,true);$fake->catalog=[];
    fails('SMS_PIN_MISSING',fn()=>$client->preparePrivacyConsent(ref('c'),['email'=>true,'sms'=>true]));
    check($fake->writes===0 && $fake->emailWrites===0 && $fake->pinWrites===0,'Missing catalog fails before writes');

    // Replace only previously marked app pins, not arbitrary same-coloured chart pins.
    $fake=new FakePrivacy($config,'','');$fake->emailAllowed=false;$fake->seed('1.0');
    $fake->rows[0]['ref']=ref('f');$fake->rows[0]['symbol']='SMS-erlaubt.png';
    $fake->doc['text'].="\nSMS-Pin (Check-in): SMS-erlaubt.png";
    $fake->rows[0]['content']['text']=$fake->doc['text'];$old=$fake->rows[0];
    $unrelated=['ref'=>ref('b'),'fachinformationstypTO'=>['fachinformationstyp'=>1],'symbol'=>'SMS-erlaubt.png','content'=>['text'=>'Unrelated']];
    $fake->rows[]=$unrelated;$client=new Checkin\T2med($config,$fake);
    $plan=$client->preparePrivacyConsent(ref('c'),['email'=>false,'sms'=>false]);
    check(count($plan['previous_pins'])===1,'Only app-owned privacy document included in cleanup');
    $fake->seed('1.3.4');$row=$fake->rows[0];$fake->rows[]=$old;$fake->rows[]=$unrelated;
    $j=journal($plan);$client->savePrivacyConsent(ref('c'),$row,$plan,$j);$j->complete();
    check($fake->rows[0]['symbol']==='SMS-nicht-erlaubt.png' && $fake->rows[1]['symbol']===null,'Old positive app pin replaced by current negative pin');
    check($fake->rows[1]['content']===$old['content'] && $fake->rows[2]===$unrelated,'Historical document and unrelated entry unchanged');

    $custom=$data;$custom['privacy']['sms_pin_allowed']='Eigene-SMS.png';$custom['privacy']['sms_pin_denied']='';
    $customConfig=new Checkin\Config($custom);$fake=new FakePrivacy($customConfig,'','');$fake->catalog=['Eigene-SMS.png'];
    $client=new Checkin\T2med($customConfig,$fake);
    check($client->preparePrivacyConsent(ref('c'),['email'=>true,'sms'=>true])['pin']==='Eigene-SMS.png','Custom configured symbol');
    $plan=$client->preparePrivacyConsent(ref('c'),['email'=>false,'sms'=>false]);$fake->seed('1.3.4');
    $j=journal($plan);$client->savePrivacyConsent(ref('c'),$fake->rows[0],$plan,$j);$j->complete();
    check($fake->emailAllowed===false && $fake->pinWrites===0,'Empty mapping disables only respective SMS pin, not email');
    foreach (['../evil.png','bad/name.png','ä.png','bad.png?x','https://x.png'] as $bad) {
        $invalid=$data;$invalid['privacy']['sms_pin_allowed']=$bad;
        fails('CONFIG_SMS_PIN',fn()=>new Checkin\Config($invalid));
    }
    $invalid=$data;$invalid['privacy']['sms_pin_denied']=$invalid['privacy']['sms_pin_allowed'];
    fails('CONFIG_SMS_PIN',fn()=>new Checkin\Config($invalid));
    if (!empty($argv[1])) file_put_contents($argv[1],json_encode($nativeRequests,JSON_THROW_ON_ERROR));
    echo "$count focused photo/privacy/consent checks passed (offline).\n";
} finally {
    foreach(glob($temp.'/pending/*') as $file) unlink($file);
    rmdir($temp.'/pending');unlink($temp.'/key');rmdir($temp);
}
