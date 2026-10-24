<?php
function page_top($title, $active='') {
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($title); ?> · MĚŘENÍ PRO</title>
<link rel="stylesheet" href="style.css?v=6.3.26">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><span class="mark">M</span><span>MĚŘENÍ <b>PRO</b></span></a>
  <nav>
    <a class="<?php echo $active==='home'?'active':''; ?>" href="index.php">Přehled</a>
    <a class="<?php echo $active==='customers'?'active':''; ?>" href="customers.php">Zákazníci</a>
    <a class="<?php echo $active==='jobs'?'active':''; ?>" href="jobs.php">Zakázky</a>
    <a class="<?php echo $active==='reports'?'active':''; ?>" href="reports.php">Protokoly</a>
    <?php if(is_admin()): ?><a class="<?php echo $active==='users'?'active':''; ?>" href="users.php">Uživatelé</a>
    <a class="<?php echo $active==='updater'?'active':''; ?>" href="mp-auto-updater.php">Aktualizace</a><?php endif; ?>
    <a href="logout.php">Odhlásit</a>
  </nav>
</header>
<main class="wrap">
<?php
}
function page_bottom() {
?>
</main>
<footer class="footer">MĚŘENÍ PRO · verze 6.3.26</footer>
</body></html>
<?php } ?>

