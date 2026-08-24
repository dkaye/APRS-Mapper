<?php
/**
 * APRS Tracker Map — which network an address belongs to, answered locally.
 *
 * Replaces a call to ip-api.com in the join handler. That call blocked the request for
 * 50-200 ms, capped the whole server at 45 lookups a minute however many devices were
 * joining, and sent each volunteer's IP address to a third party over plain HTTP. It is
 * now a binary search of a file built monthly by /home/pi/build-asn-table.py.
 *
 * COST. About 19 seeks of 16 bytes for the IPv4 table's 454k records, off a file the
 * kernel keeps in page cache and shares between processes. Well under a millisecond, and
 * cheaper than the 22 KB JSON decode of mobile_trackers.json the same request already
 * does — so this can be asked on every beacon, where the old one could not be asked twice.
 *
 * WHAT IT RETURNS. A name people recognise, not what the table says. The raw AS
 * descriptions are unusable: T-Mobile is "T-MOBILE-AS21928", Verizon Wireless is
 * "CELLCO-PART", Starlink is "SPACEX-STARLINK", and "COMCAST INDIA ENGINEERING CENTER"
 * has nothing to do with the Comcast a phone connects through. So known AS numbers are
 * mapped to names, and anything unknown falls back to the description tidied up.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * @author    Doug Kaye
 * @copyright 2026 Doug Kaye. All Rights Reserved.
 */

/**
 * Where the table lives, and the one place that decides it.
 *
 * A function rather than a constant so it can be pointed somewhere else at runtime: the
 * tests write an index per case and a constant can only be set once, so every case after
 * the first would have read the first one's directory — and, since that directory is
 * cleaned up, would have quietly tested nothing at all.
 */
function asn_dir(?string $set = null): string
{
    static $dir = null;
    if ($set !== null) $dir = $set;
    if ($dir === null) {
        $dir = defined('MARSAPRS_ASN_DIR') ? MARSAPRS_ASN_DIR
             : (getenv('MARSAPRS_ASN_DIR') ?: '/var/lib/marsaprs/asn/current');
    }
    return $dir;
}

/**
 * Well-known networks, by AS number.
 *
 * Only the ones a tracker in this part of the world actually joins through are listed;
 * everything else falls back to the table's own description, which is fine for a name
 * nobody is going to filter on. The names are chosen to match what the Analyzer's
 * "Cellular Carrier" list offers, because the same string has to serve a popup, an admin
 * panel and that filter — one spelling, decided here, is what keeps them agreeing.
 *
 * A carrier usually announces from several AS numbers (Verizon Wireless alone uses three),
 * which is exactly why the number is the key and the description is not.
 */
const ASN_NETWORK_NAMES = [
    // ── US mobile carriers ────────────────────────────────────────────────────
    21928 => 'T-Mobile',          // T-MOBILE-AS21928, also Sprint since the merger
    6167  => 'Verizon',           // CELLCO-PART (Verizon Wireless)
    22394 => 'Verizon',
    6142  => 'US Cellular',
    20057 => 'AT&T',              // ATT-MOBILITY-LLC
    2386  => 'AT&T',              // ATT-DATACOMM
    7018  => 'AT&T',              // ATT-INTERNET4
    // ── satellite ─────────────────────────────────────────────────────────────
    14593 => 'Starlink',          // SPACEX-STARLINK
    // ── US broadband, which is what a phone on house WiFi reports ─────────────
    7922  => 'Comcast',           // COMCAST-7922
    7015  => 'Comcast',
    33651 => 'Comcast',
    20115 => 'Spectrum',          // CHARTER-20115
    11427 => 'Spectrum',
    22773 => 'Cox',               // ASN-CXA-ALL-CCI-22773-RDC
    701   => 'Verizon',           // UUNET, Verizon's wireline side
    209   => 'CenturyLink',
    5650  => 'Frontier',
    46375 => 'AT&T',
    // ── the ones that turn up behind an event's WiFi ──────────────────────────
    15169 => 'Google',
    16509 => 'Amazon',
    13335 => 'Cloudflare',
];

/**
 * The network an IP address belongs to, or null.
 *
 * Null for anything the table cannot answer — a private address, a malformed one, a
 * missing table, an unrouted range. Callers treat that as "not known" and show nothing,
 * which is the honest outcome and the same one the old lookup produced on failure.
 */
function asn_lookup(string $ip): ?string
{
    $needle = @inet_pton($ip);
    if ($needle === false) return null;

    $v6   = strlen($needle) === 16;
    $alen = $v6 ? 16 : 4;
    $rec  = $alen * 2 + 8;                       // start, end, asn(4), name offset(4)
    $path = asn_dir() . '/' . ($v6 ? 'v6.idx' : 'v4.idx');

    $size = @filesize($path);
    if (!$size || $size < $rec) return null;
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;

    try {
        // Greatest record whose start is <= the address. Comparing the raw bytes IS
        // comparing the addresses: inet_pton gives network byte order, and a bytewise
        // comparison of big-endian numbers of equal width is a numeric one. That is what
        // lets v4 and v6 share this loop and keeps 128-bit addresses away from any
        // integer conversion.
        $lo = 0;
        $hi = intdiv($size, $rec) - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (fseek($fh, $mid * $rec) !== 0) return null;
            $start = fread($fh, $alen);
            if ($start === false || strlen($start) !== $alen) return null;
            if (strcmp($start, $needle) <= 0) { $found = $mid; $lo = $mid + 1; }
            else                              { $hi = $mid - 1; }
        }
        if ($found < 0) return null;

        // The ranges do not cover everything, so the record found may simply end before
        // the address. A gap is not a match.
        if (fseek($fh, $found * $rec + $alen) !== 0) return null;
        $tail = fread($fh, $alen + 8);
        if ($tail === false || strlen($tail) !== $alen + 8) return null;
        if (strcmp($needle, substr($tail, 0, $alen)) > 0) return null;

        $meta = unpack('Nasn/Noff', substr($tail, $alen));
        if (!$meta) return null;
        if (isset(ASN_NETWORK_NAMES[$meta['asn']])) return ASN_NETWORK_NAMES[$meta['asn']];
        return asn_tidy_description(asn_read_name($meta['off']));
    } finally {
        fclose($fh);
    }
}

/** One length-prefixed name out of the shared string table. */
function asn_read_name(int $offset): string
{
    $path = asn_dir() . '/names.bin';
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    try {
        if (fseek($fh, $offset) !== 0) return '';
        $len = fread($fh, 1);
        if ($len === false || $len === '') return '';
        $n = ord($len);
        return $n ? (string)fread($fh, $n) : '';
    } finally {
        fclose($fh);
    }
}

/**
 * An AS description made fit to read.
 *
 * The table writes them as registry handles — "COMCAST-7922", "ATT-MOBILITY-LLC" — so the
 * trailing AS number is dropped and the rest title-cased. Deliberately gentle: this is the
 * fallback for networks nobody has named, and mangling an unfamiliar one is worse than
 * showing it as the registry has it.
 */
function asn_tidy_description(string $desc): ?string
{
    $desc = trim($desc);
    if ($desc === '') return null;
    $desc = preg_replace('/[\s-]*AS\d+$/i', '', $desc);      // COMCAST-7922 → COMCAST
    $desc = preg_replace('/-\d+$/', '', $desc);
    $desc = trim($desc);
    if ($desc === '') return null;
    // Shouty registry handles read better in title case; anything already mixed-case was
    // written by a human and is left alone.
    if ($desc === strtoupper($desc) && preg_match('/[A-Z]{3}/', $desc)) {
        $desc = ucwords(strtolower($desc), " \t\r\n\f\v-");
    }
    return $desc;
}

/** Whether a usable table is present, for the self-test and for install checks. */
function asn_table_meta(): ?array
{
    $raw = @file_get_contents(asn_dir() . '/meta.json');
    if ($raw === false) return null;
    $meta = json_decode($raw, true);
    return is_array($meta) ? $meta : null;
}
