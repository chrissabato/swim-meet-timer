<?php
// Simple Meet Timer API — one JSON file per meet in ./data, guarded with flock().
//
// GET  api.php?a=time                      -> { now }  (server clock, ms; phones sync to this)
// GET  api.php?a=state&code=ABCD&v=12      -> meet JSON, or { same: true } if version unchanged
// POST api.php?a=create  {name,date,lanes,pool,unit,genders}            -> { code }
// POST api.php?a=<action>&code=ABCD  {...}                 -> { ok, meet }
//      actions: start {t} | recall | next {event,heat,stroke,distance,gender} | setup {any of those}
//               split {lane,cid,t} | finish {lane,cid,t} | undo {lane,cid}
//               empty {lane,empty}   (mark a lane as having no swimmer this heat)

header('Content-Type: application/json');
header('Cache-Control: no-store');

const DATA_DIR = __DIR__ . '/data';
const MAX_TIMERS_PER_LANE = 3;
const STROKES = ['Free', 'Back', 'Breast', 'Fly', 'IM', 'Free Relay', 'Medley Relay'];
const GENDERS = ['bg' => ['Boys', 'Girls'], 'mw' => ['Men', 'Women'], 'none' => []];

// Trim user text to a max length (mbstring isn't always installed).
function clip($s, $n) {
    $s = trim((string) $s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

function now_ms() { return (int) round(microtime(true) * 1000); }

function fail($status, $msg) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

function meet_path($code) {
    if (!preg_match('/^[A-Z0-9]{4}$/', $code)) fail(400, 'Bad meet code');
    return DATA_DIR . "/$code.json";
}

// Sets whichever heat fields are given, ignoring invalid values.
function apply_heat_fields(&$heat, $f, $meet) {
    if (isset($f['event'])) $heat['event'] = min(max((int) $f['event'], 1), 999);
    if (isset($f['heat'])) $heat['heat'] = min(max((int) $f['heat'], 1), 999);
    if (isset($f['stroke'])) $heat['stroke'] = in_array($f['stroke'], STROKES, true) ? $f['stroke'] : '';
    if (isset($f['distance'])) $heat['distance'] = max(0, (int) $f['distance']);
    if (isset($f['gender'])) {
        $heat['gender'] = in_array($f['gender'], GENDERS[$meet['genders'] ?? 'none'] ?? [], true) ? $f['gender'] : '';
    }
}

function new_heat($meet, $f = []) {
    $heat = [
        'id' => now_ms(),
        'event' => 1,
        'heat' => 1,
        'stroke' => '',
        'gender' => '',
        'distance' => 0,
        'startedAt' => null,
        'lanes' => new stdClass(),
        'empty' => [],
    ];
    apply_heat_fields($heat, $f, $meet);
    return $heat;
}

// Phones send timestamps already converted to the server clock. Anything far
// off means a bad sync, so fall back to the time the request arrived.
function server_time($t) {
    $now = now_ms();
    return (is_numeric($t) && abs($t - $now) < 10000) ? (int) $t : $now;
}

// Swimming times are truncated (not rounded) to hundredths.
function elapsed($meet, $t) {
    return (int) (floor((server_time($t) - $meet['heat']['startedAt']) / 10) * 10);
}

// Returns a reference to this timer's record within the lane, creating it if needed.
function &timer_entry(&$meet, $body) {
    $heat = &$meet['heat'];
    if (!$heat['startedAt']) fail(409, 'Heat has not started');
    $lane = (string) (int) ($body['lane'] ?? 0);
    if ($lane < 1 || $lane > $meet['lanes']) fail(400, 'Bad lane');
    if (in_array((int) $lane, $heat['empty'] ?? [], true)) fail(409, 'Lane is marked empty');
    $cid = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($body['cid'] ?? ''));
    if ($cid === '') fail(400, 'Missing timer id');

    $lanes = (array) $heat['lanes'];
    if (!isset($lanes[$lane])) $lanes[$lane] = ['timers' => new stdClass()];
    $timers = (array) $lanes[$lane]['timers'];
    if (!isset($timers[$cid])) {
        if (count($timers) >= MAX_TIMERS_PER_LANE) fail(409, 'Lane already has ' . MAX_TIMERS_PER_LANE . ' timers');
        $timers[$cid] = ['splits' => [], 'final' => null];
    }
    $lanes[$lane]['timers'] = $timers;
    $heat['lanes'] = $lanes;
    return $heat['lanes'][$lane]['timers'][$cid];
}

$action = $_GET['a'] ?? '';

if ($action === 'time') {
    echo json_encode(['now' => now_ms()]);
    exit;
}

if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true)) fail(500, 'Cannot create data directory');

$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
}

if ($action === 'create') {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    } while (file_exists(meet_path($code)));

    $unit = ($body['unit'] ?? 'yd') === 'm' ? 'm' : 'yd';
    $genders = (string) ($body['genders'] ?? '');
    $meet = [
        'code' => $code,
        'name' => clip($body['name'] ?? '', 80) ?: 'Swim Meet',
        'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($body['date'] ?? '')) ? $body['date'] : date('Y-m-d'),
        'lanes' => min(max((int) ($body['lanes'] ?? 8), 1), 12),
        'pool' => min(max((int) ($body['pool'] ?? 25), 10), 100),
        'unit' => $unit,
        'genders' => isset(GENDERS[$genders]) ? $genders : 'none',
        'version' => 1,
        'history' => [],
    ];
    $meet['heat'] = new_heat($meet);
    file_put_contents(meet_path($code), json_encode($meet), LOCK_EX);
    echo json_encode(['code' => $code]);
    exit;
}

$code = strtoupper((string) ($_GET['code'] ?? ''));
$path = meet_path($code);
if (!file_exists($path)) fail(404, 'Meet not found');

if ($action === 'state') {
    $fh = fopen($path, 'r');
    flock($fh, LOCK_SH);
    $json = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $v = (int) ($_GET['v'] ?? 0);
    if ($v && preg_match('/"version":(\d+)/', $json, $m) && (int) $m[1] === $v) {
        echo '{"same":true}';
    } else {
        echo $json;
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST required');

$fh = fopen($path, 'c+');
flock($fh, LOCK_EX);
$meet = json_decode(stream_get_contents($fh), true);

switch ($action) {
    case 'start':
        if ($meet['heat']['startedAt']) fail(409, 'Heat already started');
        $meet['heat']['startedAt'] = server_time($body['t'] ?? null);
        break;

    case 'recall':
        $meet['heat']['startedAt'] = null;
        $meet['heat']['lanes'] = new stdClass();
        break;

    case 'setup':
        if ($meet['heat']['startedAt']) fail(409, 'Heat already started');
        apply_heat_fields($meet['heat'], $body, $meet);
        break;

    case 'next':
        $h = $meet['heat'];
        if ($h['startedAt'] || !empty($h['lanes'])) array_unshift($meet['history'], $h);
        $meet['heat'] = new_heat($meet, $body);
        break;

    case 'split':
        $e = &timer_entry($meet, $body);
        if ($e['final'] !== null) fail(409, 'Already finished');
        $e['splits'][] = elapsed($meet, $body['t'] ?? null);
        break;

    case 'finish':
        $e = &timer_entry($meet, $body);
        if ($e['final'] !== null) fail(409, 'Already finished');
        $e['final'] = elapsed($meet, $body['t'] ?? null);
        break;

    case 'empty':
        $lane = (int) ($body['lane'] ?? 0);
        if ($lane < 1 || $lane > $meet['lanes']) fail(400, 'Bad lane');
        $empty = array_values(array_diff($meet['heat']['empty'] ?? [], [$lane]));
        if (!empty($body['empty'])) {
            foreach (($meet['heat']['lanes'][$lane]['timers'] ?? []) as $t) {
                if ($t['splits'] || $t['final'] !== null) fail(409, 'Lane already has times recorded');
            }
            $empty[] = $lane;
            sort($empty);
        }
        $meet['heat']['empty'] = $empty;
        break;

    case 'undo':
        $e = &timer_entry($meet, $body);
        if ($e['final'] !== null) $e['final'] = null;
        else array_pop($e['splits']);
        break;

    default:
        fail(400, 'Unknown action');
}
unset($e);

$meet['version']++;
if (empty($meet['heat']['lanes'])) $meet['heat']['lanes'] = new stdClass();
$json = json_encode($meet);
ftruncate($fh, 0);
rewind($fh);
fwrite($fh, $json);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

echo '{"ok":true,"meet":' . $json . '}';
