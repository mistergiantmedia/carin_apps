<?php
// Fixed lists used across the app. Labels are Dutch because they are shown to the family.

// Bump when style.css / *.js change, so browsers load the new version.
const ASSET_VERSION = '4';

// Calendar event types: label, emoji, colour. Order = order in pickers.
const EVENT_TYPES = [
    'PLAYDATE' => ['Speelafspraak', '🧸', '#F08C2E'],
    'PARTY' => ['Kinderfeestje', '🎈', '#E0568A'],
    'SPORT' => ['Sport', '⚽', '#2BA879'],
    'ACTIVITY' => ['Activiteit', '🎨', '#8E6CDF'],
    'OUTING' => ['Uitje', '🎡', '#1FA2B8'],
    'HOLIDAY' => ['Vakantie', '🏖️', '#F2B705'],
    'SCHOOL' => ['School', '🏫', '#4A6FE3'],
    'FAMILY' => ['Familie', '👵', '#C2489B'],
    'BORREL' => ['Borrel', '🥂', '#B8860B'],
    'BBQ' => ['BBQ', '🍖', '#D95F43'],
    'DINNER' => ['Etentje', '🍽️', '#A0522D'],
    'PARENTS' => ['Ouders uit', '💑', '#D6336C'],
    'BABYSIT' => ['Oppas', '🍼', '#5B8C2A'],
    'APPOINTMENT' => ['Afspraak', '🩺', '#607D8B'],
    'OTHER' => ['Overig', '📌', '#6C5CE7'],
];

// Types that belong to the parents' own social life
const SOCIAL_TYPES = ['BORREL', 'BBQ', 'DINNER', 'PARENTS'];

// Groups for the "Uitjes & feestjes" page
const EVENT_GROUPS = [
    'uitjes' => ['Uitjes & vakanties', ['OUTING', 'HOLIDAY']],
    'feestjes' => ['Feestjes, borrels & etentjes', ['PARTY', 'BORREL', 'BBQ', 'DINNER', 'FAMILY']],
    'sport' => ['Sport & activiteiten', ['SPORT', 'ACTIVITY']],
    'ouders' => ['Ouders uit', ['PARENTS', 'BORREL', 'DINNER', 'BBQ']],
    'afspraken' => ['Afspraken & school', ['APPOINTMENT', 'SCHOOL']],
];

const RECURRENCES = [
    '' => 'Niet herhalen',
    'DAILY' => 'Elke dag',
    'WEEKLY' => 'Elke week',
    'BIWEEKLY' => 'Om de week',
    'MONTHLY' => 'Elke maand',
    'YEARLY' => 'Elk jaar',
];

const TASK_RECURRENCES = [
    '' => 'Eenmalig',
    'DAILY' => 'Elke dag',
    'WEEKLY' => 'Elke week',
    'MONTHLY' => 'Elke maand',
];

const HOSTS = [
    '' => 'Niet van toepassing',
    'HOME' => '🏠 Bij ons',
    'AWAY' => '🚗 Bij hen',
];

const RSVPS = [
    '' => 'Uitgenodigd',
    'YES' => 'Komt',
    'MAYBE' => 'Misschien',
    'NO' => 'Kan niet',
];

// Contact relations: label, emoji
const RELATIONS = [
    'FRIEND' => ['Vriendje', '🧸'],
    'CLASSMATE' => ['Klasgenoot', '🏫'],
    'PARENT' => ['Ouder van vriendje', '👋'],
    'FAMILY' => ['Familie', '👵'],
    'OWN_FRIEND' => ['Eigen vrienden', '🥂'],
    'NEIGHBOR' => ['Buren', '🏡'],
    'BABYSITTER' => ['Oppas', '🍼'],
    'SCHOOL' => ['School / juf / meester', '📚'],
    'COACH' => ['Sport / club', '⚽'],
    'COLLEAGUE' => ['Collega', '💼'],
    'OTHER' => ['Overig', '📌'],
];

// Idea categories: label, emoji, short explanation
const IDEA_CATEGORIES = [
    'OUTING' => ['Uitje', '🎡', 'Leuke dingen om samen te doen'],
    'ENGAGEMENT' => ['Betrokken bij school', '🙋', 'Meehelpen en betrokken zijn op school en club'],
    'ATTENTION' => ['Attent zijn', '💌', 'Kleine gebaren voor mensen om je heen'],
    'DATE' => ['Date & ouders', '💑', 'Voor Carin en Rene samen of met vrienden'],
    'DINNER' => ['Etentje & borrel', '🍽️', 'Mensen uitnodigen of uit eten'],
    'GIFT' => ['Cadeau-idee', '🎁', 'Cadeaus voor verjaardagen en feestdagen'],
    'ACTIVITY' => ['Thuis & knutselen', '🎨', 'Voor regenachtige dagen'],
    'HOLIDAY' => ['Vakantie', '🏖️', 'Bestemmingen en vakantie-ideeën'],
];

const BIRTHDAY_ITEMS = [
    'CARD' => '💌 Kaartje / appje',
    'GIFT' => '🎁 Cadeau',
    'CALL' => '📞 Gebeld',
    'PARTY' => '🎉 Feestje gepland',
];

const PHOTO_KINDS = [
    'SCHOOL' => 'Schoolfoto',
    'CLASS' => 'Klassenfoto',
    'OTHER' => 'Andere foto',
];
