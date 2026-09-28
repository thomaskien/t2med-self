#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } $GLOBALS['count']++; }
function ref(string $letter, int $revision = 1): array { return ['objectId' => ['id' => str_repeat($letter, 32)], 'revision' => $revision]; }
function fails(string $tag, callable $fn): void {
    try { $fn(); } catch (Checkin\AppError $error) { check($error->tag === $tag, "Expected $tag; got {$error->tag}"); return; }
    throw new RuntimeException('Expected ' . $tag);
}
$count = 0;
$data = Checkin\Toml::read(dirname(__DIR__) . '/config.example.toml');
$data['t2med']['doctor_role_id'] = ref('a')['objectId']['id']; $data['t2med']['treatment_location_id'] = ref('b')['objectId']['id'];
$config = new Checkin\Config($data);
class FakePhoto extends Checkin\RestClient {
    public int $revision = 20, $uploads = 0, $writes = 0;
    public bool $exists = false, $concurrentPhoto = false, $wrongPatient = false, $hidden = false, $reject = false;
    public function request(string $method, string $path, mixed $body = null, bool $requireSuccess = true): array {
        if (str_ends_with($path, 'arztrollenbehandlungorte')) return ['arztrollen' => [['ref' => ref('a')]], 'behandlungsorte' => [['ref' => ref('b')]]];
        if (str_ends_with($path, '/lesedaten')) return ['patientenbild' => ['sichtbar' => !$this->hidden,
            'patientRef' => ref($this->wrongPatient ? 'd' : 'c', $this->revision), 'patientenbild' => $this->exists ? 'cdn://photo' : null]];
        if (str_ends_with($path, '/upload/token')) return ['uploadToken' => 'cdn://synthetic-token'];
        if (str_ends_with($path, '/upload/passbild')) {
            $this->writes++; check($body['patientRef'] === ref('c', $this->revision), 'Photo save uses most recent revision, not initial ref');
            if ($this->reject) throw new Checkin\AppError('REST_REJECTED', 'synthetic conflict', 502);
            $this->exists = true; return ['successful' => true];
        }
        throw new RuntimeException('Unexpected endpoint ' . $path);
    }
    public function upload(string $token, string $jpeg): void { $this->uploads++; $this->revision++; $this->exists = $this->concurrentPhoto; }
}
$fake = new FakePhoto($config, '', ''); $client = new Checkin\T2med($config, $fake);
$client->uploadPhoto(ref('c', 1), 'synthetic'); check($fake->writes === 1, 'Stale initial revision does not prevent photo save');
$client->uploadPhoto(ref('c', 1), 'synthetic'); check($fake->writes === 1 && $fake->uploads === 1, 'Existing photo not overwritten');
$fake = new FakePhoto($config, '', ''); $fake->concurrentPhoto = true;
(new Checkin\T2med($config, $fake))->uploadPhoto(ref('c'), 'synthetic'); check($fake->writes === 0, 'Concurrent photo not overwritten');
$fake = new FakePhoto($config, '', ''); $fake->wrongPatient = true;
fails('PHOTO_PATIENT', fn() => (new Checkin\T2med($config, $fake))->uploadPhoto(ref('c'), 'synthetic')); check($fake->uploads === 0, 'Wrong patient stops before upload');
$fake = new FakePhoto($config, '', ''); $fake->hidden = true;
fails('PHOTO_HIDDEN', fn() => (new Checkin\T2med($config, $fake))->uploadPhoto(ref('c'), 'synthetic'));
$fake = new FakePhoto($config, '', ''); $fake->reject = true;
fails('REST_REJECTED', fn() => (new Checkin\T2med($config, $fake))->uploadPhoto(ref('c'), 'synthetic')); check($fake->writes === 1, 'Rejected photo is not retried');

foreach (['1.5', ' 1.5 '] as $version) check(Checkin\PrivacyForm::minimum($version) === '1.5', 'minimum is a plain version');
check(Checkin\PrivacyForm::sufficient('1.10', '1.5'), 'Numeric minor order');
check(Checkin\PrivacyForm::sufficient('1.5.0', '1.5'), 'Missing patch equals zero');
check(!Checkin\PrivacyForm::sufficient('1.4.99', '1.5'), 'Old version insufficient');
foreach (['1.5-test', '1', 'ge 1.5', '>= 1.5', '1.05', '99999.0'] as $bad) fails('PRIVACY_VERSION', fn() => Checkin\PrivacyForm::minimum($bad));
$answers = ['email' => false, 'sms' => true, 'strokes' => [[[0.1,0.5],[0.2,0.1],[0.3,0.8],[0.4,0.2],[0.5,0.5]]]];
check(Checkin\PrivacyForm::answers($answers) === $answers, 'Optional email may stay no');
fails('PRIVACY_INPUT', fn() => Checkin\PrivacyForm::answers(array_replace($answers, ['strokes' => []])));
fails('PRIVACY_INPUT', fn() => Checkin\PrivacyForm::answers(array_replace($answers, ['email' => 'yes'])));
fails('PRIVACY_INPUT', fn() => Checkin\PrivacyForm::answers(array_replace($answers, ['strokes' => [[[INF,0]]]])));

class FakePrivacy extends Checkin\RestClient {
    public array $rows = [], $doc = [], $calls = [], $lastWrite = [];
    public string $patientName = 'Testperson';
    public int $lock = 0, $uploads = 0, $writes = 0;
    public bool $badOwner = false, $corrupt = false, $reject = false, $badMapping = false;
    public string $pdf = '%PDF-synthetic';
    public ?bool $emailAllowed = null;
    public int $emailWrites = 0, $pinWrites = 0, $patientRevision = 30;
    public bool $rejectEmail = false, $ignoreEmail = false, $ignorePin = false, $rejectPin = false, $wrongDetails = false;
    public array $catalog = ['SMS-erlaubt.png', 'SMS-nicht-erlaubt.png'];
    public array $lastConsentWrite = [], $lastPinWrite = [];
    public function details(): array {
        return ['patientRef'=>ref($this->wrongDetails ? 'f' : 'c',$this->patientRevision),
            'personendatenDTO'=>['namensdaten'=>['vorname'=>'Erika','nachname'=>$this->patientName], 'geburtsdaten'=>['geburtsdatum'=>null], 'geschlecht'=>'WEIBLICH'],
            'adressdatenDTO'=>['postadresse'=>['strasse'=>'Teststraße','hausnummer'=>'12','plz'=>'12345','ort'=>'Testort'], 'postfachadresse'=>null],
            'kontaktdatenDTO'=>['telefonnummern'=>[['nummer'=>'0123456789','typ'=>1]], 'emailAdressen'=>[['emailadresse'=>'test@example.invalid']],
                'benachrichtigungErlaubt'=>$this->emailAllowed, 'bevorzugterBenachrichtigungsweg'=>'BRIEF'],
            'weitereDatenDTO'=>['patientennummer'=>123456,'chroniker'=>true,'sequenzVorlage'=>null], 'sichtbarFuerBenutzer'=>false];
    }
    public function seed(string $version): void {
        $text = Checkin\PrivacyForm::recordText($version, str_repeat('e',32), str_repeat('f',64), hash('sha256',$this->pdf), ['email'=>false,'sms'=>false]);
        $this->rows = [['ref'=>ref('d'),'fachinformationstypTO'=>['fachinformationstyp'=>75],'content'=>['text'=>$text]]];
        $this->doc = ['ref'=>ref('e'),'fachinformationstyp'=>75,'text'=>$text,'verweis'=>'cdn://APS/Praxis/Patient/synthetic'];
    }
    public function request(string $method, string $path, mixed $body = null, bool $requireSuccess = true): array {
        $this->calls[] = $path;
        if (str_ends_with($path, 'arztrollenbehandlungorte')) return ['arztrollen' => [['ref' => ref('a')]], 'behandlungsorte' => [['ref' => ref('b')]]];
        if (str_ends_with($path, '/find/details')) return ['details'=>$this->details()];
        if (str_ends_with($path, '/aktualisiere/details')) {
            $this->lastConsentWrite=$body;
            $this->emailWrites++;
            $expected=$this->details();$expected['kontaktdatenDTO']['benachrichtigungErlaubt']=$body['details']['kontaktdatenDTO']['benachrichtigungErlaubt'];
            check($body['details'] === $expected, 'Full fresh DTO unchanged except notification consent');
            check($body['kontext']['behandlungsfallRef'] === null && $body['kontext']['patientRef'] === $expected['patientRef'],'Consent uses fresh reference without case');
            if($this->rejectEmail) throw new Checkin\AppError('REST_REJECTED','Synthetic email refusal',502);
            if(!$this->ignoreEmail) $this->emailAllowed=$expected['kontaktdatenDTO']['benachrichtigungErlaubt'];
            $this->patientRevision++; return ['details'=>$this->details()];
        }
        if (str_ends_with($path, '/allestandardsymbolnamen')) return $this->catalog;
        if (str_ends_with($path, '/symbolaendern')) {
            $this->lastPinWrite=$body;
            $this->pinWrites++;
            if($this->rejectPin) throw new Checkin\AppError('REST_REJECTED','Synthetic pin refusal',502);
            foreach($this->rows as &$row) if($row['ref']['objectId']===$body['karteieintragRef']['objectId']) {
                check($body['karteieintragRef']===$row['ref'],'Pin uses chart reference, not document reference');
                if(!$this->ignorePin) $row['symbol']=$body['symbol'];
            }
            unset($row);return ['successful'=>true];
        }
        if (str_ends_with($path, '/gesperrt')) return ['successful'=>true,'sperrungPatientStatusTyp'=>$this->lock];
        if (str_ends_with($path, '/karteikarte/all')) { check($body['kontext']['patientRef']['objectId'] === ref('c')['objectId'],'Record request scoped to patient'); return $this->rows; }
        if (str_ends_with($path,'/byids')) return $this->badOwner ? [] : array_values(array_filter($this->rows,fn($r)=>in_array($r['ref']['objectId'],$body,true)));
        if (str_ends_with($path,'/dokumentationbearbeitenvorgang')) return ['fachinformationRef'=>ref($this->badMapping?'f':'e')];
        if (str_ends_with($path,'/dokumentverweis/find')) return ['dokumentverweisTO'=>$this->doc];
        if (str_ends_with($path,'/upload/token')) return ['uploadToken'=>'cdn://synthetic-token'];
        if (str_ends_with($path,'/dokumentverweis/update')) {
            $this->lastWrite = $body;
            $this->writes++;
            check($body['neuerEintrag'] === true && $body['kontext']['behandlungsfallRef'] === null,'Append document without case creation');
            if ($this->reject) throw new Checkin\AppError('REST_REJECTED','synthetic rejected write',502);
            $this->doc=$body['dokumentverweis'];$this->doc['ref']=ref('e');$this->doc['verweis']='cdn://APS/Praxis/Patient/synthetic';
            $this->rows=[['ref'=>ref('d'),'fachinformationstypTO'=>['fachinformationstyp'=>75],'content'=>['text'=>$this->doc['text']]]];
            return ['dokumentverweis'=>$this->doc];
        }
        throw new RuntimeException('Unexpected endpoint ' . $path);
    }
    public function uploadPdf(string $token, string $pdf, string $id): void { $this->uploads++; $this->pdf=$pdf; }
    public function documentBytes(string $contentPath): string { return $this->corrupt ? '%PDF-corrupt' : $this->pdf; }
}
$fake = new FakePrivacy($config,'','');$client=new Checkin\T2med($config,$fake);
check(!$client->privacySufficient(ref('c'),'1.5'),'No form requests privacy');
$fake->seed('1.4');check(!$client->privacySufficient(ref('c'),'1.5'),'Old document requests privacy');
$fake->seed('1.10');check($client->privacySufficient(ref('c'),'1.5'),'Newer document sufficient');
$fake->lock=4;check($client->privacySufficient(ref('c'),'1.5'),'Explicitly non-blocked user status accepted');
foreach ([1,2,3,99] as $lock) {$fake->lock=$lock;fails('PRIVACY_RECORD_ACCESS',fn()=>$client->privacySufficient(ref('c'),'1.5'));}
$fake->lock=0;$fake->badOwner=true;fails('PRIVACY_PATIENT',fn()=>$client->privacySufficient(ref('c'),'1.5'));$fake->badOwner=false;
$fake->badMapping=true;fails('PRIVACY_VERIFY',fn()=>$client->privacySufficient(ref('c'),'1.5'));$fake->badMapping=false;
$fake->corrupt=true;fails('PRIVACY_VERIFY',fn()=>$client->privacySufficient(ref('c'),'1.5'));$fake->corrupt=false;
$fake->rows[0]['fachinformationstypTO']['fachinformationstyp']=99;check(!$client->privacySufficient(ref('c'),'1.5'),'N text alone is not a document');
$fake->seed('1.5');$fake->rows[0]['content']['text']='Datenschutz ohne Version';check(!$client->privacySufficient(ref('c'),'1.5'),'Unknown legacy version insufficient');
$text=Checkin\PrivacyForm::recordText('1.5',str_repeat('e',32),str_repeat('f',64),hash('sha256','%PDF-new'),$answers);
$client->savePrivacyDocument(ref('c'),'%PDF-new',$text,str_repeat('e',32));check($fake->writes===1 && $fake->uploads===1,'One verified document saved');
$fake->reject=true;fails('REST_REJECTED',fn()=>$client->savePrivacyDocument(ref('c'),'%PDF-new',$text,str_repeat('e',32)));check($fake->writes===2,'No retry on document rejection');
echo "$count focused photo/privacy checks passed (offline).\n";
