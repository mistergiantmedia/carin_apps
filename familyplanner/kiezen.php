<?php
// Kinderen kiezen: big pictures of all recipes. A child taps their own face, then taps what they'd like to eat
// (a star). Parents see the wishes at the week menu (eten.php) and plan them in one click. ?kid=<member id>.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/meals.php';
require_login();
seed_recipes();

$kid = member(get_int('kid'));
if (is_post() && $kid && isset(recipes()[post_int('recipe_id')])) {
    $rid = post_int('recipe_id');
    $has = db()->prepare('SELECT 1 FROM fp_meal_wishes WHERE member_id = ? AND recipe_id = ?');
    $has->execute([$kid['id'], $rid]);
    if ($has->fetchColumn()) {
        db()->prepare('DELETE FROM fp_meal_wishes WHERE member_id = ? AND recipe_id = ?')->execute([$kid['id'], $rid]);
    } else {
        db()->prepare('INSERT INTO fp_meal_wishes (member_id, recipe_id) VALUES (?, ?)')->execute([$kid['id'], $rid]);
        flash('⭐ ' . recipes()[$rid]['name'] . '! Papa en mama zien je wens.');
    }
    redirect('kiezen.php?kid=' . (int) $kid['id'] . '#r' . $rid);
}

$wishes = meal_wishes();
// What's already on the menu in the coming week: recipe id => day
$planned = [];
foreach (meals_between(today(), date('Y-m-d', strtotime('+7 day'))) as $day => $m) {
    if ($m['recipe_id'] && !isset($planned[(int) $m['recipe_id']])) {
        $planned[(int) $m['recipe_id']] = $day;
    }
}

page_start('Kiezen wat we eten', ['active' => 'eten.php', 'bodyClass' => 'kids-pick']);
?>
<p><a href="eten.php">← Eten & boodschappen</a></p>
<?php if (!$kid): ?>
  <h1 class="pick-title">🙋 Wie ben jij?</h1>
  <div class="pick-who">
    <?php foreach (members() as $m): ?>
      <a class="pick-face" href="kiezen.php?kid=<?= (int) $m['id'] ?>" style="--c:<?= e($m['color']) ?>"><?= avatar($m, 120) ?><b><?= e($m['name']) ?></b></a>
    <?php endforeach; ?>
  </div>
<?php else:
    $mine = 0;
    foreach ($wishes as $rid => $who) {
        $mine += in_array((int) $kid['id'], $who, true) ? 1 : 0;
    }
    // Things you like first, then the rest; things you don't like (or can't eat) at the end
    $list = recipes();
    uasort($list, function ($a, $b) use ($kid) {
        $rank = function ($r) use ($kid) {
            $v = $r['likes'][(int) $kid['id']] ?? '';
            return allergic_to($r, $kid) ? 3 : ($v === 'LIKE' ? 0 : ($v === 'DISLIKE' ? 2 : 1));
        };
        return [$rank($a), $a['name']] <=> [$rank($b), $b['name']];
    }); ?>
  <div class="pick-head" style="--c:<?= e($kid['color']) ?>">
    <?= avatar($kid, 72) ?>
    <div class="grow"><h1 class="pick-title">Hoi <?= e($kid['name']) ?>! Wat wil jij eten? 😋</h1>
      <p>Tik op wat je lekker vindt. <?= $mine ? '<b>Je hebt ' . $mine . ' ' . ($mine === 1 ? 'wens' : 'wensen') . ' ⭐</b>' : '' ?></p></div>
    <a class="btn secondary" href="kiezen.php">Iemand anders</a>
  </div>
  <div class="pick-grid">
    <?php foreach ($list as $rid => $r):
        $wished = in_array((int) $kid['id'], $wishes[$rid] ?? [], true);
        $allergic = allergic_to($r, $kid);
        $dislike = ($r['likes'][(int) $kid['id']] ?? '') === 'DISLIKE';
        $others = array_filter($wishes[$rid] ?? [], function ($mid) use ($kid) {
            return $mid !== (int) $kid['id'];
        }); ?>
      <form method="post" class="pick-card<?= $wished ? ' wished' : '' ?><?= $dislike || $allergic ? ' meh' : '' ?>" id="r<?= (int) $rid ?>">
        <?= csrf_field() ?><input type="hidden" name="recipe_id" value="<?= (int) $rid ?>">
        <button type="submit"<?= $allergic ? ' disabled' : '' ?> aria-pressed="<?= $wished ? 'true' : 'false' ?>">
          <?php if ($r['photo']): ?><img src="foto.php?f=<?= e($r['photo']) ?>&amp;s=t" alt=""><?php else: ?><span class="pick-emoji" style="background:<?= e(recipe_color($r)) ?>"><?= e($r['emoji'] ?: '🍽️') ?></span><?php endif; ?>
          <b><?= e($r['name']) ?></b>
          <?php if ($wished): ?><span class="pick-star">⭐</span><?php endif; ?>
          <?php if (isset($planned[$rid])): ?><span class="pick-note">📅 <?= e(ucfirst(day_label($planned[$rid]))) ?>!</span>
          <?php elseif ($allergic): ?><span class="pick-note">⚠️ Niet voor jou</span>
          <?php elseif ($others): ?><span class="pick-note"><?php foreach ($others as $mid): if ($o = member($mid)): ?><?= avatar($o, 24) ?><?php endif; endforeach; ?> wil dit ook</span><?php endif; ?>
        </button>
      </form>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_end();
