<?php
// Example data so the app shows how everything works. Everything gets is_demo = 1 and can be
// removed in one go from the settings page (links, guests and checklists go with it).
// Dates are relative to today, so the demo always looks current.

function demo_loaded(): bool
{
    return (bool) db()->query('SELECT 1 FROM fp_contacts WHERE is_demo = 1 LIMIT 1')->fetchColumn();
}

function remove_demo(): void
{
    $pdo = db();
    $pdo->beginTransaction();
    foreach (['fp_ideas', 'fp_friendbook', 'fp_tasks', 'fp_events', 'fp_classes', 'fp_contacts', 'fp_households'] as $table) {
        $pdo->exec("DELETE FROM $table WHERE is_demo = 1");
    }
    $pdo->commit();
}

function load_demo(): void
{
    if (demo_loaded()) {
        return;
    }
    $pdo = db();
    $mid = [];
    foreach (members() as $m) {
        $mid[$m['name']] = (int) $m['id'];
    }
    $kaila = $mid['Kaila'] ?? null;
    $bodi = $mid['Bodi'] ?? null;
    $carin = $mid['Carin'] ?? null;
    $rene = $mid['Rene'] ?? null;
    $d = function (int $days, string $time = '00:00') {
        return date('Y-m-d', strtotime(today() . " $days day")) . ' ' . $time . ':00';
    };
    // Day-of-year offsets for birthdays (so some fall in the coming weeks)
    $bday = function (int $inDays, int $age) {
        $t = strtotime(today() . " $inDays day");
        return [(int) date('j', $t), (int) date('n', $t), (int) date('Y', $t) - $age];
    };

    $pdo->beginTransaction();
    try {
        $household = function (string $name, string $street, string $city, string $phone = '') use ($pdo) {
            $pdo->prepare('INSERT INTO fp_households (name, street, postal_code, city, phone, is_demo) VALUES (?, ?, ?, ?, ?, 1)')
                ->execute([$name, $street, '1234 AB', $city, $phone ?: null]);
            return (int) $pdo->lastInsertId();
        };
        $contact = function (array $c, array $members = [], bool $favorite = false) use ($pdo) {
            [$bd, $bm, $by] = $c['birthday'] ?? [null, null, null];
            $pdo->prepare('INSERT INTO fp_contacts (household_id, first_name, last_name, is_child, relation, birth_day, birth_month, birth_year, phone, email, allergies, hourly_rate, notes, is_favorite, is_demo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')->execute([
                $c['household'] ?? null, $c['first'], $c['last'] ?? null, !empty($c['child']) ? 1 : 0, $c['relation'] ?? 'FRIEND',
                $bd, $bm, $by, $c['phone'] ?? null, $c['email'] ?? null, $c['allergies'] ?? null, $c['rate'] ?? null, $c['notes'] ?? null, $favorite ? 1 : 0,
            ]);
            $id = (int) $pdo->lastInsertId();
            foreach (array_filter($members) as $m) {
                $pdo->prepare('INSERT INTO fp_contact_members (contact_id, member_id) VALUES (?, ?)')->execute([$id, $m]);
            }
            return $id;
        };

        // Families of friends
        $hVries = $household('Familie de Vries', 'Lindelaan 12', 'Utrecht', '06 12345678');
        $noor = $contact(['household' => $hVries, 'first' => 'Noor', 'last' => 'de Vries', 'child' => 1, 'birthday' => $bday(9, 9), 'allergies' => 'Pinda’s'], [$kaila], true);
        $contact(['household' => $hVries, 'first' => 'Sanne', 'last' => 'de Vries', 'relation' => 'PARENT', 'phone' => '06 12345678', 'birthday' => $bday(80, 39)], [$carin]);
        $contact(['household' => $hVries, 'first' => 'Mark', 'last' => 'de Vries', 'relation' => 'PARENT', 'phone' => '06 87654321'], [$rene]);

        $hBakker = $household('Familie Bakker', 'Kastanjestraat 3', 'Utrecht', '06 22223333');
        $lotte = $contact(['household' => $hBakker, 'first' => 'Lotte', 'last' => 'Bakker', 'child' => 1, 'birthday' => $bday(40, 8)], [$kaila], true);
        $sem = $contact(['household' => $hBakker, 'first' => 'Sem', 'last' => 'Bakker', 'child' => 1, 'birthday' => $bday(3, 5)], [$bodi], true);
        $contact(['household' => $hBakker, 'first' => 'Femke', 'last' => 'Bakker', 'relation' => 'PARENT', 'phone' => '06 22223333'], [$carin]);

        $hJansen = $household('Familie Jansen', 'Beukenweg 45', 'Utrecht');
        $finn = $contact(['household' => $hJansen, 'first' => 'Finn', 'last' => 'Jansen', 'child' => 1, 'birthday' => $bday(-20, 8)], [$kaila]);
        $contact(['household' => $hJansen, 'first' => 'Ilse', 'last' => 'Jansen', 'relation' => 'PARENT', 'phone' => '06 33334444'], []);

        $hPeters = $household('Familie Peters', 'Eikenlaan 8', 'Utrecht');
        $julia = $contact(['household' => $hPeters, 'first' => 'Julia', 'last' => 'Peters', 'child' => 1, 'birthday' => $bday(120, 5)], [$bodi], true);

        $hOpa = $household('Opa & Oma', 'Dorpsstraat 1', 'Amersfoort', '033 1234567');
        $oma = $contact(['household' => $hOpa, 'first' => 'Oma', 'last' => 'Ria', 'relation' => 'FAMILY', 'birthday' => $bday(25, 68), 'phone' => '033 1234567'], [$carin, $rene, $kaila, $bodi], true);
        $opa = $contact(['household' => $hOpa, 'first' => 'Opa', 'last' => 'Henk', 'relation' => 'FAMILY', 'birthday' => $bday(150, 71)], [$carin, $rene, $kaila, $bodi], true);

        $hEva = $household('Eva & Tom', 'Zonnelaan 20', 'Utrecht', '06 55556666');
        $eva = $contact(['household' => $hEva, 'first' => 'Eva', 'relation' => 'OWN_FRIEND', 'birthday' => $bday(14, 41), 'phone' => '06 55556666'], [$carin], true);
        $tom = $contact(['household' => $hEva, 'first' => 'Tom', 'relation' => 'OWN_FRIEND', 'birthday' => $bday(200, 42)], [$rene]);

        $lisa = $contact(['first' => 'Lisa', 'last' => 'Smit', 'relation' => 'BABYSITTER', 'phone' => '06 77778888', 'rate' => 10.50, 'notes' => 'Kan meestal vrijdag- en zaterdagavond. Studeert pedagogiek.'], [], true);
        $contact(['first' => 'Anne', 'last' => 'van Dijk', 'relation' => 'SCHOOL', 'notes' => 'Juf van groep 5 (Kaila)', 'email' => 'juf.anne@school.nl'], [$kaila]);

        // Classes (smoelenboek) with classmates
        $classmates = function (int $classId, array $names, int $age) use ($contact, $pdo) {
            $ids = [];
            foreach ($names as $i => $n) {
                [$first, $last] = explode(' ', $n, 2);
                $ids[] = $contact(['first' => $first, 'last' => $last, 'child' => 1, 'relation' => 'CLASSMATE', 'birthday' => [($i * 7) % 28 + 1, ($i * 5) % 12 + 1, (int) date('Y') - $age]]);
            }
            foreach ($ids as $cid) {
                $pdo->prepare('INSERT INTO fp_class_contacts (class_id, contact_id) VALUES (?, ?)')->execute([$classId, $cid]);
            }
            return $ids;
        };
        $pdo->prepare('INSERT INTO fp_classes (member_id, name, school, school_year, teacher, is_demo) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$kaila, 'Groep 5', 'Basisschool De Regenboog', school_year(), 'Juf Anne']);
        $k5 = (int) $pdo->lastInsertId();
        foreach ([$noor, $lotte, $finn] as $cid) {
            $pdo->prepare('INSERT INTO fp_class_contacts (class_id, contact_id) VALUES (?, ?)')->execute([$k5, $cid]);
        }
        $classmates($k5, ['Mila Visser', 'Daan de Boer', 'Sophie Mulder', 'Luuk Smit', 'Emma de Graaf', 'Levi Bos', 'Zoë Vos', 'Mees Dekker'], 8);
        $pdo->prepare('INSERT INTO fp_classes (member_id, name, school, school_year, teacher, is_demo) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$bodi, 'Groep 2', 'Basisschool De Regenboog', school_year(), 'Juf Marieke']);
        $k2 = (int) $pdo->lastInsertId();
        foreach ([$sem, $julia] as $cid) {
            $pdo->prepare('INSERT INTO fp_class_contacts (class_id, contact_id) VALUES (?, ?)')->execute([$k2, $cid]);
        }
        $classmates($k2, ['Noah Meijer', 'Saar Hendriks', 'Liam Kok', 'Nina Jacobs', 'Jens Willems', 'Fleur Maas'], 5);

        // Calendar
        $event = function (array $e) use ($pdo) {
            $pdo->prepare('INSERT INTO fp_events (title, type, start_at, end_at, all_day, location, host, description, recurrence, recur_until, drop_member_id, pickup_member_id, cost, paid, done, is_demo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')->execute([
                $e['title'], $e['type'], $e['start'], $e['end'], !empty($e['all_day']) ? 1 : 0, $e['location'] ?? null, $e['host'] ?? '',
                $e['description'] ?? null, $e['recurrence'] ?? '', $e['until'] ?? null, $e['drop'] ?? null, $e['pickup'] ?? null,
                $e['cost'] ?? null, !empty($e['paid']) ? 1 : 0, !empty($e['done']) ? 1 : 0,
            ]);
            $id = (int) $pdo->lastInsertId();
            foreach (array_filter($e['members'] ?? []) as $m) {
                $pdo->prepare('INSERT INTO fp_event_members (event_id, member_id) VALUES (?, ?)')->execute([$id, $m]);
            }
            foreach ($e['contacts'] ?? [] as $cid => $rsvp) {
                $pdo->prepare('INSERT INTO fp_event_contacts (event_id, contact_id, rsvp) VALUES (?, ?, ?)')->execute([$id, $cid, $rsvp]);
            }
            return $id;
        };
        $all = [$carin, $rene, $kaila, $bodi];
        // Weekly sport, starting on the right weekday this week
        $wed = date('Y-m-d', strtotime('wednesday this week', strtotime(today())));
        $sat = date('Y-m-d', strtotime('saturday this week', strtotime(today())));
        $fri = date('Y-m-d', strtotime('friday this week', strtotime(today())));
        $sun = date('Y-m-d', strtotime('sunday this week', strtotime(today())));
        $event(['title' => 'Zwemles', 'type' => 'SPORT', 'start' => date('Y-m-d', strtotime("$wed -14 day")) . ' 15:30:00', 'end' => date('Y-m-d', strtotime("$wed -14 day")) . ' 16:15:00', 'recurrence' => 'WEEKLY', 'members' => [$kaila], 'location' => 'Zwembad De Krommerijn', 'drop' => $carin, 'pickup' => $carin, 'description' => 'Zwemtas: handdoek, badpak, borstel.']);
        $event(['title' => 'Voetbal JO7', 'type' => 'SPORT', 'start' => date('Y-m-d', strtotime("$sat -14 day")) . ' 09:00:00', 'end' => date('Y-m-d', strtotime("$sat -14 day")) . ' 10:15:00', 'recurrence' => 'WEEKLY', 'members' => [$bodi], 'location' => 'Sportpark Zuilen', 'drop' => $rene, 'pickup' => $rene]);
        $event(['title' => 'Gym', 'type' => 'SCHOOL', 'start' => date('Y-m-d', strtotime('monday this week', strtotime(today()))) . ' 10:00:00', 'end' => date('Y-m-d', strtotime('monday this week', strtotime(today()))) . ' 10:45:00', 'recurrence' => 'WEEKLY', 'members' => [$kaila], 'description' => 'Gymtas mee!']);
        // Playdates (past and planned), including one to return
        $event(['title' => 'Spelen bij Noor', 'type' => 'PLAYDATE', 'start' => $d(-12, '14:00'), 'end' => $d(-12, '17:00'), 'host' => 'AWAY', 'members' => [$kaila], 'contacts' => [$noor => 'YES'], 'pickup' => $rene, 'done' => true]);
        $event(['title' => 'Spelen bij Noor', 'type' => 'PLAYDATE', 'start' => $d(-33, '14:00'), 'end' => $d(-33, '17:00'), 'host' => 'AWAY', 'members' => [$kaila], 'contacts' => [$noor => 'YES'], 'done' => true]);
        $event(['title' => 'Lotte komt spelen', 'type' => 'PLAYDATE', 'start' => $d(-5, '14:00'), 'end' => $d(-5, '17:30'), 'host' => 'HOME', 'members' => [$kaila], 'contacts' => [$lotte => 'YES'], 'done' => true]);
        $event(['title' => 'Sem komt spelen', 'type' => 'PLAYDATE', 'start' => $fri . ' 14:30:00', 'end' => $fri . ' 17:00:00', 'host' => 'HOME', 'members' => [$bodi], 'contacts' => [$sem => 'YES'], 'description' => 'Femke haalt Sem om 17:00 op.']);
        $event(['title' => 'Spelen bij Julia', 'type' => 'PLAYDATE', 'start' => $d(2, '13:00'), 'end' => $d(2, '16:00'), 'host' => 'AWAY', 'members' => [$bodi], 'contacts' => [$julia => 'YES']]);
        // Party with drop-off and pick-up + checklist
        $party = $event(['title' => 'Kinderfeestje Finn', 'type' => 'PARTY', 'start' => $sat . ' 14:00:00', 'end' => $sat . ' 17:00:00', 'location' => 'Ballorig Utrecht', 'members' => [$kaila], 'contacts' => [$finn => 'YES'], 'drop' => $carin, 'pickup' => $rene, 'host' => 'AWAY']);
        // Parents' social life
        $event(['title' => 'Etentje met z’n tweeën', 'type' => 'PARENTS', 'start' => $fri . ' 19:30:00', 'end' => $fri . ' 23:00:00', 'members' => [$carin, $rene], 'location' => 'Restaurant De Zusters']);
        $event(['title' => 'BBQ bij Eva & Tom', 'type' => 'BBQ', 'start' => $d(9, '16:00'), 'end' => $d(9, '21:00'), 'members' => $all, 'contacts' => [$eva => 'YES', $tom => 'YES'], 'location' => 'Zonnelaan 20']);
        $event(['title' => 'Borrel met de buren', 'type' => 'BORREL', 'start' => $d(16, '17:00'), 'end' => $d(16, '19:30'), 'members' => [$carin, $rene], 'host' => 'HOME']);
        $event(['title' => 'Oppas Lisa', 'type' => 'BABYSIT', 'start' => $d(-9, '19:00'), 'end' => $d(-9, '23:30'), 'members' => [$kaila, $bodi], 'contacts' => [$lisa => 'YES'], 'cost' => 47.25, 'paid' => false]);
        // Family and outings
        $event(['title' => 'Zondag bij opa & oma', 'type' => 'FAMILY', 'start' => $sun . ' 12:00:00', 'end' => $sun . ' 16:00:00', 'members' => $all, 'contacts' => [$oma => 'YES', $opa => 'YES'], 'location' => 'Amersfoort']);
        $event(['title' => 'Naar NEMO', 'type' => 'OUTING', 'start' => $d(20, '10:00'), 'end' => $d(20, '16:00'), 'members' => $all, 'location' => 'Amsterdam', 'cost' => 72.00, 'paid' => true]);
        $event(['title' => 'Herfstvakantie', 'type' => 'HOLIDAY', 'start' => $d(22) , 'end' => date('Y-m-d', strtotime(today() . ' +30 day')) . ' 23:59:00', 'all_day' => true, 'members' => $all]);
        $event(['title' => 'Tandarts Bodi', 'type' => 'APPOINTMENT', 'start' => $d(4, '08:30'), 'end' => $d(4, '09:00'), 'members' => [$bodi], 'drop' => $rene]);
        $event(['title' => 'Ouderavond groep 5', 'type' => 'SCHOOL', 'start' => $d(6, '19:30'), 'end' => $d(6, '20:30'), 'members' => [$carin]]);
        $event(['title' => 'Padel met de jongens', 'type' => 'SPORT', 'start' => $d(1, '20:00'), 'end' => $d(1, '21:30'), 'members' => [$rene]]);
        $event(['title' => 'Yoga', 'type' => 'SPORT', 'start' => date('Y-m-d', strtotime('tuesday this week', strtotime(today()))) . ' 20:00:00', 'end' => date('Y-m-d', strtotime('tuesday this week', strtotime(today()))) . ' 21:00:00', 'recurrence' => 'WEEKLY', 'members' => [$carin]]);

        // Tasks
        $task = function (string $title, ?int $member, ?int $days, string $rec = '', ?int $event = null, ?int $contact = null) use ($pdo) {
            $pdo->prepare('INSERT INTO fp_tasks (title, member_id, due_date, recurrence, event_id, contact_id, is_demo) VALUES (?, ?, ?, ?, ?, ?, 1)')
                ->execute([$title, $member, $days === null ? null : date('Y-m-d', strtotime(today() . " $days day")), $rec, $event, $contact]);
        };
        $task('Cadeautje kopen voor Finn', $carin, 1, '', $party);
        $task('Kaartje maken voor Finn', $kaila, 2, '', $party);
        $task('Gymtas inpakken', $kaila, 0, 'WEEKLY');
        $task('Vuilnisbak aan de straat', $rene, 1, 'WEEKLY');
        $task('Tanden poetsen sticker-kaart bijwerken', $bodi, 0, 'DAILY');
        $task('Oppas Lisa betalen', $rene, -2);
        $task('Schoolfoto’s bestellen', $carin, 5);
        $task('Vakantie herfst: huisje boeken', null, null);

        // Friend books
        $pdo->prepare('INSERT INTO fp_friendbook (member_id, contact_id, direction, given_on, is_demo) VALUES (?, ?, ?, ?, 1)')
            ->execute([$kaila, $noor, 'OUT', date('Y-m-d', strtotime(today() . ' -19 day'))]);
        $pdo->prepare('INSERT INTO fp_friendbook (member_id, contact_id, direction, given_on, is_demo) VALUES (?, ?, ?, ?, 1)')
            ->execute([$bodi, $sem, 'IN', date('Y-m-d', strtotime(today() . ' -6 day'))]);
        $pdo->prepare('INSERT INTO fp_friendbook (member_id, contact_id, direction, given_on, returned_on, is_demo) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$kaila, $lotte, 'OUT', date('Y-m-d', strtotime(today() . ' -60 day')), date('Y-m-d', strtotime(today() . ' -45 day'))]);

        // Gift idea for Noor
        $pdo->prepare("INSERT INTO fp_ideas (title, category, contact_id, is_demo) VALUES ('Knutselset sieraden maken', 'GIFT', ?, 1)")->execute([$noor]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
