<?php
require __DIR__.'/db.php';
require __DIR__.'/user-invite-lib.php';
ensure_user_invite_schema($pdo);
if (!empty($_SESSION['user_id'])) redirect_to('index.php');
$token=trim(isset($_GET['token'])?$_GET['token']:(isset($_POST['token'])?$_POST['token']:''));
$error=''; $success=false; $user=null;
if($token!==''){
    $hash=hash('sha256',$token);
    $st=$pdo->prepare("SELECT * FROM mp_users WHERE invite_token_hash=? AND invite_expires_at>=NOW() LIMIT 1");
    $st->execute(array($hash)); $user=$st->fetch();
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    $p1=(string)(isset($_POST['password'])?$_POST['password']:'');
    $p2=(string)(isset($_POST['password2'])?$_POST['password2']:'');
    if(!$user) $error='Pozvánka je neplatná nebo její platnost vypršela.';
    elseif(strlen($p1)<10) $error='Heslo musí mít alespoň 10 znaků.';
    elseif($p1!==$p2) $error='Zadaná hesla se neshodují.';
    else{
        $st=$pdo->prepare("UPDATE mp_users SET password_hash=?,is_active=1,invite_token_hash=NULL,invite_expires_at=NULL,activated_at=NOW() WHERE id=? AND invite_token_hash=?");
        $st->execute(array(password_hash($p1,PASSWORD_DEFAULT),(int)$user['id'],hash('sha256',$token)));
        $success=true;
    }
}
?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aktivace účtu · MĚŘENÍ PRO</title><link rel="stylesheet" href="style.css?v=6.3.25"></head><body class="login-body"><main class="login-card"><div class="protocol-brand"><span class="mark">M</span><div><b>MĚŘENÍ <em>PRO</em></b><small>AKTIVACE ÚČTU</small></div></div>
<?php if($success): ?><h1>Účet je aktivní</h1><div class="notice">Heslo bylo nastaveno. Nyní se můžete přihlásit.</div><p><a class="btn" href="login.php">Přejít na přihlášení</a></p>
<?php elseif(!$user): ?><h1>Pozvánka není platná</h1><div class="login-error">Odkaz je neplatný nebo jeho platnost vypršela. Požádejte administrátora o novou pozvánku.</div>
<?php else: ?><h1>Nastavení hesla</h1><p class="muted">Účet: <b><?php echo h($user['username']); ?></b><?php if(!empty($user['email'])): ?> · <?php echo h($user['email']); ?><?php endif; ?></p><?php if($error): ?><div class="login-error"><?php echo h($error); ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="token" value="<?php echo h($token); ?>"><label>Nové heslo</label><input name="password" type="password" minlength="10" required autocomplete="new-password"><label>Heslo znovu</label><input name="password2" type="password" minlength="10" required autocomplete="new-password"><button class="btn" type="submit">Nastavit heslo a aktivovat účet</button></form><?php endif; ?></main></body></html>