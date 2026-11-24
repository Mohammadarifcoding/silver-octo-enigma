<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow"><title>Admin sign in — KPI</title>
  <link rel="icon" href="/assets/img/kpi-logo.png"><link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="login-intro">
      <a class="login-brand" href="/"><img src="/assets/img/kpi-logo.png" width="64" height="64" alt="Khulna Polytechnic Institute"></a>
      <span class="overline">Khulna Polytechnic Institute</span>
      <h1>A little less admin.<br>More room to teach.</h1>
      <p>Your workspace for college enquiries and department information.</p>
      <div class="intro-foot">Established 1963 <span>College administration</span></div>
    </section>
    <section class="login-form-panel">
      <div class="login-form-wrap"><div class="login-lock"><?= icon('lock') ?></div>
      <span class="overline">Admin portal</span><h2>Welcome back</h2><p class="muted">Enter your credentials to continue.</p>
      <?php if ($flash): ?><div class="notice" role="status"><?= e($flash) ?></div><?php endif ?>
      <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif ?>
      <form method="post" action="/admin/login">
        <?= csrf_field() ?>
        <label for="username">Username</label><input id="username" name="username" autocomplete="username" value="<?= e(text_value($_POST, 'username')) ?>" required maxlength="60" autofocus>
        <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="72">
        <button class="button primary full" type="submit">Sign in</button>
      </form>
      <p class="login-note"><?= icon('lock') ?> Only authorised college administrators can sign in.</p>
      <a class="text-link" href="/">Back to college website</a></div>
    </section>
  </main>
</body></html>
