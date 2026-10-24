<?php
function ensure_user_invite_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='mp_users'");
    $st->execute(array($db));
    $cols = array();
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $cols[$c] = true;
    $changes = array(
        'email' => "ADD COLUMN email VARCHAR(190) NULL AFTER username",
        'invite_token_hash' => "ADD COLUMN invite_token_hash CHAR(64) NULL",
        'invite_expires_at' => "ADD COLUMN invite_expires_at DATETIME NULL",
        'invited_at' => "ADD COLUMN invited_at DATETIME NULL",
        'activated_at' => "ADD COLUMN activated_at DATETIME NULL"
    );
    foreach ($changes as $name=>$sql) {
        if (!isset($cols[$name])) $pdo->exec("ALTER TABLE mp_users ".$sql);
    }
    $idx = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='mp_users' AND INDEX_NAME='uq_mp_users_email'");
    $idx->execute(array($db));
    if (!(int)$idx->fetchColumn()) $pdo->exec("ALTER TABLE mp_users ADD UNIQUE KEY uq_mp_users_email (email)");
}
function mp_invite_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO']==='https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST']) : 'zpevak.com';
    $dir = rtrim(str_replace('\\','/',dirname(isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'/')), '/');
    return $scheme.'://'.$host.($dir && $dir!=='.' ? $dir : '');
}
function create_invitation($pdo, $userId) {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $st=$pdo->prepare("UPDATE mp_users SET invite_token_hash=?, invite_expires_at=DATE_ADD(NOW(), INTERVAL 48 HOUR), invited_at=NOW(), activated_at=NULL, is_active=0 WHERE id=?");
    $st->execute(array($hash,$userId));
    return $token;
}
function send_user_invitation($email, $username, $displayName, $token) {
    $url = mp_invite_base_url().'/activate-account.php?token='.rawurlencode($token);
    $subject = 'Pozvánka do MĚŘENÍ PRO';
    $name = $displayName !== '' ? $displayName : $username;
    $body = "Dobrý den ".$name.",\n\nbyl Vám vytvořen účet do aplikace MĚŘENÍ PRO.\n\nUživatelské jméno: ".$username."\n\nHeslo si nastavíte pomocí tohoto jednorázového odkazu (platnost 48 hodin):\n".$url."\n\nPokud jste pozvánku neočekával(a), zprávu ignorujte.\n";
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/','',$_SERVER['HTTP_HOST']) : 'zpevak.com';
    $headers = array('MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','From: MERENI PRO <noreply@'.$host.'>');
    return @mail($email, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, implode("\r\n",$headers));
}
?>