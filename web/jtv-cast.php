<?php
/**
 * jtv-cast.php - the letterbox between the website and the TV app.
 *
 * The two halves never speak to each other. The phone drops a note here, the
 * television picks it up a few seconds later, and the television leaves a note
 * saying what it is doing so the phone can stop guessing. That is all this is:
 * one short line per customer, in each direction.
 *
 * Endpoints, all on this one file:
 *
 *   GET  ?peek=USER&t=TOKEN      what is waiting, and what the TV is doing
 *   POST action=send             phone -> TV   "put channel 402 on"
 *   POST action=ack              TV confirms it took the command
 *   POST action=status           TV -> phone   "I am watching 402 right now"
 *
 * Deliberately not a login system. The token is a latch, not a lock: it stops
 * one customer idly changing another's channel, and that is the whole of the
 * threat here. The worst a determined person achieves is turning somebody
 * else's television over, and nothing in this file can reach an account, a
 * password, or a payment.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Above public_html, so a browser cannot read it directly.
define('STORE', dirname(__DIR__) . '/johnnytv-cast.json');

/** How long an unclaimed command stays interesting. Past this it is stale. */
define('COMMAND_TTL', 120);

/** How long after its last word we still believe a television is watching. */
define('STATUS_TTL', 90);

/** Entries untouched for this long are dropped, so the file never grows. */
define('FORGET_AFTER', 7 * 24 * 3600);

function tidy($s) { return strtolower(trim((string)$s)); }

function store_read() {
    if (!is_file(STORE)) return [];
    $raw = @file_get_contents(STORE);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function store_write($data) {
    // Forget anybody who has not been seen in a week.
    $now = time();
    foreach ($data as $user => $row) {
        $seen = max(
            isset($row['cmd']['at']) ? (int)$row['cmd']['at'] : 0,
            isset($row['tv']['at']) ? (int)$row['tv']['at'] : 0
        );
        if ($seen && $now - $seen > FORGET_AFTER) unset($data[$user]);
    }
    @file_put_contents(STORE, json_encode($data), LOCK_EX);
}

function out($x) { echo json_encode($x); exit; }

function fail($why) { out(['ok' => false, 'error' => $why]); }

/**
 * The latch.
 *
 * Both halves compute this from details they already hold, so nothing new has
 * to be stored anywhere and no password is ever sent to this server.
 */
function latch($user, $token) {
    $token = preg_replace('/[^a-z0-9]/', '', tidy($token));
    return $token === '' ? '' : $token;
}

$user = tidy(isset($_REQUEST['user']) ? $_REQUEST['user'] : (isset($_GET['peek']) ? $_GET['peek'] : ''));
$token = latch($user, isset($_REQUEST['t']) ? $_REQUEST['t'] : '');
if ($user === '') fail('no user');

$data = store_read();
$row = isset($data[$user]) ? $data[$user] : [];

// A username's first caller sets its latch; everyone after has to match it.
if (isset($row['t']) && $row['t'] !== '' && $token !== $row['t']) fail('mismatch');

$action = isset($_POST['action']) ? $_POST['action'] : '';
$now = time();

if ($action === 'send') {
    $id = preg_replace('/[^0-9]/', '', (isset($_POST['id']) ? $_POST['id'] : ''));
    if ($id === '') fail('no channel');
    $row['t'] = $token;
    $row['cmd'] = [
        'id'   => $id,
        'name' => mb_substr(trim((string)(isset($_POST['name']) ? $_POST['name'] : '')), 0, 80),
        'at'   => $now,
        'seq'  => (isset($row['cmd']['seq']) ? (int)$row['cmd']['seq'] : 0) + 1
    ];
    $data[$user] = $row;
    store_write($data);
    out(['ok' => true, 'seq' => $row['cmd']['seq']]);
}

if ($action === 'ack') {
    // The television took it. Clearing it stops the same command being obeyed
    // twice if the app restarts.
    $row['t'] = $token;
    unset($row['cmd']);
    $data[$user] = $row;
    store_write($data);
    out(['ok' => true]);
}

if ($action === 'status') {
    $row['t'] = $token;
    $row['tv'] = [
        'id'   => preg_replace('/[^0-9]/', '', (isset($_POST['id']) ? $_POST['id'] : '')),
        'name' => mb_substr(trim((string)(isset($_POST['name']) ? $_POST['name'] : '')), 0, 80),
        'at'   => $now
    ];
    $data[$user] = $row;
    store_write($data);
    out(['ok' => true]);
}

// Default: peek. Used by both halves, several times a minute, so it stays cheap.
$cmd = null;
if (isset($row['cmd']) && $now - (int)$row['cmd']['at'] <= COMMAND_TTL) $cmd = $row['cmd'];

$tv = null;
if (isset($row['tv']) && $now - (int)$row['tv']['at'] <= STATUS_TTL) {
    $tv = $row['tv'];
    $tv['ago'] = $now - (int)$row['tv']['at'];
}

out(['ok' => true, 'cmd' => $cmd, 'tv' => $tv, 'now' => $now]);
