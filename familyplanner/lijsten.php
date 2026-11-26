<?php
// Lijstjes: one list per thing to sort out (energie overstappen, dekbedden kopen…) with to-dos,
// options to compare (link, price, pros and cons, our pick) and inspiration. ?id=<list> opens one list.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/lists.php';
$user = require_login();

$listId = get_int('id');
$list = $listId ? find_list($listId) : null;
if ($listId && !$list) {
    redirect('lijsten.php');
}
$here = 'lijsten.php' . ($list ? '?id=' . (int) $list['id'] : '');

if (is_post()) {
    $action = post('action');
    $id = post_int('id');
    try {
        if ($action === 'add_list' && post('title') !== '') {
            db()->prepare('INSERT INTO fp_lists (title, emoji, notes, created_by) VALUES (?, ?, ?, ?)')
                ->execute([mb_cut(post('title'), 160), post('emoji') !== '' ? mb_cut(post('emoji'), 16) : null, post_or_null('notes'), $user['id']]);
            redirect('lijsten.php?id=' . (int) db()->lastInsertId());
        }
        if ($action === 'edit_list' && $list && post('title') !== '') {
            db()->prepare('UPDATE fp_lists SET title = ?, emoji = ?, notes = ? WHERE id = ?')
                ->execute([mb_cut(post('title'), 160), post('emoji') !== '' ? mb_cut(post('emoji'), 16) : null, post_or_null('notes'), $list['id']]);
            flash('Lijst aangepast');
        }
        if ($action === 'finish_list' && $list) {
            db()->prepare('UPDATE fp_lists SET done_at = IF(done_at IS NULL, NOW(), NULL) WHERE id = ?')->execute([$list['id']]);
            flash($list['done_at'] ? 'Lijst weer geopend' : '🎉 Klaar! De lijst staat nu onder "afgerond".');
            redirect('lijsten.php');
        }
        if ($action === 'delete_list' && $list) {
            db()->prepare('DELETE FROM fp_lists WHERE id = ?')->execute([$list['id']]);
            flash('Lijst verwijderd');
            redirect('lijsten.php');
        }
        if ($list && $action === 'add_item') {
            save_list_item((int) $list['id'], [
                'kind' => post('kind'), 'title' => post('title'), 'url' => post('url'), 'price' => post('price'), 'price_note' => post('price_note'),
                'pros' => post('pros'), 'cons' => post('cons'), 'notes' => post('notes'), 'member_id' => post_int('member_id') ?: null,
            ]);
            flash(['TODO' => 'Op de lijst gezet ✅', 'OPTION' => 'Optie toegevoegd 🛒', 'INSPIRATION' => 'Inspiratie bewaard 💡'][post('kind')] ?? 'Toegevoegd');
        }
        if ($list && $action === 'edit_item' && $id) {
            save_list_item((int) $list['id'], [
                'title' => post('title'), 'url' => post('url'), 'price' => post('price'), 'price_note' => post('price_note'),
                'pros' => post('pros'), 'cons' => post('cons'), 'notes' => post('notes'), 'member_id' => post_int('member_id') ?: 0,
            ], $id);
            flash('Aangepast');
        }
        if ($list && $action === 'toggle' && $id) {
            toggle_list_item($id);
        }
        if ($list && $action === 'choose' && $id) {
            $it = find_list_item($id);
            set_list_choice((int) $list['id'], $id, $it && !$it['chosen']);
        }
        if ($list && $action === 'delete_item' && $id) {
            db()->prepare('DELETE FROM fp_list_items WHERE id = ? AND list_id = ?')->execute([$id, $list['id']]);
            flash('Verwijderd');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($here . ($id ? '#i' . $id : ''));
}

/** Add / edit form for one item. $kind decides which fields show. */
function item_form(array $list, string $kind, ?array $it = null): string
{
    $new = $it === null;
    $v = function ($k) use ($it) {
        return e($it[$k] ?? '');
    };
    $h = '<form method="post" class="form item-form">' . csrf_field()
        . '<input type="hidden" name="action" value="' . ($new ? 'add_item' : 'edit_item') . '">'
        . '<input type="hidden" name="id" value="' . (int) ($it['id'] ?? 0) . '">'
        . '<input type="hidden" name="kind" value="' . e($kind) . '">'
        . '<input type="hidden" name="next" value="lijsten.php?id=' . (int) $list['id'] . '">';
    if ($kind === 'TODO') {
        $h .= '<div class="item-row"><input name="title" value="' . $v('title') . '" placeholder="Wat moet er gebeuren? Bijv. meterstanden doorgeven" required>'
            . '<select name="member_id" aria-label="Wie"><option value="">Wie?</option>' . options(member_options(), $it['member_id'] ?? '') . '</select></div>'
            . '<details class="more"><summary>Link of toelichting</summary><input name="url" value="' . $v('url') . '" placeholder="https://…" inputmode="url">'
            . '<textarea name="notes" rows="2" placeholder="Toelichting">' . $v('notes') . '</textarea></details>';
    } else {
        $h .= '<input name="url" value="' . $v('url') . '" placeholder="Plak hier de link: https://…" inputmode="url" style="font-size:16px">'
            . '<input name="title" value="' . $v('title') . '" placeholder="' . ($kind === 'OPTION' ? 'Naam (leeg = we halen de titel van de link)' : 'Titel (leeg = titel van de link)') . '">';
        if ($kind === 'OPTION') {
            $h .= '<div class="row2"><div><label>Bedrag (€)</label><input name="price" value="' . ($it && $it['price'] !== null ? e(number_format((float) $it['price'], 2, ',', '')) : '') . '" placeholder="49,95" inputmode="decimal"></div>'
                . '<div><label>Per</label><input name="price_note" value="' . $v('price_note') . '" list="price-notes" placeholder="eenmalig, per maand, per jaar…"></div></div>'
                . '<div class="row2"><div><label>👍 Voordelen <small>(één per regel)</small></label><textarea name="pros" rows="3">' . $v('pros') . '</textarea></div>'
                . '<div><label>👎 Nadelen <small>(één per regel)</small></label><textarea name="cons" rows="3">' . $v('cons') . '</textarea></div></div>';
        }
        $h .= '<textarea name="notes" rows="2" placeholder="' . ($kind === 'OPTION' ? 'Opmerking' : 'Waarom is dit mooi of handig?') . '">' . $v('notes') . '</textarea>';
    }
    return $h . '<button class="btn small" style="margin-top:10px">' . ($new ? '＋ Toevoegen' : 'Opslaan') . '</button></form>';
}

function item_action(string $action, array $it, string $label, string $class = 'btn small secondary', string $extra = ''): string
{
    return '<form method="post" class="inline"' . $extra . '>' . csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . (int) $it['id'] . '">'
        . '<input type="hidden" name="next" value="lijsten.php?id=' . (int) $it['list_id'] . '"><button class="' . $class . '">' . $label . '</button></form>';
}

function lines_html(string $icon, ?string $text): string
{
    $lines = list_lines($text);
    if (!$lines) {
        return '';
    }
    $h = '<ul class="proscons">';
    foreach ($lines as $l) {
        $h .= '<li><span>' . $icon . '</span> ' . e($l) . '</li>';
    }
    return $h . '</ul>';
}

function price_text(array $o): string
{
    return $o['price'] === null ? '' : money((float) $o['price']) . ($o['price_note'] ? ' <small>' . e($o['price_note']) . '</small>' : '');
}

// ---------------------------------------------------------------- overview
if (!$list) {
    $open = load_lists(false);
    $finished = load_lists(true);
    page_start('Lijstjes', ['active' => 'lijsten.php']);
    page_header('📋 Lijstjes', 'Per onderwerp een lijst: wat moet er gebeuren, welke opties zijn er (met link, bedrag, voor- en nadelen) en wat inspireert ons.',
        '<a class="btn" href="#nieuw">＋ Nieuwe lijst</a>');
    ?>
    <?php if (!$open): ?>
      <div class="card"><?= empty_state('📋', 'Nog geen lijstjes. Begin bijvoorbeeld met "Energie overstappen" of "Nieuwe dekbedden".') ?></div>
    <?php else: ?>
      <div class="grid list-grid">
        <?php foreach ($open as $l): $pct = $l['todos'] ? round($l['todos_done'] / $l['todos'] * 100) : 0; ?>
          <a class="card list-card" href="lijsten.php?id=<?= (int) $l['id'] ?>">
            <div class="list-emoji"><?= e($l['emoji'] ?: '📋') ?></div>
            <h3><?= e($l['title']) ?></h3>
            <?php if ($l['notes']): ?><p class="muted small"><?= e(excerpt($l['notes'], 90)) ?></p><?php endif; ?>
            <div class="list-counts">
              <?php if ($l['todos']): ?><span class="badge <?= $l['todos_done'] == $l['todos'] ? 'ok' : '' ?>">✅ <?= (int) $l['todos_done'] ?>/<?= (int) $l['todos'] ?></span><?php endif; ?>
              <?php if ($l['options']): ?><span class="badge">🛒 <?= (int) $l['options'] ?> <?= $l['options'] == 1 ? 'optie' : 'opties' ?></span><?php endif; ?>
              <?php if ($l['inspiration']): ?><span class="badge">💡 <?= (int) $l['inspiration'] ?></span><?php endif; ?>
              <?php if (!$l['todos'] && !$l['options'] && !$l['inspiration']): ?><span class="badge">nog leeg</span><?php endif; ?>
            </div>
            <?php if ($l['todos']): ?><div class="progress"><span style="width:<?= $pct ?>%"></span></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="card form" id="nieuw" style="margin-top:18px">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_list">
      <h2>＋ Nieuwe lijst</h2>
      <div style="display:flex;gap:8px;position:relative;margin-top:8px">
        <input name="emoji" value="" data-emoji-picker data-default="📋" aria-label="Emoji">
        <input name="title" placeholder="Waar gaat het over? Bijv. Energie overstappen" required style="font-size:17px">
      </div>
      <details class="more"><summary>Toelichting</summary><textarea name="notes" rows="2" placeholder="Bijv. contract loopt af in maart"></textarea></details>
      <button class="btn" style="margin-top:14px">Lijst maken</button>
    </form>

    <?php if ($finished): ?>
      <div class="section-title"><h2>🎉 Afgerond (<?= count($finished) ?>)</h2></div>
      <div class="grid list-grid">
        <?php foreach ($finished as $l): ?>
          <a class="card list-card done" href="lijsten.php?id=<?= (int) $l['id'] ?>"><h3><?= e(($l['emoji'] ?: '📋') . ' ' . $l['title']) ?></h3></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php page_end();
    return;
}

// ---------------------------------------------------------------- one list
$items = load_list_items((int) $list['id']);
$todoDone = count(array_filter($items['TODO'], function ($t) {
    return $t['done_at'] !== null;
}));
$cheap = cheapest_options($items['OPTION']);
page_start($list['title'], ['active' => 'lijsten.php']);
page_header(($list['emoji'] ?: '📋') . ' ' . $list['title'], $list['notes'] ?: '', '<a class="btn secondary" href="lijsten.php">← Alle lijstjes</a>');
?>
<?php if ($list['done_at']): ?><div class="card tight" style="margin-bottom:14px">🎉 Deze lijst is afgerond.</div><?php endif; ?>
<datalist id="price-notes"><option value="eenmalig"><option value="per maand"><option value="per jaar"><option value="per stuk"></datalist>

<!-- Te doen -->
<div class="card" id="todo">
  <div class="list-head"><h2>✅ Te doen</h2><?php if ($items['TODO']): ?><span class="badge <?= $todoDone === count($items['TODO']) ? 'ok' : '' ?>"><?= $todoDone ?>/<?= count($items['TODO']) ?></span><?php endif; ?></div>
  <?php if ($items['TODO']): ?>
    <ul class="list list-todos">
      <?php foreach ($items['TODO'] as $t): $m = member($t['member_id'] ? (int) $t['member_id'] : null); $done = $t['done_at'] !== null; ?>
        <li class="task<?= $done ? ' is-done' : '' ?>" id="i<?= (int) $t['id'] ?>">
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="next" value="<?= e($here) ?>">
            <input type="checkbox" class="tick" aria-label="Afvinken"<?= $done ? ' checked' : '' ?> onchange="this.form.submit()"></form>
          <div class="grow"><span class="title"><?= e($t['title']) ?></span>
            <?php if ($t['url'] || $t['notes']): ?><span class="meta"><?php if ($t['url']): ?><a href="<?= e($t['url']) ?>" target="_blank" rel="noopener">🔗 <?= e(link_host($t['url'])) ?></a> <?php endif; ?><?= e($t['notes'] ?? '') ?></span><?php endif; ?></div>
          <?php if ($m): ?><?= avatar($m, 28) ?><?php endif; ?>
          <details class="item-more"><summary class="btn small secondary" title="Aanpassen">✏️</summary>
            <div class="item-edit"><?= item_form($list, 'TODO', $t) ?><?= item_action('delete_item', $t, '🗑 Verwijderen', 'btn small danger', ' data-confirm="Verwijderen?"') ?></div></details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?= item_form($list, 'TODO') ?>
</div>

<!-- Opties -->
<div class="card" id="opties" style="margin-top:16px">
  <div class="list-head"><h2>🛒 Opties vergelijken</h2><?php if ($items['OPTION']): ?><span class="badge"><?= count($items['OPTION']) ?></span><?php endif; ?></div>
  <?php if (count($items['OPTION']) > 1): ?>
    <div class="table-wrap"><table class="data compare">
      <thead><tr><th></th><th>Optie</th><th>Bedrag</th><th>👍</th><th>👎</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($items['OPTION'] as $o): ?>
        <tr<?= $o['chosen'] ? ' class="picked"' : '' ?>>
          <td><?= $o['chosen'] ? '⭐' : '' ?></td>
          <td><a href="#i<?= (int) $o['id'] ?>"><?= e($o['title']) ?></a></td>
          <td class="nowrap"><b><?= price_text($o) ?: '<span class="muted">—</span>' ?></b><?= in_array((int) $o['id'], $cheap, true) ? ' <span class="badge ok">goedkoopst</span>' : '' ?></td>
          <td><?= count(list_lines($o['pros'])) ?: '' ?></td>
          <td><?= count(list_lines($o['cons'])) ?: '' ?></td>
          <td><?php if ($o['url']): ?><a href="<?= e($o['url']) ?>" target="_blank" rel="noopener">↗</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?php if ($items['OPTION']): ?>
    <div class="grid option-grid">
      <?php foreach ($items['OPTION'] as $o): ?>
        <div class="option-card<?= $o['chosen'] ? ' picked' : '' ?>" id="i<?= (int) $o['id'] ?>">
          <div class="option-top">
            <h3><?= e($o['title']) ?></h3>
            <?php if ($o['chosen']): ?><span class="badge warn">⭐ Onze keuze</span><?php endif; ?>
          </div>
          <?php if ($o['price'] !== null): ?><div class="price"><?= price_text($o) ?><?= in_array((int) $o['id'], $cheap, true) ? ' <span class="badge ok">goedkoopst</span>' : '' ?></div><?php endif; ?>
          <?php if ($o['url']): ?><a class="small" href="<?= e($o['url']) ?>" target="_blank" rel="noopener">🔗 <?= e(link_host($o['url'])) ?> ↗</a><?php endif; ?>
          <?= lines_html('👍', $o['pros']) ?><?= lines_html('👎', $o['cons']) ?>
          <?php if ($o['notes']): ?><p class="muted small"><?= nl2br(e($o['notes'])) ?></p><?php endif; ?>
          <div class="option-actions">
            <?= item_action('choose', $o, $o['chosen'] ? '⭐ Gekozen' : '☆ Dit wordt het', $o['chosen'] ? 'btn small warn-btn' : 'btn small soft') ?>
            <details class="item-more"><summary class="btn small secondary" title="Aanpassen">✏️</summary>
              <div class="item-edit"><?= item_form($list, 'OPTION', $o) ?><?= item_action('delete_item', $o, '🗑 Verwijderen', 'btn small danger', ' data-confirm="Deze optie verwijderen?"') ?></div></details>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <details class="more add-more"<?= $items['OPTION'] ? '' : ' open' ?>><summary class="btn small soft">Optie toevoegen</summary><?= item_form($list, 'OPTION') ?></details>
</div>

<!-- Inspiratie -->
<div class="card" id="inspiratie" style="margin-top:16px">
  <div class="list-head"><h2>💡 Inspiratie</h2><?php if ($items['INSPIRATION']): ?><span class="badge"><?= count($items['INSPIRATION']) ?></span><?php endif; ?></div>
  <?php if ($items['INSPIRATION']): ?>
    <div class="grid option-grid">
      <?php foreach ($items['INSPIRATION'] as $o): ?>
        <div class="option-card" id="i<?= (int) $o['id'] ?>">
          <h3><?= e($o['title']) ?></h3>
          <?php if ($o['url']): ?><a class="small" href="<?= e($o['url']) ?>" target="_blank" rel="noopener">🔗 <?= e(link_host($o['url'])) ?> ↗</a><?php endif; ?>
          <?php if ($o['notes']): ?><p class="muted small"><?= nl2br(e($o['notes'])) ?></p><?php endif; ?>
          <div class="option-actions">
            <details class="item-more"><summary class="btn small secondary" title="Aanpassen">✏️</summary>
              <div class="item-edit"><?= item_form($list, 'INSPIRATION', $o) ?><?= item_action('delete_item', $o, '🗑 Verwijderen', 'btn small danger', ' data-confirm="Verwijderen?"') ?></div></details>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <details class="more add-more"<?= $items['INSPIRATION'] ? '' : ' open' ?>><summary class="btn small soft">Inspiratie toevoegen</summary><?= item_form($list, 'INSPIRATION') ?></details>
</div>

<!-- Lijst zelf -->
<details class="card more" style="margin-top:16px">
  <summary>⚙️ Lijst aanpassen of afronden</summary>
  <form method="post" class="form" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="edit_list"><input type="hidden" name="next" value="<?= e($here) ?>">
    <div style="display:flex;gap:8px;position:relative"><input name="emoji" value="<?= e($list['emoji'] ?? '') ?>" data-emoji-picker data-default="📋"><input name="title" value="<?= e($list['title']) ?>" required></div>
    <textarea name="notes" rows="2" placeholder="Toelichting" style="margin-top:8px"><?= e($list['notes'] ?? '') ?></textarea>
    <button class="btn small" style="margin-top:8px">Opslaan</button>
  </form>
  <div style="display:flex;gap:8px;margin-top:12px">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="finish_list"><input type="hidden" name="next" value="<?= e($here) ?>"><button class="btn ok"><?= $list['done_at'] ? '↺ Weer openen' : '🎉 Lijst afronden' ?></button></form>
    <form method="post" class="inline" data-confirm="De hele lijst met alles erin verwijderen?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_list"><input type="hidden" name="next" value="<?= e($here) ?>"><button class="btn danger">🗑 Verwijderen</button></form>
  </div>
</details>
<?php page_end();
