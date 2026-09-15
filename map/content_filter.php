<?php
/**
 * MARS APRS — Objectionable-content filter for participant messages.
 *
 * What this is for, plainly: App Store Guideline 1.2 requires that an app carrying
 * user-generated content have "a method for filtering objectionable content". This is
 * that method. It is deliberately a small, legible word list rather than a service call
 * or a model — a filter that needs the internet is a filter that fails at the far end of
 * a race course, which is exactly where this app is used.
 *
 * What it is NOT is a claim to catch everything. A word list cannot, and anything that
 * claimed to would be lying. It is the first of three layers, and the weakest:
 *
 *   1. this filter, which stops the obvious case at the point of sending;
 *   2. per-participant blocking, so a recipient can end it themselves at once;
 *   3. reports to the event administrator, who can remove the message and eject the
 *      sender from the event entirely.
 *
 * Deliberately NOT applied to Transcriber log entries. Those are a machine's
 * transcription of what came over the air — a record of a transmission, not something a
 * participant chose to put in front of someone. Censoring the log would falsify it, and
 * the person who said it is identifiable by callsign and answerable under Part 97
 * anyway.
 *
 * Tuning it: the list below errs toward the unambiguous. A filter that rejects "damn" in
 * "the damn gate is locked" trains volunteers to fight the tool during an event, and a
 * tool people fight mid-net is worse than one that lets a rude word through.
 *
 * Docs: https://marsaprs.org
 * @author    Doug Kaye
 * @copyright 2026 Doug Kaye. All Rights Reserved.
 */

declare(strict_types=1);

/** Words that get a message refused. Lower case; matched whole-word, case-insensitively.
 *
 *  Slurs and sexual content only. Mild profanity is deliberately absent — see the note
 *  above about volunteers fighting the tool. An event that wants a stricter list can add
 *  to this array; it is the only thing that needs editing. */
const HT_BLOCKED_WORDS = [
    // Sexual content
    'anal', 'blowjob', 'cocksucker', 'cum', 'cunt', 'dildo', 'handjob',
    'porn', 'porno', 'pornography', 'rape', 'rapist',
    // Slurs. Listed so they can be refused, for no other reason.
    'chink', 'coon', 'dyke', 'fag', 'faggot', 'kike', 'nigger', 'nigga',
    'raghead', 'retard', 'retarded', 'spic', 'tranny', 'wetback',
    // Unambiguous profanity
    'motherfucker', 'fuck', 'fucking', 'fucked', 'shit', 'bullshit', 'bitch', 'whore',
];

/**
 * Whether `$text` may be sent, and which word stopped it.
 *
 * Returns null when the text is acceptable, or the offending word when it is not.
 *
 * Matching is whole-word so that "Scunthorpe" and "assessment" pass — the classic
 * failure of filters like this, and one that in an event context would mean rejecting
 * real traffic ("pass the class 3 assessment") for no reason a volunteer could guess.
 *
 * Leetspeak is folded first (`f*ck`, `sh1t`) because the trivial evasion is the common
 * one. Anything more determined than that gets through, and is what layers 2 and 3 are
 * for.
 */
function ht_filter_message(string $text): ?string
{
    if (trim($text) === '') return null;

    // Fold the obvious substitutions, then reduce to letters and single spaces. Done on
    // a copy: the message is stored exactly as typed if it passes, never rewritten.
    $probe = strtolower($text);
    $probe = strtr($probe, [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't',
        '@' => 'a', '$' => 's', '!' => 'i', '*' => '', '.' => '', '-' => '', '_' => '',
    ]);
    // Anything not a letter becomes a space, so word boundaries survive punctuation.
    $probe = preg_replace('/[^a-z]+/', ' ', $probe) ?? '';
    $words = array_filter(explode(' ', $probe), fn($w) => $w !== '');

    foreach ($words as $w) {
        if (in_array($w, HT_BLOCKED_WORDS, true)) return $w;
    }
    // Repeated letters ("fuuuuck") collapse to a single letter and are checked again.
    // Cheap, and it closes the other evasion anybody tries first.
    foreach ($words as $w) {
        $collapsed = preg_replace('/(.)\1+/', '$1', $w) ?? $w;
        if ($collapsed !== $w && in_array($collapsed, HT_BLOCKED_WORDS, true)) return $w;
    }

    // Self-censored spellings: "f*ck", "s#!t", "c--t". Handled separately, and only for
    // words the sender actually masked -- that masking is the whole signal.
    //
    // Each run of non-letters becomes a wildcard of one to three characters, and the
    // result is matched against the list: "s#!t" becomes /^s.{1,3}t$/, which "shit"
    // satisfies. Devowelling was tried first and cannot do this -- it recovers "f*ck",
    // where only the vowel is hidden, but not "s#!t", where a consonant is hidden too.
    //
    // This is far too blunt to run over ordinary words, which is why it does not:
    // /^c.{1,3}t$/ also matches "cat" and "coat". Requiring an embedded non-letter is
    // what keeps "good shot" and "count of riders" out of it -- nobody masks a word they
    // did not mean to mask.
    if (preg_match_all('/[a-z]+(?:[^a-z\s]+[a-z]+)+/i', $text, $m)) {
        foreach ($m[0] as $token) {
            $pattern = preg_replace('/[^a-z]+/', '.{1,3}', strtolower($token));
            if ($pattern === null || $pattern === '') continue;
            foreach (HT_BLOCKED_WORDS as $bad) {
                if (preg_match('/^' . $pattern . '$/', $bad) === 1) return $token;
            }
        }
    }
    return null;
}
