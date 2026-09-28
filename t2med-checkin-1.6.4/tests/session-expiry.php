#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Checkin { function time(): int { return $GLOBALS['testNow']; } }
namespace {
    require dirname(__DIR__) . '/src/bootstrap.php';
    // Synthetic in-memory sessions and controlled clock. No login, server or filesystem writes.
    function expiryCheck(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
    $start = 1800000000; $GLOBALS['testNow'] = $start;
    $defaults = (new Checkin\Config(dirname(__DIR__) . '/config.example.toml', false))->data;
    foreach ([1,4,12,18,24] as $hours) {
        $data=$defaults; $data['app']['session_hours']=$hours;
        $session=new Checkin\SessionStore(new Checkin\Config($data,false));
        $_SESSION=['auth'=>'SYNTHETIC','auth_started'=>$start,'auth_until'=>$start+$hours*3600];
        $limit=$start+min(12,$hours)*3600;
        $GLOBALS['testNow']=$limit-1;expiryCheck($session->authenticated(),'One second before limit');
        $_SESSION['flow']=['last_activity'=>$GLOBALS['testNow']];
        $GLOBALS['testNow']=$limit;expiryCheck(!$session->authenticated(),'Exact absolute limit; activity must not extend it');
        $GLOBALS['testNow']=$limit+1;expiryCheck(!$session->authenticated(),'Expired stays expired');
        try { $session->client(); throw new RuntimeException('Expired session created REST client'); }
        catch (Checkin\AppError $e) { expiryCheck($e->tag==='LOGIN_REQUIRED','Expiry is an authentication error'); }
    }
    $session=new Checkin\SessionStore(new Checkin\Config($defaults,false));$GLOBALS['testNow']=$start;
    $_SESSION=['auth'=>'SYNTHETIC','auth_until'=>$start+3600];
    expiryCheck(!$session->authenticated(),'Legacy sessions require one new login');
    $_SESSION['auth_started']=$start+60;expiryCheck(!$session->authenticated(),'Future login timestamp is invalid');
    $_SESSION['auth_started']=$start;$_SESSION['auth_until']=$start;expiryCheck(!$session->authenticated(),'Earlier stored expiry is binding');
    $_SESSION=['auth'=>'SYNTHETIC','auth_started'=>$start-5*3600,'auth_until'=>$start+7*3600];
    $data=$defaults;$data['app']['session_hours']=4;
    expiryCheck(!(new Checkin\SessionStore(new Checkin\Config($data,false)))->authenticated(),'Shorter config also limits existing new-format sessions');
    echo "OK: Absolute session expiry at 1/4/12 hours, legacy 18/24 capped, no sliding expiry, new login required for old format.\n";
}
