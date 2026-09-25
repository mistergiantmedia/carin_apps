<?php
// Oppas: babysitters (with hourly rate), upcoming and past bookings, costs per month,
// and everything that still has to be paid (also other events with costs).
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require_login();

if (is_post()) {
    $action = post('action');
    if ($action === 'paid') {
        db()->prepare('UPDATE fp_events SET paid = 1 - paid WHERE id = ?')->execute([post_int('id')]);
        flash('Bijgewerkt');
    }
    if ($action === 'set_cost') {
        $cost = round((float) str_replace(',', '.', post('cost')), 2);
        db()->prepare('UPDATE fp_events SET cost = ? WHERE id = ?')->execute([$cost > 0 ? $cost : null, post_int('id')]);
        flash('Bedrag opgeslagen');
    }
    redirect('oppas.php' . (post('anchor') ? '#' . preg_replace('/[^a-z]/', '', post('anchor')) : ''));
}

$sitters = db()->query("SELECT c.*, (SELECT COUNT(*) FROM fp_event_contacts ec JOIN fp_events e ON e.id = ec.event_id WHERE ec.contact_id = c.id AND e.type = 'BABYSIT') AS times,
    (SELECT MAX(e.start_at) FROM fp_event_contacts ec JOIN fp_events e ON e.id = ec.event_id WHERE ec.contact_id = c.id AND e.type = 'BABYSIT' AND e.start_at <= NOW()) AS last_time
    FROM fp_contacts c WHERE c.relation = 'BABYSITTER' ORDER BY c.is_favorite DESC, times DESC, c.first_name")->fetchAll();
$upcoming = load_events(today(), date('Y-m-d', strtotime('+6 month')), ['types' => ['BABYSIT']]);
$past = array_reverse(load_events(date('Y-m-d', strtotime('-1 year')), today(), ['types' => ['BABYSIT']]));
$kidIds = array_map('intval', array_keys(children()));

/** Hours × the sitter's rate, when no amount has been entered yet. */
function estimate(array $ev): ?float
{
    foreach ($ev['contacts'] as $c) {
        $stmt = db()->prepare('SELECT hourly_rate FROM fp_contacts WHERE id = ?');
        $stmt->execute([$c['id']]);
        $rate = $stmt->fetchColumn();
        if ($rate) {
            $hours = (strtotime($ev['end_at']) - strtotime($ev['start_at'])) / 3600;
            return round($hours * (float) $rate, 2);
        }
    }
    return null;
}

$perMonth = [];
foreach ($past as $ev) {
    $amount = $ev['cost'] !== null ? (float) $ev['cost'] : (estimate($ev) ?? 0);
    $ym = substr($ev['start_at'], 0, 7);
    $perMonth[$ym]['sum'] = ($perMonth[$ym]['sum'] ?? 0) + $amount;
    $perMonth[$ym]['hours'] = ($perMonth[$ym]['hours'] ?? 0) + (strtotime($ev['end_at']) - strtotime($ev['start_at'])) / 3600;
    $perMonth[$ym]['n'] = ($perMonth[$ym]['n'] ?? 0) + 1;
}
$unpaid = db()->query('SELECT id, title, type, cost, start_at FROM fp_events WHERE cost > 0 AND paid = 0 AND start_at <= NOW() ORDER BY start_at')->fetchAll();

page_start('Oppas');
page_header('🍼 Oppas', 'Wie past er op, wanneer, en wat moet er nog betaald worden.',
    '<a class="btn secondary" href="contact.php?new=1&amp;relation=BABYSITTER&amp;next=oppas.php">＋ Oppas toevoegen</a>');
?>
<div class="section-title" style="margin-top:0"><h2>👩‍🍼 Onze oppassers</h2></div>
<?php if (!$sitters): ?>
  <div class="card"><?= empty_state('🍼', 'Nog geen oppas in het adresboek.', '<a class="btn" href="contact.php?new=1&amp;relation=BABYSITTER&amp;next=oppas.php">＋ Oppas toevoegen</a>') ?></div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($sitters as $s): ?>
      <div class="card">
        <div style="display:flex;gap:12px;align-items:center">
          <?= avatar($s, 56) ?>
          <div class="grow"><a href="contact.php?id=<?= (int) $s['id'] ?>" style="color:inherit"><b style="font-size:17px"><?= e(contact_name($s)) ?></b></a> <?= $s['is_favorite'] ? '⭐' : '' ?><br>
            <span class="muted small"><?= $s['hourly_rate'] !== null ? e(money((float) $s['hourly_rate'])) . ' / uur · ' : '' ?><?= (int) $s['times'] ?>× opgepast<?= $s['last_time'] ? ' · laatst ' . e(format_date_short($s['last_time'])) : '' ?></span></div>
        </div>
        <?php if ($s['notes']): ?><p class="small muted" style="margin:10px 0 0"><?= e(excerpt($s['notes'], 140)) ?></p><?php endif; ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px">
          <button type="button" class="btn small" data-new-event='<?= e(json_encode(['type' => 'BABYSIT', 'title' => 'Oppas ' . contact_name($s, false), 'members' => $kidIds, 'contacts' => [['id' => (int) $s['id'], 'name' => contact_name($s, false), 'fullName' => contact_name($s), 'photo' => $s['photo'], 'color' => name_color(contact_name($s, false)), 'rsvp' => '']], 'start' => today() . 'T19:00', 'end' => today() . 'T23:30'], JSON_UNESCAPED_UNICODE)) ?>'>📅 Inplannen</button>
          <?php if ($s['phone']): ?><a class="btn small secondary" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $s['phone'])) ?>">📞 Bellen</a>
            <a class="btn small secondary" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace(['/[^0-9]/', '/^06/'], ['', '316'], $s['phone'])) ?>?text=<?= e(rawurlencode('Hoi ' . contact_name($s, false) . '! Zou je kunnen oppassen op ')) ?>">💬 Appen</a><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="cols" style="margin-top:18px">
  <div class="stack">
    <div class="card">
      <h2>📅 Gepland</h2>
      <?php foreach ($upcoming as $ev): $est = $ev['cost'] === null ? estimate($ev) : null; ?>
        <a class="ev" style="--c:<?= e(event_color($ev)) ?>" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>" data-edit-event="<?= (int) $ev['id'] ?>" data-occ="<?= e($ev['occ']) ?>">
          <div class="ev-time"><?= e(format_date_short($ev['start_at'])) ?><br><?= e(substr($ev['start_at'], 11, 5)) ?>–<?= e(substr($ev['end_at'], 11, 5)) ?></div>
          <div class="ev-body"><span class="ev-title"><?= e($ev['title']) ?></span><span class="ev-meta"><?= $ev['cost'] !== null ? e(money((float) $ev['cost'])) : ($est ? '± ' . e(money($est)) : '') ?></span></div>
          <span class="avatars"><?php foreach ($ev['contacts'] as $c): ?><?= avatar($c, 30) ?><?php endforeach; ?></span></a>
      <?php endforeach; ?>
      <?php if (!$upcoming): ?><p class="muted">Geen oppas gepland.</p><?php endif; ?>
    </div>
    <div class="card">
      <h2>🕰 Geweest</h2>
      <div class="table-wrap"><table class="data">
        <tr><th>Wanneer</th><th>Wie</th><th class="num">Uren</th><th class="num">Bedrag</th><th></th></tr>
        <?php foreach (array_slice($past, 0, 30) as $ev): $hours = (strtotime($ev['end_at']) - strtotime($ev['start_at'])) / 3600; $est = $ev['cost'] === null ? estimate($ev) : null; ?>
          <tr>
            <td class="nowrap"><a href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>"><?= e(format_date_short($ev['start_at'])) ?></a></td>
            <td><?= e(implode(', ', array_map(function ($c) { return contact_name($c, false); }, $ev['contacts'])) ?: '—') ?></td>
            <td class="num"><?= e(str_replace('.', ',', (string) round($hours, 1))) ?></td>
            <td class="num">
              <?php if ($ev['cost'] !== null): ?><?= e(money((float) $ev['cost'])) ?>
              <?php else: ?>
                <form method="post" style="display:flex;gap:4px;justify-content:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="set_cost"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
                  <input name="cost" value="<?= $est ? e(str_replace('.', ',', (string) $est)) : '' ?>" placeholder="€" style="width:80px;min-height:32px;padding:4px 8px"><button class="btn small">✓</button></form>
              <?php endif; ?>
            </td>
            <td><?php if ($ev['cost'] !== null): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><button class="badge <?= $ev['paid'] ? 'ok' : 'warn' ?>" style="border:0;cursor:pointer"><?= $ev['paid'] ? '✓ betaald' : 'nog betalen' ?></button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
      <?php if (!$past): ?><p class="muted">Nog geen oppasavonden geweest.</p><?php endif; ?>
    </div>
  </div>
  <div class="stack">
    <div class="card" id="betalen">
      <h2>💶 Nog te betalen</h2>
      <?php if ($unpaid): ?>
        <ul class="list compact">
          <?php foreach ($unpaid as $u): ?>
            <li><span style="font-size:20px"><?= event_type($u['type'])[1] ?></span><div class="grow"><a class="title" href="event.php?id=<?= (int) $u['id'] ?>" style="color:inherit"><?= e($u['title']) ?></a><span class="meta"><?= e(format_date_short($u['start_at'])) ?></span></div>
              <b><?= e(money((float) $u['cost'])) ?></b>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="anchor" value="betalen"><button class="btn small ok">✓</button></form></li>
          <?php endforeach; ?>
        </ul>
        <p style="margin-top:10px"><b>Totaal: <?= e(money((float) array_sum(array_column($unpaid, 'cost')))) ?></b></p>
      <?php else: ?><p class="muted">Alles is betaald. 👍</p><?php endif; ?>
    </div>
    <div class="card">
      <h2>📊 Per maand</h2>
      <?php if ($perMonth): ?>
        <table class="data">
          <tr><th>Maand</th><th class="num">Keer</th><th class="num">Uren</th><th class="num">Kosten</th></tr>
          <?php foreach ($perMonth as $ym => $row): ?>
            <tr><td style="text-transform:capitalize"><?= e(MONTHS[(int) substr($ym, 5, 2)]) ?> <?= substr($ym, 0, 4) ?></td><td class="num"><?= $row['n'] ?></td><td class="num"><?= e(str_replace('.', ',', (string) round($row['hours'], 1))) ?></td><td class="num"><?= e(money($row['sum'])) ?></td></tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?><p class="muted">Nog geen gegevens.</p><?php endif; ?>
    </div>
  </div>
</div>
<?php page_end();
