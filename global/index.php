<?php
include __DIR__ . '/markets.php';
$markets = array_values(array_filter(shobidMarketList(), function ($market) {
    return !empty($market['available']);
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Shobid Global</title>
  <link rel="stylesheet" href="/style.css?v=20260409-2">
</head>
<body class="country-selector-page">
  <main class="country-selector-shell">
    <section class="country-selector-card">
      <div class="profile-kicker">Global</div>
      <h1>Currently not available in your country.</h1>
      <p>Please choose an available country site to continue.</p>
      <div class="country-selector-actions" style="display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:center;">
        <?php foreach ($markets as $market): ?>
        <a class="profile-submit" href="/<?php echo htmlspecialchars((string)$market['code']); ?>/index.php"><?php echo htmlspecialchars((string)$market['name']); ?></a>
        <?php endforeach; ?>
      </div>
    </section>
  </main>
</body>
</html>
