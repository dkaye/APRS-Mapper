#!/usr/bin/env python3
# Tests for the Transcriber channel worker.
#
# The two that matter most are the ones guarding what reaches the log. whisper does not
# stay quiet when it hears nothing — fed squelch hiss it produces "Thank you." with
# complete confidence — and a log slowly filling with invented lines is worse than one
# that misses a transmission, because nobody thinks to question it.
#
# Runs without an SDR and without whisper: rtl_fm and sox are skipped via --spool-only,
# and whisper is a stub script whose output the test chooses.
#
# compare-models.py is covered here too, in the bench section. It is a bench tool rather
# than part of the service, but it is what decides whether the initial prompt ships, and
# a measurement nobody has checked is worse than none.
#
# Usage: python3 transcriber/tests/test_transcriber.py   (exit 0 = pass)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import json
import math
import os
import re
import struct
import sys
import tempfile
import threading
import time
import wave
from http.server import BaseHTTPRequestHandler, HTTPServer

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "bin"))
import transcriber  # noqa: E402

FAILURES = []


def check(label, got, want):
    if got != want:
        FAILURES.append(f"{label}: got {got!r}, want {want!r}")
    else:
        print(f"  ok  {label}")


# ── the guard against invented speech ────────────────────────────────────────

def test_worth_logging():
    print("worth_logging")
    for junk in ["", "  ", "you", "Thank you.", "THANK YOU", "thanks for watching!",
                 "[BLANK_AUDIO]", "Bye.", "...", "uh", "you you you you",
                 # whisper describing a sound rather than reporting speech. An open
                 # squelch on a quiet frequency produces these steadily — "(water
                 # splashing)" is a real one, off a real repeater.
                 "(water splashing)", "[MUSIC]", "(engine noise)", "( silence )",
                 # whisper's other house style for the same thing. These reached a real
                 # event log: a squelch crash as "*BANG*", static as "*gunshot*". Worse
                 # than a wrong entry, because it reads like something happened.
                 "*BANG*", "*gunshot*", "*static*", "♪♪♪", "♪ music ♪",
                 # A repeater's courtesy tone, landing in a clip of its own. Three entries
                 # reading exactly this reached a real event log; on a roll call with an
                 # over every few seconds there would have been dozens.
                 "Beep", "beep", "Beep.", "BEEP", "Tone", "chirp"]:
        check(f"discards {junk!r}", transcriber.worth_logging(junk), False)
    for real in ["aid three we have a rider down", "copy that sending medical",
                 # The word inside real speech is not the tone on its own.
                 "I heard the beep after your transmission", "what tone are you using",
                 "net control this is whiskey six sierra golf"]:
        check(f"keeps {real[:24]!r}", transcriber.worth_logging(real), True)


# The two whisper actually produced on this repeater, off the six-hour bench tape.
# Neither is caught by "every word is identical" — the model does not repeat words when
# it loops, it repeats phrases — and both would have been filed as real traffic.
LOOP_LOVE = (
    "I love it. I love it. I love it. It's good. I love it. I love it. I love it. "
    "I love it. I love it. I love it. You rap. Oh, damn. I love you. I love you. "
    "I love you. I love you. I love you. I love you. I love you. I love you."
)
LOOP_PIANO = (
    "I don't know if they have a piano, I don't think it's a place to get out of the "
    "way. I think it's a place to get out of the way. I think that's a place to get "
    "out of the way. I think that's a place to get out of the way. I think that's a "
    "place to get out of the way."
)


def test_a_looping_transcription_does_not_reach_the_log():
    """whisper's other failure on marginal audio: not one invented sentence but the
    same one over and over. The old rule only caught "you you you you" — every word
    identical — and the fast model does not do that. It repeats phrases."""
    print("loops — what must not reach the log")
    for junk in [LOOP_LOVE, LOOP_PIANO,
                 # More off the same tape, all of them from real captures.
                 "I'm going to be in the next control. I'm going to be in the next "
                 "control. I'm going to be in the next control. I'm going to be in "
                 "the next control.",
                 "I don't know if I'm going to be doing it for a while, but I don't "
                 "know if I'm going to be doing it for a while, but I don't know if "
                 "I'm going to be doing it for a while."]:
        check(f"rejects {junk[:28]!r}...", transcriber.loggable(junk), "")


def test_repetition_on_the_air_is_not_a_hallucination():
    """The expensive mistake would be the other one. Radio traffic repeats: a callsign
    said three times, "roger roger", "break break break", a number read back for
    clarity, net control working down a list. Losing one of those costs the log a line;
    the rule has to be about degenerate repetition — the same phrase many times over —
    and not about repetition."""
    print("loops — what must survive")
    for real in ["roger roger",
                 "break break break",
                 "K6DRK K6DRK K6DRK",
                 "say again, say again",
                 "No, wait, wait, wait, wait.",
                 "seven seven seven, that is seven seven seven",
                 # Off the same tape, inside a real conversation.
                 "That's right, that's right, that's right, that's right.",
                 "W6ABC W6ABC please come back. W6DEF W6DEF please come back. "
                 "W6GHI W6GHI please come back.",
                 "aid three we have a rider down"]:
        check(f"keeps {real[:28]!r}", transcriber.loggable(real), real)


def test_a_loop_on_the_end_is_trimmed_rather_than_thrown_away():
    """A transcription that worked and then stuck is a transmission somebody made with
    a groove on the end of it. Rejecting the entry loses what was said; keeping it whole
    files the invented sentence five times. Trim it."""
    print("loops — a real transmission with a groove on the end")
    text = ("K6DRK monitoring the repeater and I will be back on after the net this "
            "evening. I love you. I love you. I love you. I love you. I love you.")
    got = transcriber.loggable(text)
    check("keeps the transmission",
          got.startswith("K6DRK monitoring the repeater"), True)
    check("with one copy of the loop, not five", got.count("I love you"), 1)


def test_a_transcription_that_is_mostly_loop_is_rejected_whole():
    """And the decision that goes with it: trimming is for an entry that is mostly real,
    not for one that is mostly loop. Where the loop is most of the text the
    transcription failed, and its opening words are no more trustworthy than its last
    ones — "I don't know if they have a piano" is not a real sentence rescued from a bad
    recording, it is the same failure a few words earlier.

    Which is why the whole text is judged before anything is trimmed. The other order
    destroys the evidence: reduced to one copy each, LOOP_LOVE reads as an ordinary
    short entry, and the repetition was the only thing that showed it was invented.
    """
    print("loops — order of the two rules")
    trimmed = transcriber.collapse_loops(LOOP_LOVE)
    check("trimming alone would leave something that reads as real",
          len(trimmed.split()) < len(LOOP_LOVE.split()) and bool(trimmed), True)
    check("so the whole text is judged first, and rejected",
          transcriber.loggable(LOOP_LOVE), "")


# ── callsigns ────────────────────────────────────────────────────────────────

# What this event expects to hear, in the shape the manager delivers it.
ROSTER = {"callsigns": ["K6DRK", "KM6AOW", "W6ABC"],
          "tactical": ["Sweep 1", "Net Control"]}

# The other half of the same delivery: the place names the sheet states outright, because
# no pattern can find them, and a correction somebody wrote down after hearing one go
# wrong. All five of these are real Dipsea aid stations.
PLACES = {"callsigns": ["K6DRK"], "tactical": ["Net Control"],
          "terms": ["Windy Gap", "Cardiac", "Stinson Beach"],
          "corrections": {"cardiac hill": "Cardiac"}}


def vocab(d=None):
    return transcriber.Vocabulary(d)


def corrected(text, d=None):
    return transcriber.correct_callsigns(text, vocab(d))


def test_a_callsign_is_recognized_by_its_shape_not_by_how_many_words_it_took():
    """A US callsign is one or two letters, a digit, one to three letters — so three
    characters is a legal callsign and TWO phonetic words with a digit between them is a
    legitimate one. W6P is "whiskey six papa" and belongs to somebody.

    The first version of this required three or more phonetic words, which would have
    quietly dropped every 1x1 holder on the band. It tests the shape.
    """
    print("callsigns — the shape, not the word count")
    check("two phonetic words with a digit between them is a callsign",
          corrected("whiskey six papa listening"), "W6P listening")
    check("and so is the full five",
          corrected("kilo six delta romeo kilo monitoring"), "K6DRK monitoring")
    check("a written digit works as well as a spoken one",
          corrected("kilo mike 6 alpha oscar whiskey"), "KM6AOW")
    check("as does what whisper capitalizes for itself",
          corrected("K-6 DRK on West Marin"), "K6DRK on West Marin")
    # Two letters, a digit, and nothing after it is not a callsign, whatever it sounds
    # like — the shape needs a letter on the far side of the digit.
    check("but a letter and a digit alone is not", corrected("alpha six"), "alpha six")
    check("nor is a digit and a letter", corrected("six alpha"), "six alpha")

    # And the threshold has to sit clear of the alphabet it is telling apart, or a
    # mangled phonetic word could land on the wrong letter and produce a callsign that
    # is well-formed, plausible, and somebody else's.
    worst = max(
        __import__("difflib").SequenceMatcher(None, a, b).ratio()
        for a in transcriber.NATO for b in transcriber.NATO
        if transcriber.NATO[a] != transcriber.NATO[b])
    check("no two phonetic letters are within the threshold of each other",
          worst < transcriber.SIMILARITY, True)


def test_a_short_roster_word_does_not_eat_ordinary_english():
    """The one that got into a real log.

    A roll-call roster carries first names, and a first name is short. "and" against the
    roster name "Andy" is a single inserted character and scores 0.857 — past SIMILARITY
    — so a live net entry came out as "at least 125 hundred Andy fifty". That is the
    failure this file guards against everywhere else: a wrong name reads as
    authoritative and nobody questions it, while a mangled one warns you itself.

    A single ratio is systematically too generous at the short end, because one
    character is a much larger share of a short word. Below SHORT_EXACT, exact or
    nothing. The rest of this checks that the fix took nothing useful with it."""
    print("callsigns — short roster words")
    d = {"terms": ["Andy", "Alexander", "Cardiac", "Pantoll"],
         "tactical": ["SAG", "Net Control"], "callsigns": ["K6DRK"]}

    check("'and' is not the name Andy",
          corrected("at least 125 hundred and fifty", d),
          "at least 125 hundred and fifty")
    check("nor is 'sack' the tactical call SAG", corrected("the sack is full", d),
          "the sack is full")
    check("nor 'are' anything at all", corrected("are you there", d), "are you there")

    # What the fix must not have cost: the name said properly, and the fuzzy matching
    # that earns its place on longer entries.
    check("the name still lands when it is actually said",
          corrected("this is Andy mobile", d), "this is Andy mobile")
    check("a long place name still tolerates a mishearing",
          corrected("we are at cardiack now", d), "we are at Cardiac now")
    check("and a word whisper split in two is still joined",
          corrected("meet at pan toll", d), "meet at Pantoll")
    check("callsigns are untouched by any of it",
          corrected("K6 DRK testing", d), "K6DRK testing")


def test_ordinary_speech_is_not_turned_into_a_callsign():
    """The expensive mistake, and the one this feature invites. "six" and "alpha" are
    ordinary words that people say on the radio all day, and a rule that reaches for a
    callsign whenever it sees one would rewrite the traffic it was meant to clarify.

    Every line here is real net traffic or comes off this repeater's own tape."""
    print("callsigns — what must not be touched")
    for real in ["we have six alpha riders at the aid station",
                 "aid three we have a rider down",
                 "copy that sending medical",
                 "roger roger",
                 "break break break",
                 "seven seven seven, that is seven seven seven",
                 "That's right, that's right, that's right, that's right.",
                 "No, wait, wait, wait, wait.",
                 # A callsign next to a short word: the word must not be swallowed into
                 # it. "K6DRK on" scores 0.83 against K6DRK on its own, which is easily
                 # enough to eat the "on" if spans are matched without care.
                 "K6DRK I am mobile at the start",
                 "K6DRK on West Marin",
                 # Three callsigns in a row are three callsigns, not one long one.
                 "K6DRK K6DRK K6DRK"]:
        check(f"leaves {real[:34]!r}", corrected(real, ROSTER), real)


def test_the_event_vocabulary_answers_what_a_guess_only_asks():
    """Knowing the roster turns "did I hear a callsign" into "which of these did I hear",
    which is a far easier question and a far safer answer. All four spellings below came
    off this receiver, and all four are the same station."""
    print("callsigns — matched against the event's own list")
    check("exactly what was heard, joined up",
          corrected("K-6 DRK testing on West Marin K-6 DRK", ROSTER),
          "K6DRK testing on West Marin K6DRK")
    check("a wrong character is corrected to the station on the list",
          corrected("K-60RK", ROSTER), "K6DRK")
    check("punctuation stays where it was",
          corrected("K-60RK, are you mobile?", ROSTER), "K6DRK, are you mobile?")
    # Tactical calls are the same idea for phrases. "sweet one" is a real mishearing of
    # a real tactical call, and it is only correctable because the event has a Sweep 1.
    check("a tactical call is matched the same way",
          corrected("sweet one is clear of the course", ROSTER),
          "Sweep 1 is clear of the course")
    check("and written the way the event writes it",
          corrected("net control this is whiskey six sierra golf", ROSTER),
          "Net Control this is W6SG")


def test_a_partial_match_is_left_exactly_as_it_was_heard():
    """A wrong callsign in a log is worse than a mangled one. "K-60RK" is obviously
    damaged and anybody reading it knows to be careful; "KM6AOW" is authoritative, and
    if it is the wrong station nobody will ever find out from the log.

    So nothing here guesses. Four ways of not knowing, all of which leave the words
    alone."""
    print("callsigns — the cases where guessing is the failure")
    check("text that is not close to anything on the list",
          corrected("K-60 Arcade", ROSTER), "K-60 Arcade")
    check("a phonetic run that does not spell a callsign",
          corrected("6 delta rho mu", ROSTER), "6 delta rho mu")

    # Two roster entries the same distance away is a coin toss, and a coin toss recorded
    # as a fact is exactly what must not happen. K6DR0 is 0.80 from both.
    check("two equally good answers means no answer",
          corrected("K-6DR0 mobile", {"callsigns": ["K6DRK", "K6DRJ"]}), "K-6DR0 mobile")

    # And the one that matters most in the field: the roster is never the whole band.
    # K6DRJ scores 0.80 against K6DRK, so a visitor one letter away from a club member
    # would be logged as the club member. A well-formed callsign is not evidence of
    # mangling — it is a callsign.
    check("a valid callsign that is not on the list is still that callsign",
          corrected("K6DRJ mobile", ROSTER), "K6DRJ mobile")
    check("even when the list has a near neighbour",
          corrected("W6ABD standing by", ROSTER), "W6ABD standing by")


def test_an_event_with_no_vocabulary_is_the_normal_case():
    """Both keys are optional and most events have neither. Nothing here may treat that
    as an error, and the shape rule still has to work without a list — it is all a first
    event on a new frequency ever has."""
    print("callsigns — no vocabulary at all")
    for empty in [None, {}, {"callsigns": [], "tactical": []},
                  {"callsigns": None, "tactical": None},
                  # And the four-key shape with nothing in it, which is what a device gets
                  # from a server whose sheet has no Vocabulary section — the state every
                  # event is in until somebody adds one.
                  {"callsigns": [], "tactical": [], "terms": [], "corrections": {}},
                  {"terms": None, "corrections": None},
                  # A registry hand-edited into the wrong shape. This runs on a receiver in
                  # a shed; it may do nothing, but it may not raise.
                  {"terms": "Windy Gap", "corrections": ["cardiff", "Cardiac"]}]:
        v = vocab(empty)
        check(f"{empty} is an empty vocabulary, not a failure", bool(v), False)
        check("and the shape rule still applies",
              transcriber.correct_callsigns("whiskey six papa listening", v),
              "W6P listening")
        check("while nothing is invented to match",
              transcriber.correct_callsigns("K-60RK", v), "K-60RK")
    check("and no vocabulary at all is the same as an empty one",
          transcriber.correct_callsigns("kilo six delta romeo kilo"), "K6DRK")

    # The config file need not mention it, which is the shape of every channels.json
    # written before this existed.
    with tempfile.TemporaryDirectory() as tmp:
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1", "frequency": "1", "serial": "1"}]}, fh)
        ch = transcriber.load_channel(config, "rx1")
        check("a config with no vocabulary key loads", bool(ch.vocabulary), False)

        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1", "frequency": "1", "serial": "1"}],
                       "vocabulary": ROSTER}, fh)
        ch = transcriber.load_channel(config, "rx1")
        check("and one with it reaches the channel", ch.vocabulary.callsigns[0], "K6DRK")


def test_a_stated_term_is_matched_like_a_tactical_call():
    """Place names are the one thing no pattern on the sheet can find — "Windy Gap",
    "Bootjack", "Stinson Beach" are multi-word proper nouns with no shape to them — so the
    sheet states them outright and they arrive as `terms`.

    They are the same kind of thing as a tactical call: a phrase this event expects to
    hear. So they go through the same matcher, which already handles multi-word spans, and
    nothing new had to be invented for them."""
    print("callsigns — the terms the sheet states outright")
    check("a stated place name is written the way the sheet writes it",
          corrected("we are at windy gap with the runners", PLACES),
          "we are at Windy Gap with the runners")
    check("and a near miss is corrected to it, the way a tactical call is",
          corrected("windy cap is clear", PLACES), "Windy Gap is clear")
    check("a three-word term is one span",
          corrected("moving to stinson beach now", PLACES), "moving to Stinson Beach now")
    check("terms and tactical calls live together",
          corrected("net control this is windy gap", PLACES),
          "Net Control this is Windy Gap")
    check("and nothing that is not close to one is touched",
          corrected("we are at the top of the swoop", PLACES),
          "we are at the top of the swoop")

    # The case a list of place names introduces and a list of tactical calls never did:
    # one-word terms. difflib scores "at cardiac" against "cardiac" at 0.82, because one
    # short extra word barely moves the ratio — so the term eats the word in front of it
    # and the log quietly loses a word. A span is compared only with phrases of the same
    # number of words, which is what stops it.
    short = {"terms": ["Cardiac", "Stinson Beach"]}
    check("a one-word term does not swallow the word before it",
          corrected("we are at cardiac", short), "we are at Cardiac")
    check("nor the word after it",
          corrected("cardiac copies that", short), "Cardiac copies that")

    # The one thing whisper reliably does to a place name is split it, and that is not a
    # mishearing — it is the same letters in the same order. Handled exactly, so the guard
    # above costs nothing.
    split = {"terms": ["Bootjack", "Pantoll", "Stinson Beach"]}
    check("a place name whisper split in two is put back together",
          corrected("boot jack copies", split), "Bootjack copies")
    check("and the capitalized version of the same",
          corrected("we are at Pan Toll", split), "we are at Pantoll")
    check("but joining is exact, so it does not reach across a real word",
          corrected("at stinson beach", split), "at Stinson Beach")


def test_a_correction_is_exact_and_never_fuzzy():
    """A correction is somebody writing down a mishearing they actually heard: "Cardiac"
    is coming out as "Cardiff", so `Cardiff = Cardiac` goes in the box.

    It matches the normalized form exactly and in no other way. A fuzzy correction rule is
    a footgun of a different order from a fuzzy vocabulary match: the vocabulary can only
    ever rewrite text into a callsign or a phrase the event actually uses, while a rule
    says "replace this with that" and one typo'd entry would rewrite unrelated traffic
    into whatever its author had in mind. One character out and it does nothing."""
    print("callsigns — corrections, which do not guess")
    only = {"corrections": {"cardiff": "Cardiac"}}
    check("the form that was written down is corrected",
          corrected("cardiff is clear of the course", only), "Cardiac is clear of the course")
    check("punctuation stays where it was",
          corrected("say again, cardiff?", only), "say again, Cardiac?")
    # One character away is exactly what the fuzzy matcher takes, and exactly what this
    # must not.
    check("one character away is left alone", corrected("cardif is clear", only),
          "cardif is clear")
    check("and so is a longer word that contains it",
          corrected("cardiffs are clear", only), "cardiffs are clear")

    multi = {"corrections": {"cardiac hill": "Cardiac"}}
    check("a correction can span words", corrected("we are at cardiac hill", multi),
          "we are at Cardiac")
    check("but only the words it names",
          corrected("we are at cardiac hills", multi), "we are at cardiac hills")


def test_a_correction_is_applied_before_the_vocabulary_and_not_after():
    """The ordering, which is the whole of what makes a correction worth having.

    A correction is an instruction from somebody who watched the log get it wrong. The
    vocabulary matcher is a guess — a good one, but a guess — and it runs over the same
    words. If it went first it would consume the text the rule names and the rule would
    silently never fire, which is the failure the box exists to fix.

    Here the sheet lists "Cardiac Hill" as a term and the operator wants the log to say
    "Cardiac". Both orders produce a defensible answer; only one of them does what the
    person typing was asking for."""
    print("callsigns — a correction outranks a guess")
    sheet = {"terms": ["Cardiac Hill", "Cardiac"]}
    check("the sheet's own term is what the matcher would give",
          corrected("we are at cardiac hill", sheet), "we are at Cardiac Hill")
    check("and the correction overrides it",
          corrected("we are at cardiac hill", dict(sheet, corrections={"cardiac hill": "Cardiac"})),
          "we are at Cardiac")
    # The other side of the same ordering: a rule naming something the roster knows
    # exactly still wins, because it was typed on purpose.
    check("even over an exact callsign the event knows",
          corrected("K6DRK mobile", {"callsigns": ["K6DRK"],
                                     "corrections": {"k6drk": "K6DRK/M"}}),
          "K6DRK/M mobile")


def test_the_initial_prompt_is_off_unless_a_channel_asks_for_it():
    """Priming whisper with the roster makes it likelier to emit those exact words —
    which is the point, and the danger. The worst failure this device has is confident
    text invented from static, and "KM6AOW mobile" off a hiss burst passes every filter
    downstream, because it is a short, unrepetitive, entirely reasonable sentence.

    So it ships off. It is turned on for one channel, measured with compare-models.py
    against that channel's real traffic AND against real static, and only then argued
    about."""
    print("whisper — the initial prompt")
    base = {"id": "rx1", "frequency": "1", "serial": "1"}
    check("off by default", transcriber.Channel(base).initial_prompt, False)
    check("on only when the channel says so",
          transcriber.Channel(dict(base, initial_prompt=True)).initial_prompt, True)

    v = vocab(ROSTER)
    check("the prompt names the stations", "K6DRK" in v.prompt(), True)
    check("and the tactical calls", "Sweep 1" in v.prompt(), True)
    check("an empty vocabulary has nothing to prompt with", vocab().prompt(), "")

    # The stated terms belong in it more than anything else does. A place name is the case
    # the prompt was meant for: whisper has no reason to reach for "Bootjack" and every
    # reason to reach for "boot jack".
    p = vocab(PLACES).prompt()
    check("and the terms the sheet stated", "Stinson Beach" in p, True)
    # But not the mishearings. A correction's heard-form is what went wrong; priming the
    # model with it would make it likelier to produce the very text being corrected.
    check("while a correction's heard-form is not in it", "cardiac hill" in p.lower(), False)

    # whisper.cpp truncates at n_text_ctx/2 and drops the tail silently, so the trimming
    # happens here, where it can drop whole callsigns instead of half of one.
    crowded = vocab({"callsigns": ["K6DRK%02d" % i for i in range(200)]}).prompt()
    check("a long roster is trimmed to the token budget",
          len(crowded) // 2 <= transcriber.PROMPT_MAX_TOKENS, True)
    check("dropping whole callsigns, not half of one",
          crowded.endswith("."), True)
    check("and every term in it is one somebody could say",
          all(t.strip(" .") in ["K6DRK%02d" % i for i in range(200)]
              for t in crowded.split(":")[1].split(",")), True)


def test_the_prompt_flags_are_only_passed_to_a_build_that_has_them():
    """whisper.cpp treats an unknown option as fatal — usage and a non-zero exit — so a
    flag passed blind turns better text into a channel that transcribes nothing at all,
    on the oldest device and the one least likely to be watched.

    --carry-initial-prompt is asked about separately from --prompt because it is the
    newer of the two, and a build can have one without the other. Our clips run past one
    30-second window, so without it the prompt only reaches the first."""
    print("whisper — the prompt flags")

    class Ran:
        returncode, stdout, stderr = 0, "K6DRK on West Marin", ""

    def whisper_that(help_text):
        def run(argv, **kw):
            if argv[1:] == ["-h"]:
                return type("Help", (), {"returncode": 0, "stdout": help_text,
                                         "stderr": ""})()
            seen.append(argv)
            return Ran()
        return run

    both = ("  --prompt PROMPT        initial prompt (max n_text_ctx/2 tokens)\n"
            "  --carry-initial-prompt  [false] always prepend initial prompt\n")
    older = "  --prompt PROMPT        initial prompt (max n_text_ctx/2 tokens)\n"
    ancient = "  -np,  --no-prints      [false] do not print anything\n"

    real = transcriber.subprocess.run
    try:
        for label, help_text, prompt, want in [
                ("no prompt means no flags", both, None, []),
                ("both flags on a build that has both", both, "K6DRK",
                 [transcriber.PROMPT_FLAG, transcriber.CARRY_PROMPT]),
                ("only the one an older build advertises", older, "K6DRK",
                 [transcriber.PROMPT_FLAG]),
                ("and neither on a build that has never heard of them", ancient,
                 "K6DRK", [])]:
            seen = []
            transcriber._flag_support.clear()
            transcriber.subprocess.run = whisper_that(help_text)
            transcriber.transcribe("whisper-cli", "model.bin", "clip.wav", prompt=prompt)
            check(label,
                  [f for f in seen[0]
                   if f in (transcriber.PROMPT_FLAG, transcriber.CARRY_PROMPT)], want)
            if prompt and transcriber.PROMPT_FLAG in seen[0]:
                check("  the prompt itself follows the flag",
                      seen[0][seen[0].index(transcriber.PROMPT_FLAG) + 1], prompt)
    finally:
        transcriber.subprocess.run = real
        transcriber._flag_support.clear()


# ── the bench tool ───────────────────────────────────────────────────────────
#
# compare-models.py is where the initial prompt above is decided, so its two answers have
# to be trustworthy: that the prompt it measures is the one the device would actually
# use, and that a line invented from static is recognized as invented. Neither needs a
# radio to check.

_BENCH = []


def bench():
    """compare-models.py, whose file name is not an importable one."""
    if not _BENCH:
        import importlib.util
        path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "bin",
                            "compare-models.py")
        spec = importlib.util.spec_from_file_location("compare_models", path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        _BENCH.append(module)
    return _BENCH[0]


def bench_channel(vocabulary=None):
    return transcriber.Channel({"id": "rx1-146700", "frequency": "146700000",
                                "serial": "1", "model": "ggml-base.en.bin"}, vocabulary)


def test_the_bench_compares_configurations_and_not_only_models():
    """Two arms that differ by the model, or two that differ by the prompt — one capture
    either way, because two dongles hear different things and the difference in the text
    would be confounded with a difference in what arrived.

    The default stays the model comparison: that invocation is in the README and predates
    this."""
    print("bench — what the two arms differ by")
    b = bench()
    args = b.parser().parse_args(["--channel", "rx1", "--clips", "10", "--minutes", "20"])
    check("the documented invocation still parses",
          (args.clips, args.minutes), (10, 20.0))
    check("and still compares models", args.compare, "models")

    channel = bench_channel(vocab(PLACES))
    models = b.arms_for("models", channel, "channels.json")
    check("the model arms are the two models",
          [a.model for a in models], [f for _, f in b.MODELS])
    check("and neither is primed", [a.prompt for a in models], [None, None])

    primed = b.arms_for("prompt", channel, "channels.json")
    check("the prompt arms are one model run twice",
          [a.model for a in primed], ["ggml-base.en.bin"] * 2)
    check("the first plain", primed[0].prompt, None)
    # The measurement is worth nothing if it is of a lookalike. This has to be the exact
    # string the worker would hand whisper, built by the worker's own Vocabulary from the
    # same file the device reads — token budget, excluded corrections and all.
    check("the second primed with exactly what the worker would use",
          primed[1].prompt, channel.vocabulary.prompt())
    check("and a model can be named for both arms",
          [a.model for a in b.arms_for("prompt", channel, "x", "ggml-tiny.en.bin")],
          ["ggml-tiny.en.bin"] * 2)


def test_a_prompt_comparison_says_when_there_is_nothing_to_prime_with():
    """An event with no vocabulary is the normal case, and there a prompt comparison is
    the same run twice. It would report "identical on 6 of 6", which reads as a result and
    means only that nothing was measured — so it refuses instead, and says where it
    looked."""
    print("bench — an empty vocabulary")
    b = bench()
    try:
        b.arms_for("prompt", bench_channel(vocab()), "/etc/transcriber/channels.json")
        FAILURES.append("empty vocabulary: expected SystemExit")
    except SystemExit as e:
        check("refuses rather than measuring nothing", "no vocabulary" in str(e), True)
        check("and says which file it read",
              "/etc/transcriber/channels.json" in str(e), True)


def test_a_line_off_static_that_names_the_roster_is_the_finding():
    """The veto condition. "K6DRK at Cardiac" invented from hiss is far worse than a
    mangled callsign: it is plausible, it names a real person and a real place, and
    nobody reading the log has any reason to doubt it."""
    print("bench — invented traffic")
    b = bench()
    v = vocab(PLACES)
    check("ordinary invention names nobody",
          b.names_from("thank you for watching", v), [])
    check("a roster name is the finding",
          b.names_from("K6DRK at Cardiac", v), ["K6DRK", "Cardiac"])
    # The entry that matters most arrives mangled, and the worker would write it down
    # properly before anybody read it — so the check runs over the corrected text rather
    # than looking for the literal string.
    check("even spelled the way whisper spells it",
          b.names_from("kilo six delta romeo kilo at cardiac hill", v),
          ["K6DRK", "Cardiac"])
    # A callsign the event never listed is not this event's traffic. It is still junk in
    # the log, and it is reported as such; it is not the veto.
    check("a callsign nobody listed is not one of these names",
          b.names_from("W6XYZ mobile", v), [])
    check("nor is half a place name", b.names_from("the beach was crowded", v), [])


def test_the_static_report_calls_out_what_was_invented_and_clears_what_was_not():
    """Zero on both arms is the outcome the prompt needs, and it has to be stated as such
    — the point of the pass is to produce a decision, not a table."""
    print("bench — the static verdict")
    b = bench()
    arms = [b.Arm("Plain", "m", None), b.Arm("Primed", "m", "p")]
    quiet = {a.name: b.Result("", "", 1.0, []) for a in arms}

    lines = b.static_lines(arms, [dict(quiet) for _ in range(8)], 10.0, vocab(PLACES))
    check("silence on both arms clears the prompt",
          any("clears the prompt to ship" in ln for ln in lines), True)

    invented = dict(quiet)
    invented["Primed"] = b.Result("K6DRK at Cardiac", "K6DRK at Cardiac", 1.0,
                                  ["K6DRK", "Cardiac"])
    lines = b.static_lines(arms, [dict(quiet), invented], 10.0, vocab(PLACES))
    check("one named entry is the veto", any("veto" in ln for ln in lines), True)
    check("and it says which words were named",
          any("K6DRK" in ln for ln in lines), True)
    check("nothing is cleared while that stands",
          any("clears the prompt" in ln for ln in lines), False)

    # Junk from noise is a different finding from fiction from noise. Both are worth
    # reading; only one of them names somebody.
    junk = dict(quiet)
    junk["Primed"] = b.Result("all right then", "all right then", 1.0, [])
    lines = b.static_lines(arms, [junk], 10.0, vocab(PLACES))
    check("noise in the log without a name is said differently",
          any("junk rather" in ln for ln in lines), True)


def test_the_traffic_summary_separates_what_correction_can_fix():
    """The crux of the prompt decision. The worker corrects callsigns AFTER
    transcription, so a prompt only earns its risk where it rescues something correction
    cannot fix afterwards — a difference the correction pass closes by itself cost the
    log nothing and bought it nothing."""
    print("bench — raw against corrected")
    b = bench()
    arms = [b.Arm("Plain", "m", None), b.Arm("Primed", "m", "p")]
    closed = {"seconds": 10.0,
              "Plain": b.Result("K-6 DRK mobile", "K6DRK mobile", 3.0, ["K6DRK"]),
              "Primed": b.Result("K6DRK mobile", "K6DRK mobile", 3.0, ["K6DRK"])}
    lines = b.traffic_lines(arms, [closed])
    check("a difference correction closes was bought for nothing",
          any("correct_callsigns closes" in ln for ln in lines), True)

    survives = dict(closed,
                    Primed=b.Result("K6DRK at Cardiac", "K6DRK at Cardiac", 3.0, []))
    lines = b.traffic_lines(arms, [survives])
    check("one that survives correction is the thing being bought",
          any("still different after correction" in ln for ln in lines), True)


def test_the_static_pass_takes_noise_the_channel_would_never_record():
    """The receiver does not record silence any more — the gain is pinned and the squelch
    gates properly, so a quiet frequency yields no clips at all. That is correct, and it
    removes the very thing a prompt has to be measured against.

    So this pass opens the gate on purpose. -l 0 is not a low threshold but the absence
    of one: rtl_fm then gates nothing and emits at the full rate, on the channel's own
    frequency, and the stream is cut into clips the length of an over."""
    print("bench — capturing static on purpose")
    b = bench()
    import subprocess as sp
    seen = {}
    real = b.subprocess.Popen

    def fake_popen(argv, stdout=None, stderr=None):
        # The real Popen, captured before the stub replaced it — a fake radio is still a
        # process, and calling the name would call this.
        seen["argv"] = argv
        return real(emitter([(30.0, True, 40)]), stdout=stdout, stderr=sp.DEVNULL)

    b.subprocess.Popen = fake_popen
    try:
        with tempfile.TemporaryDirectory() as d:
            clips = list(b.capture_noise(bench_channel(), d, 0.5, 2,
                                         __import__("time").time() + 20))
            lengths = [transcriber.clip_seconds(p) for p in clips]
    finally:
        b.subprocess.Popen = real

    argv = seen.get("argv", [])
    check("the gate is off, not merely low", argv[argv.index("-l") + 1], "0")
    check("on the channel's own frequency", argv[argv.index("-f") + 1], "146700000")
    check("two clips of noise", len(lengths), 2)
    check("each the length asked for", all(0.45 <= s <= 0.65 for s in lengths), True)


# ── capture must not wait for transcription ──────────────────────────────────

def test_transcription_runs_off_the_capture_thread():
    """A slow model must cost latency, not transmissions.

    While whisper ran inline, nothing drained rtl_fm's pipe — 64 KB, about two seconds
    of audio — after which rtl_fm blocks on write, stops reading the SDR, and the
    samples are gone. The careful model runs slower than real time, so it would have
    lost overs outright rather than merely lagging.

    Here the "transcription" sleeps far longer than the clips take to arrive. What
    matters is that enqueueing never blocks and everything is eventually processed.
    """
    print("capture vs transcription")
    import time as _time
    work, stopping, done = transcriber.ClipQueue(), threading.Event(), []

    def slow(channel, path, whisper, model, outbox, retention=None):
        _time.sleep(0.15)                   # far slower than clips arrive
        done.append(path)

    real, transcriber.handle_clip = transcriber.handle_clip, slow
    try:
        worker = threading.Thread(target=transcriber.transcribe_loop,
                                  args=(work, None, None, None, _FakeOutbox(), stopping),
                                  daemon=True)
        worker.start()
        t0 = _time.time()
        for i in range(10):
            work.put(f"clip_{i}.wav")       # capture keeps going regardless
        enqueue_time = _time.time() - t0
        check("enqueueing 10 clips is instant", enqueue_time < 0.05, True)

        deadline = _time.time() + 10
        while len(done) < 10 and _time.time() < deadline:
            _time.sleep(0.02)
        check("all ten are transcribed", len(done), 10)
        check("and in order", done, [f"clip_{i}.wav" for i in range(10)])
        stopping.set(); worker.join(timeout=5)
    finally:
        transcriber.handle_clip = real


class _FakeOutbox:
    def flush(self, post):
        return True


# ── the backlog is in RAM now, so it has to be bounded ───────────────────────

def test_backlog_is_bounded_by_size_not_just_count():
    """Clips moved to tmpfs, which is RAM. A clip count bounds nothing on its own: at
    MAX_CLIP_SECONDS one clip is nearly 4 MB, so a hundred would be more than /run holds
    and the channel would die of ENOSPC instead of merely running behind."""
    print("clip backlog")
    with tempfile.TemporaryDirectory() as d:
        def clip(i, kb):
            path = os.path.join(d, f"clip_{i:05d}.wav")
            with open(path, "wb") as fh:
                fh.write(b"\0" * (kb * 1024))
            return path

        q = transcriber.ClipQueue(max_clips=100, max_bytes=300 * 1024)
        paths = [clip(i, 100) for i in range(5)]         # 500 KB against a 300 KB budget
        for p in paths:
            q.put(p)
        check("keeps only what fits", len(q), 3)
        check("and deletes what it dropped", os.path.exists(paths[0]), False)
        check("keeping the newest", q.get(0.1), paths[2])

        # The count limit still applies on its own, for many small clips.
        q = transcriber.ClipQueue(max_clips=3, max_bytes=1 << 30)
        small = [clip(100 + i, 1) for i in range(6)]
        for p in small:
            q.put(p)
        check("count limit still bites", len(q), 3)
        check("oldest gone", [os.path.basename(p) for p in small if os.path.exists(p)],
              ["clip_00103.wav", "clip_00104.wav", "clip_00105.wav"])

        # And a backlog that drains must free its budget again, or the queue would
        # slowly convince itself it was full and start dropping everything.
        q = transcriber.ClipQueue(max_clips=100, max_bytes=300 * 1024)
        for i in range(20):
            p = clip(200 + i, 100)
            q.put(p)
            check_quiet(q.get(0.1) == p)
        check("draining frees the budget", len(q), 0)


def check_quiet(ok):
    if not ok:
        FAILURES.append("a drained clip was dropped instead of returned")


def test_start_capture_builds_a_command_and_keeps_the_two_directories_straight():
    """start_capture was the one function no test ever called, because it needs an SDR.
    It did not need one to catch what actually shipped: a renamed parameter left a stale
    reference behind, and the channel died with a NameError on the Pi. Stubbing Popen
    exercises the whole body for the cost of six lines.

    It also pins the split that is easy to get backwards — clips and rtl_fm's chatter in
    RAM, the measured calibration on the card, where it is the only record of what this
    site was measured at.
    """
    print("start_capture")
    with tempfile.TemporaryDirectory() as d:
        clips, spool = os.path.join(d, "run"), os.path.join(d, "spool")
        os.makedirs(clips), os.makedirs(spool)
        with open(os.path.join(spool, "calibration.json"), "w") as fh:
            json.dump({"gain": 30, "squelch": 40, "when": __import__("time").time(),
                       "frequency": "147465000"}, fh)

        seen = {}

        class FakePopen:
            def __init__(self, argv, stdout=None, stderr=None):
                seen["argv"], seen["stderr"] = argv, stderr

        real, transcriber.subprocess.Popen = transcriber.subprocess.Popen, FakePopen
        try:
            channel = transcriber.Channel({"id": "rx1-147465", "frequency": "147465000",
                                           "serial": "56052444", "squelch": 0})
            transcriber.start_capture(channel, clips, spool)
        finally:
            transcriber.subprocess.Popen = real

        argv = seen["argv"]
        check("addresses the dongle by bare serial", argv[argv.index("-d") + 1], "56052444")
        # Fixed gain, always. Automatic gain and an RF squelch cannot both work: AGC winds
        # the gain up on a quiet band until the noise crosses whatever threshold is set,
        # so the same squelch level reads 0% open and 25% open ten minutes apart. Measured
        # on a real receiver; the channel spent a day recording its own noise floor.
        check("pins the tuner gain", argv[argv.index("-g") + 1], "30")
        check("tunes where it was told", argv[argv.index("-f") + 1], "147465000")
        # -s 200000 -r 16000, never -s 16000: the RTL2832U cannot sample that low and the
        # audio comes back mangled but plausible-looking, which whisper answers by
        # inventing something.
        check("oversamples", argv[argv.index("-s") + 1], "200000")
        check("uses the squelch measured for this site", argv[argv.index("-l") + 1], "40")
        check("rtl_fm's stderr lands in RAM, not on the card",
              os.path.dirname(seen["stderr"].name), clips)
        seen["stderr"].close()


def test_clips_go_to_ram_but_never_at_the_cost_of_listening():
    """Clips belong on tmpfs, but not so badly that a channel refuses to run without it.

    Wearing the card is a slow problem; a receiver that will not start is an immediate
    one, and the device is unattended somewhere nobody wants to drive to."""
    print("clip directory")
    with tempfile.TemporaryDirectory() as spool:
        os.environ["RUNTIME_DIRECTORY"] = os.path.join(spool, "runtime")
        try:
            check("uses systemd's tmpfs directory when given one",
                  transcriber.clip_dir("rx1-146520", fallback=spool),
                  os.path.join(spool, "runtime"))
        finally:
            del os.environ["RUNTIME_DIRECTORY"]

        # /run is root-owned, so a channel run by hand as pi lands here.
        saved, transcriber.CLIPS = transcriber.CLIPS, "/proc/definitely/not/writable"
        try:
            check("falls back to the spool rather than refusing to start",
                  transcriber.clip_dir("rx1-146520", fallback=spool), spool)
        finally:
            transcriber.CLIPS = saved

        # And a run that was killed must not leave its clips behind to be transcribed
        # again, hours later, as if they had just been heard.
        keep = os.path.join(spool, "outbox")
        os.makedirs(keep, exist_ok=True)
        open(os.path.join(spool, "clip_00001.wav"), "wb").close()
        open(os.path.join(spool, "calibration.json"), "w").close()
        transcriber.sweep_clips(spool)
        check("sweeps stale clips", os.path.exists(os.path.join(spool, "clip_00001.wav")), False)
        check("but leaves what was measured here",
              os.path.exists(os.path.join(spool, "calibration.json")), True)
        check("and the outbox", os.path.isdir(keep), True)


# ── squelch calibration ──────────────────────────────────────────────────────

def site(floor, busy_at=None):
    """A fake receiver whose noise stops getting through above `floor`.

    busy_at: a level during whose FIRST sample a transmission arrives, so the
    confirmation pass is what has to catch it.
    """
    full = transcriber.SAMPLE_RATE * 2
    state = {"seen": set()}

    def sample(level, seconds):
        first = level not in state["seen"]
        state["seen"].add(level)
        if busy_at is not None and level == busy_at and first:
            return int(full * seconds)          # somebody transmitting
        return int(full * seconds) if level < floor else 0
    return sample


def test_calibration_finds_the_lowest_level_that_gates():
    """Lowest, not safest. Picking a high level would gate reliably and leave the
    receiver deaf to anything quiet — the failure nobody notices."""
    print("calibration")
    check("quiet site", transcriber.choose_squelch(site(floor=15)), 20)
    check("noisier site", transcriber.choose_squelch(site(floor=95)), 100)
    check("very quiet site", transcriber.choose_squelch(site(floor=1)), 10)


def test_calibration_is_not_fooled_by_a_transmission():
    """A transmission during the measurement looks exactly like a level that is too
    low. Without the confirmation pass it would push the answer up and quietly cost
    sensitivity for as long as the cache lasts."""
    print("calibration — someone transmits mid-measurement")
    chosen = transcriber.choose_squelch(site(floor=15, busy_at=20))
    check("still picks the right level", chosen, 20)


def test_a_wedged_tuner_is_not_mistaken_for_a_quiet_frequency():
    """The failure that cost two evenings.

    An RTL-SDR's tuner can stop locking while every command still reports success:
    rtl_fm prints "Tuned to 146700000 Hz", allocates its buffers, announces its sample
    rate, and then produces not one byte. rtl_test says "[R82XX] PLL not locked!" and
    exits 0. From the web page, from systemctl, and from the channel's own log it is
    indistinguishable from a frequency nobody is using — the journal just repeats "no
    transmissions in the last 30 minutes" while somebody listens to the same repeater on
    a handheld.

    With the squelch off there is nothing left to gate, so a working receiver must
    deliver. That is the one question that separates the two, and it costs two seconds.
    """
    print("wedged tuner")
    ch = transcriber.Channel({"id": "rx", "frequency": "146700000", "serial": "1"})

    real = transcriber._sample_rtl
    try:
        transcriber._sample_rtl = lambda c, level, secs: 0
        check("a receiver producing nothing is not alive", transcriber.receiver_alive(ch), False)

        # Full rate at squelch 0 is what a healthy dongle does.
        transcriber._sample_rtl = lambda c, level, secs: int(transcriber.SAMPLE_RATE * 2 * secs)
        check("a receiver at full rate is alive", transcriber.receiver_alive(ch), True)

        # A trickle is not enough: a tuner half-working is still a tuner to power-cycle.
        transcriber._sample_rtl = lambda c, level, secs: int(transcriber.SAMPLE_RATE * 2 * secs * 0.05)
        check("a trickle does not count", transcriber.receiver_alive(ch), False)
    finally:
        transcriber._sample_rtl = real


def test_calibration_refuses_to_measure_a_dead_input():
    """The failure that shipped, and the shape of it is worth remembering: rtl_fm could
    not open the dongle — a restart raced its release — so every sample came back empty,
    empty read as "beautifully quiet", and the scan walked the answer down to the lowest
    candidate. The channel then ran with a squelch of 0, which in rtl_fm is no gate at
    all, recorded continuous hiss, and filed a steady stream of clips whisper had nothing
    to say about.

    With the squelch off a working receiver MUST emit at the full rate. If it does not,
    there is nothing to measure and no answer worth caching."""
    print("calibration — the receiver is not running")
    check("returns None rather than a number",
          transcriber.choose_squelch(lambda level, secs: 0), None)

    # And 0 is not a candidate any more: it is the absence of a squelch, not a setting.
    check("0 is not offered as an answer", 0 in transcriber.SQUELCH_CANDIDATES, False)


def lull(floor, quiet_looks):
    """A site whose noise stops for the first `quiet_looks` samples and then comes back.

    Every level reads as gating while it lasts, which is exactly what a scan sees when it
    runs during a quiet moment on a channel that is not quiet.
    """
    full = transcriber.SAMPLE_RATE * 2
    state = {"n": 0}

    def sample(level, seconds):
        if level == 0:                       # the liveness check, not part of the scan
            return int(full * seconds)
        state["n"] += 1
        if state["n"] <= quiet_looks:
            return 0
        return int(full * seconds) if level < floor else 0
    return sample


def test_a_lull_is_not_mistaken_for_a_quiet_channel():
    """The failure that cost the most: squelch 10 cached on a channel that then ran
    99.56% open, filing noise to people's phones for a day.

    Nothing was wrong with the receiver and nothing was wrong with the threshold. The
    scan simply ran while 146.700 happened to be quiet, and the walk-down — which steps
    down for as long as the level beneath also gates — slid from wherever it started all
    the way to the lowest candidate. A lull lasting only as long as the scan is enough.

    Measuring for longer does not fix it. Over 10.8 hours of this channel a 2 s window
    sees 2.6% of the night's idle-noise range and a 300 s window sees 138%, yet the p90
    distance from one window's level to the night's typical level only moves 319 -> 284.
    The floor wanders over tens of minutes, so the answer is not a longer look: it is
    refusing to believe an answer with nothing audible underneath it.
    """
    print("calibration — a lull, not a quiet channel")
    # Two looks is what the scan spends before it would have returned the floor. The
    # re-check is a THIRD look, a good fifteen seconds later, and by then the noise is back.
    check("returns None rather than the floor",
          transcriber.choose_squelch(lull(floor=60, quiet_looks=2)), None)
    # And the honest version of the same site still measures.
    check("a real boundary still measures", transcriber.choose_squelch(site(floor=55)), 60)
    check("a site that really is this quiet still measures",
          transcriber.choose_squelch(site(floor=1)), 10)


def test_the_length_guard_is_recorded_rather_than_silent():
    """The failure this is the first half of: a Morse identifier longer than
    TONE_MAX_SECONDS is never labelled one, so it reaches post_log_audio — which runs
    BEFORE whisper — and plays on somebody's phone as a burst of beeping.

    It cannot be fixed by moving a threshold, because the guard is what assigns the label:
    every Morse clip in the corpus is under 8 s BY CONSTRUCTION, so there is nothing to
    measure a longer one against. Asking with the guard lifted is how the example gets
    collected, and it must change no verdict while it does.
    """
    print("tone filter — what the length guard hides")
    beep = {"tonal": 0.9, "agree": 0.9, "keying": 12, "hz": 1454.5, "seconds": 4.0}
    long_beep = dict(beep, seconds=30.0)
    check("a short identifier is still named", transcriber.tone_reason(beep),
          "a Morse identifier at 1454 Hz")
    check("a long one is still left alone", transcriber.tone_reason(long_beep), "")
    check("and is named when the guard is lifted",
          transcriber.tone_reason(long_beep, max_seconds=float("inf")),
          "a Morse identifier at 1454 Hz")
    # Lifting the guard must not promote things that were never tonal to begin with.
    speech = {"tonal": 0.1, "agree": 0.2, "keying": 26, "hz": 300.0, "seconds": 30.0}
    check("speech stays speech with the guard lifted",
          transcriber.tone_reason(speech, max_seconds=float("inf")), "")


def test_a_long_clip_nobody_spoke_in_is_recognised():
    """The rule measured against ear-verified labels on the 82 long clips whose truth is
    known: noise breaks 0 to 8 times, real traffic breaks 10 to 30, and nothing lands
    between. Counting transitions, not loudness — noise at 38.6 dB is LOUDER than speech,
    so anything measuring level has the sign backwards.

    Observed only for now. The corpus behind it was gathered at squelch 10 and the
    population surviving 50 is a different one.
    """
    print("long clips — nobody talking")
    steady = {"tonal": 0.4, "agree": 0.6, "keying": 2, "hz": 2666.7, "seconds": 26.4}
    talking = {"tonal": 0.1, "agree": 1.0, "keying": 20, "hz": 1454.5, "seconds": 26.4}
    check("a steady long clip is named",
          transcriber.unbroken_reason(steady).startswith("nobody talking"), True)
    check("a long clip with speech rhythm is left alone",
          transcriber.unbroken_reason(talking), "")
    # The guard's own population is untouched: short clips are tone_reason's job.
    check("a short steady clip is not its business",
          transcriber.unbroken_reason(dict(steady, seconds=4.0)), "")
    check("no scan is no opinion", transcriber.unbroken_reason(None), "")
    # The boundary sits in the empty gap between the two classes.
    check("8 transitions is still nobody",
          transcriber.unbroken_reason(dict(steady, keying=8)) != "", True)
    check("10 transitions is somebody",
          transcriber.unbroken_reason(dict(steady, keying=10)), "")


def test_a_squelch_crash_does_not_hide_the_tone_behind_it():
    """The failure Doug heard three times in one morning: a single beep on his phone.

    The tone filter was not misjudging those clips — it was ABSTAINING. A squelch crash
    at each end of the clip carries a large DC offset, and measuring frame power about
    zero counted that offset as signal: 1654 rms instead of 1056. TONE_FLOOR_RATIO is a
    fraction of the loudest frame, so the inflated peak lifted the floor above the
    courtesy tone itself, which sits around 235 rms. Five audible frames against the six
    TONE_MIN_FRAMES needs meant "not enough clip to judge", and no opinion is correctly
    treated as "transcribe it" — so the audio went out before whisper ever ran.

    Removing each frame's own mean puts those clips at 25 audible frames and names them.
    Replayed over the 174 ear-verified clips it drops 7 more noise and costs no real
    traffic at all.
    """
    print("tone filter — a DC offset must not set the floor")
    rate = 16000
    n = transcriber.TONE_FRAME

    def frame(level, hz=0.0, dc=0.0):
        import math
        return [dc + level * math.sin(2 * math.pi * hz * i / rate) for i in range(n)]

    # A quiet tone, and a crash that is quiet too but sits on a large offset. About zero
    # the crash looks four times louder than it is and buries the tone under the floor.
    tone = frame(235, hz=1454.5)
    crash = frame(100, hz=700.0, dc=1600)
    about_zero = lambda f: sum(x * x for x in f) / len(f)
    about_mean = lambda f: (lambda m: sum((x - m) ** 2 for x in f) / len(f))(sum(f) / len(f))
    check("about zero the crash outweighs the tone", about_zero(crash) > about_zero(tone), True)
    check("about its own mean it does not", about_mean(crash) < about_mean(tone), True)


def test_a_capture_knows_how_many_transmissions_are_in_it():
    """GAP_SECONDS closes a capture when rtl_fm stops SENDING, and between overs it never
    does — it keeps emitting near-silent samples through its squelch hang. So a brisk
    conversation arrives as one block and lands on somebody's phone all at once.

    The gap is in the audio, and it is not subtle: measured on three real captures, every
    over ends with about 0.95 s of near-silence, a 0.30 s courtesy beep, then another
    second of it. Comfortably longer than GAP_SECONDS, and nothing was looking.

    Counted on LEVELS, not on the beep. The beep only exists on a repeater and this is
    pointed at simplex too, where a carrier drops just the same. Checked against the beeps
    on three captures — 5 boundaries to 5 beeps, 4 to 4, 5 to 5 — with the beeps found
    independently by band energy.
    """
    print("carrier gaps — how many overs in one capture")
    # The two near-silences either side of a beep are ONE boundary, not two.
    beep = [(11.8, 0.96), (13.2, 1.06), (26.8, 0.93), (28.2, 1.06)]
    check("a beep's two halves count once",
          transcriber.transmission_count(beep, 40.0), 3)
    # A gap running to the end is the last over finishing, not another one starting.
    check("a trailing gap does not invent an over",
          transcriber.transmission_count([(5.0, 1.0), (19.2, 1.0)], 20.0), 2)
    check("audio after the last gap is another over",
          transcriber.transmission_count([(5.0, 1.0)], 20.0), 2)
    check("no gaps means it cannot say", transcriber.transmission_count([], 20.0), 0)
    # Nothing here may cost the channel a transmission.
    peaks, rate = transcriber._frame_peaks("/no/such/file.wav")
    check("a missing file says nothing", transcriber.carrier_gaps(peaks, rate), [])


def test_a_clip_nobody_said_anything_in_is_recognised():
    """The hole the tone scan leaves. It has no opinion on 27 of 91 live clips, and no
    opinion is correctly treated as "transcribe it" — so the audio is uploaded before
    whisper ever runs and a beep plays on somebody's phone with nothing in the log.

    Measured against ears rather than whisper. Doug named all 24 clips the scan abstained
    from: 5 real, 5 courtesy beeps, 11 Morse identifiers, 3 nothing at all. Sound-carrying
    time separates the beeps from the speech with a gap between them — beeps 0.38 to
    0.61 s, real traffic 0.90 to 3.17 — and it holds on data it was not fitted to: 27
    live clips that logged nothing and 25 of 140 ear-verified corpus clips gathered at a
    different gain and squelch, against none of the 47 live and 34 corpus clips that
    carried real traffic.

    Referenced to the clip's 95th-percentile frame and NOT its loudest, which is the whole
    trick: a squelch crash is louder than anything anybody says, so measuring against the
    peak asks "how much of this is within 16 dB of the crash" and gives the same small
    answer for a beep and for a sentence.
    """
    print("empty clips — nothing said in it")
    rate = 16000
    n = transcriber.TONE_FRAME
    per_second = rate / float(n)

    def clip(total_seconds, sound_seconds, crash=False):
        """peaks for a clip of `total_seconds` carrying `sound_seconds` of sound, with an
        optional squelch crash — one frame far louder than anything said, which is what
        breaks a reference taken from the loudest frame."""
        loud = int(sound_seconds * per_second)
        peaks = [900] * loud + [5] * (int(total_seconds * per_second) - loud)
        if crash:
            peaks[0] = 30000
        return peaks

    # A courtesy beep as they actually arrive: about half a second inside a 2.5 s capture.
    check("a beep is named",
          transcriber.nothing_said_reason(clip(2.5, 0.5), rate) != "", True)
    check("a sentence is not",
          transcriber.nothing_said_reason(clip(5.0, 2.0), rate), "")
    check("and a squelch crash does not change either answer",
          (transcriber.nothing_said_reason(clip(2.5, 0.5, crash=True), rate) != "",
           transcriber.nothing_said_reason(clip(5.0, 2.0, crash=True), rate)),
          (True, ""))
    # The limit, written down because it is not obvious: the reference is the clip's own
    # 95th percentile, so a clip that is more than 95% silence has a reference taken from
    # the silence, every frame clears the floor, and it reports the whole clip as sound.
    # Nothing measured comes close — the emptiest real capture was 40% sound — but a very
    # short beep inside a very long capture would defeat this, and would need the carrier
    # gaps to cut it up first.
    check("a clip that is almost entirely silence defeats it",
          transcriber.nothing_said_reason(clip(20.0, 0.3), rate), "")
    check("no audio is no opinion", transcriber.nothing_said_reason([], 0), "")
    check("content of nothing is None", transcriber.content_seconds([], 0), None)


def test_calibration_gives_up_rather_than_guessing():
    """If nothing shuts it up, say so — the caller falls back to the default instead of
    returning a made-up number."""
    print("calibration — nothing works")
    check("returns None", transcriber.choose_squelch(site(floor=10_000)), None)


# ── gain calibration ─────────────────────────────────────────────────────────

def band(thermal, adc=0.0, transmits_at=None, transmits_from=None):
    """A fake receiver's noise floor in dB, as a function of tuner gain.

    Two contributions and nothing else, which is the whole of what the knee is about: the
    converter's own noise, which does not care what the gain is, and the band's noise
    coming in through the antenna, which the tuner amplifies. Below the knee the first
    dominates and the floor barely moves as the gain goes up; above it the second does and
    the floor follows the gain 1:1. `thermal` is where the antenna noise sits at 0 dB of
    gain, relative to the converter's own floor, so it is the only thing that decides
    where the knee lands.

    transmits_at: somebody keys up during that one measurement and is gone by the next.
    transmits_from: somebody keys up at that gain and is still there at the end of the run,
    which is the harder case — every later measurement agrees with itself.
    """
    latched = {"on": False}
    measured = set()

    def measure(gain):
        if transmits_from is not None and gain >= transmits_from:
            latched["on"] = True
        power = 10 ** (adc / 10.0) + 10 ** ((thermal + gain) / 10.0)
        if latched["on"] or (transmits_at is not None and gain == transmits_at
                             and gain not in measured):
            power += 10 ** ((thermal + gain + 25) / 10.0)     # a carrier, well above it
        measured.add(gain)
        return 10 * math.log10(power)
    return measure


def test_the_gain_is_the_knee_where_the_receiver_starts_hearing_the_band():
    """The gain is chosen by where the noise floor starts following it, and never by
    which gain's noise the squelch still gates.

    That second question is the trap this project keeps falling into. Gating and
    sensitivity pull in opposite directions, and on a dead band only gating can be
    measured — so optimizing for it alone walks the gain down until the receiver gates
    beautifully and hears nothing at all.

    The knee asks something a dead band CAN answer. While the receiver is limited by its
    own converter the floor rises less than each gain increment; once it is limited by
    thermal noise arriving from the antenna it rises 1:1. Where that changes is where the
    receiver starts hearing the band rather than itself, and a noisier site reaches it at
    a lower gain — which is the whole reason 30 dB measured at one site is not a number to
    compile in for every site.
    """
    print("gain — the knee")
    check("an ordinary site", transcriber.choose_gain(band(thermal=-10)), (16.6, ""))
    check("a noisy site needs less gain", transcriber.choose_gain(band(thermal=5)),
          (12.5, ""))
    check("a quiet site needs more", transcriber.choose_gain(band(thermal=-20)),
          (29.7, ""))


def test_a_gain_sweep_with_no_knee_is_used_but_never_called_measured():
    """A floor that never follows the gain has two causes and this sweep cannot separate
    them: nothing reaching the tuner, or a site quieter than the ladder reaches.

    It used to refuse and name the antenna, and that was the wrong cause every time it
    was said. Refusing also did not avoid guessing, which was the real problem: the
    caller's fallback is a gain compiled in from another site, and at the site that
    prompted this it was 30 dB against a knee at 38.6 — so refusing to guess quietly
    guessed lower, and left the receiver deaf. The top of the sweep comes back instead,
    with the caveat attached, and the caller's job is to cache it without believing it."""
    print("gain — no knee")
    gain, why = transcriber.choose_gain(band(thermal=-200))
    check("hands back the top of the sweep", gain, max(transcriber.GAIN_CANDIDATES))
    check("rather than nothing at all", gain is not None, True)
    check("still says to check the antenna", "antenna" in why, True)
    check("but no longer claims that is the only cause", "quieter" in why, True)

    # What the caller does with it: cached so the receiver stops running on another
    # site's number, and marked so nothing downstream calls it a measurement.
    with tempfile.TemporaryDirectory() as spool:
        channel = transcriber.Channel({"id": "rx1-147465", "frequency": "147465000",
                                       "serial": "1"})
        result, why = transcriber.calibrate(channel, spool,
                                            measure=band(thermal=-200),
                                            sample=site(floor=25))
        check("it is cached", result and result["gain"], max(transcriber.GAIN_CANDIDATES))
        check("and marked as not a knee", result["knee"], False)
        check("with the reason kept beside it", "antenna" in result.get("note", ""), True)
        saved = json.load(open(os.path.join(spool, "calibration.json")))
        check("the mark survives to disk", saved["knee"], False)

    # And a receiver that hands back nothing at all is the wedged tuner again, not a
    # wonderfully quiet site. That one is still a refusal.
    gain, why = transcriber.choose_gain(lambda g: None)
    check("a dead receiver is not a measurement", gain, None)
    check("and says the tuner produced nothing", "no samples" in why, True)


def test_a_transmission_during_the_sweep_is_detected_rather_than_measured():
    """Traffic arriving mid-sweep invalidates the whole thing, exactly as it does in the
    squelch scan — and it must be caught rather than averaged in, because what it
    produces is not a wild answer but a plausible one: a floor that jumps at one gain
    looks precisely like a knee, and the gain it names is wrong for as long as it is
    cached.

    Both shapes. A transmission that ends leaves the floor lower afterwards than it was
    before, which cannot happen when the gain is going up. One that is still there at the
    end agrees with itself everywhere, so it is caught by re-measuring the bottom of the
    sweep at the end and finding it has moved."""
    print("gain — someone transmits mid-measurement")
    gain, why = transcriber.choose_gain(band(thermal=-10, transmits_at=20.7))
    check("does not cache a wrong gain", gain, None)
    check("and says what happened", "transmitting" in why, True)

    gain, why = transcriber.choose_gain(band(thermal=-10, transmits_from=20.7))
    check("a carrier that stays up is caught too", gain, None)
    check("and says what happened", "transmitting" in why, True)


def test_the_gain_sweep_stays_below_what_this_tuner_stays_linear_at():
    """The knee is measured on an idle channel, so it cannot see the one thing that got a
    hardcoded 40 removed: front-end overload from a strong transmitter elsewhere in the
    band, which is not on this frequency and is not there while the sweep runs. Nothing
    the sweep measures would object to 40 dB. So the list simply does not offer it.

    40 is the number with a failure attached to it, and it is the one to assert on. An
    earlier version of this test demanded 36 or less, which is a tighter bound than
    anything measured ever justified, and it made the sweep stop one step short of a real
    site's knee — see the test below."""
    print("gain — the cap")
    check("stays well below the tuner's 49.6 dB maximum",
          max(transcriber.GAIN_CANDIDATES) < 40, True)
    check("and never offers the 40 that overloaded the front end",
          [g for g in transcriber.GAIN_CANDIDATES if g >= 40], [])


def test_a_site_quiet_enough_to_need_the_top_of_the_sweep_still_calibrates():
    """A quiet site's knee sits high, and the sweep has to reach it or it will blame the
    antenna for the silence.

    This is measured, not invented. On an idle 2m antenna the floor sat on the converter's
    own quantisation noise from 8.7 dB all the way to 32.8 — the old top of the sweep —
    and lifted off at 36.4. Every calibration there failed with "check the antenna and its
    connector" while the antenna was connected and working, because a sweep that stops
    below the knee cannot tell a quiet site from a disconnected one. thermal=-30 puts the
    knee where that site's actually was."""
    print("gain — a quiet site reaches its knee")
    gain, why = transcriber.choose_gain(band(thermal=-30))
    check("finds the knee above the old 32.8 ceiling", gain, 36.4)
    check("and does not blame the antenna", why, "")

    # The bug itself: the same site, swept only as far as the old ceiling went. It now
    # degrades to an unmeasured 32.8 instead of refusing, which is the point — but 36.4
    # measured is the answer, and only a ladder that reaches it can say so.
    gain, why = transcriber.choose_gain(band(thermal=-30),
                                        candidates=[8.7, 12.5, 16.6, 20.7, 25.4, 29.7, 32.8])
    check("stopping short finds no knee at all", why != "", True)
    check("and can only offer the top of its ladder", gain, 32.8)
    check("naming how far it actually looked", "32.8" in why, True)


# ── what is cached, and when it is measured ──────────────────────────────────

def calibrated(spool, **fields):
    """Write a calibration cache by hand, as a device would have left it."""
    with open(os.path.join(spool, "calibration.json"), "w") as fh:
        json.dump(fields, fh)


def test_gain_and_squelch_are_cached_together_or_not_at_all():
    """A squelch level means nothing without the gain it was measured at — rtl_fm's -l
    compares received power against a threshold, and the gain decides what that power is.
    A cache holding one without the other is the bug that cost a day, so it is refused
    outright rather than half-believed."""
    print("calibration cache")
    import time as _time
    with tempfile.TemporaryDirectory() as spool:
        channel = transcriber.Channel({"id": "rx1-147465", "frequency": "147465000",
                                       "serial": "1"})
        result, why = transcriber.calibrate(
            channel, spool,
            measure=band(thermal=-10),
            sample=site(floor=25))
        check("measures a gain", result and result["gain"], 16.6)
        check("and a squelch at that gain", result and result["squelch"], 30)
        check("with nothing to explain away", why, "")

        saved = json.load(open(os.path.join(spool, "calibration.json")))
        check("both are written down together", sorted(saved),
              ["frequency", "gain", "knee", "squelch", "when"])
        check("and it is recorded that this one was a real knee", saved["knee"], True)

        # The gain the squelch was measured at is the gain the channel then opens with.
        gain, squelch, _ = transcriber.calibration_for(channel, spool)
        check("and both come back", (gain, squelch), (16.6, 30))

        # Half a cache is no cache. This is the exact file the old code wrote.
        calibrated(spool, squelch=40, when=_time.time(), frequency="147465000")
        gain, squelch, measured = transcriber.calibration_for(channel, spool)
        check("a squelch with no gain beside it is ignored", measured, None)
        check("and the channel falls back to the built-in pair", (gain, squelch),
              (transcriber.DEFAULT_GAIN, transcriber.DEFAULT_SQUELCH))


def test_a_measurement_is_never_re_measured_behind_your_back():
    """Calibration happens when somebody asks for it and at no other time.

    A cache that expired took the channel off the air for minutes at whatever hour it
    happened to fall due, and nobody asked for it. The measurement is only as old as the
    site it was made at, and a site does not change on a schedule — so an old measurement
    is used exactly as a new one is, and it is the manager's job to show how old it is."""
    print("calibration — on demand only")
    import time as _time
    check("nothing expires", hasattr(transcriber, "CALIBRATION_MAX_AGE"), False)
    with tempfile.TemporaryDirectory() as spool:
        channel = transcriber.Channel({"id": "rx1-147465", "frequency": "147465000",
                                       "serial": "1"})
        calibrated(spool, gain=20.7, squelch=20, frequency="147465000",
                   when=_time.time() - 40 * 86400)
        gain, squelch, measured = transcriber.calibration_for(channel, spool)
        check("a six-week-old measurement is still the answer", (gain, squelch),
              (20.7, 20))
        check("and is reported as a measurement", bool(measured), True)


def test_a_channel_that_has_never_been_calibrated_runs_on_the_defaults():
    """A new receiver listens on the compiled-in pair until somebody presses the button —
    it does not measure at startup and it does not quietly measure later. The cost of
    that is a device running numbers measured somewhere else, which is why the manager
    says "never calibrated" in so many words; the cost of the alternative is a channel
    that goes deaf for minutes at a moment nobody chose."""
    print("calibration — never measured")
    with tempfile.TemporaryDirectory() as d:
        clips, spool = os.path.join(d, "run"), os.path.join(d, "spool")
        os.makedirs(clips), os.makedirs(spool)

        measured = []
        seen = {}

        class FakePopen:
            def __init__(self, argv, stdout=None, stderr=None):
                seen["argv"], seen["stderr"] = argv, stderr

        real_popen = transcriber.subprocess.Popen
        real_sample, real_floor = transcriber._sample_rtl, transcriber.measure_floor
        transcriber.subprocess.Popen = FakePopen
        transcriber._sample_rtl = lambda *a: measured.append(a)
        transcriber.measure_floor = lambda *a: measured.append(a)
        try:
            channel = transcriber.Channel({"id": "rx1-147465", "frequency": "147465000",
                                           "serial": "56052444"})
            transcriber.start_capture(channel, clips, spool)
        finally:
            transcriber.subprocess.Popen = real_popen
            transcriber._sample_rtl, transcriber.measure_floor = real_sample, real_floor

        argv = seen["argv"]
        check("opens the radio without measuring anything", measured, [])
        check("on the built-in gain", argv[argv.index("-g") + 1],
              str(transcriber.DEFAULT_GAIN))
        check("and the built-in squelch", argv[argv.index("-l") + 1],
              str(transcriber.DEFAULT_SQUELCH))
        seen["stderr"].close()


def calibration_output(channel, spool, measure, sample):
    """The JSON lines run_calibration prints, in order."""
    import contextlib
    import io
    real_alive, real_floor = transcriber.receiver_alive, transcriber.measure_floor
    real_sample = transcriber._sample_rtl
    transcriber.receiver_alive = lambda c: True
    transcriber.measure_floor = lambda c, g, **kw: measure(g)
    transcriber._sample_rtl = lambda c, level, secs: sample(level, secs)
    out = io.StringIO()
    try:
        with contextlib.redirect_stdout(out):
            rc = transcriber.run_calibration(channel, spool)
    finally:
        transcriber.receiver_alive, transcriber.measure_floor = real_alive, real_floor
        transcriber._sample_rtl = real_sample
    return rc, [json.loads(ln) for ln in out.getvalue().splitlines() if ln.strip()]


def test_calibrating_says_it_has_started_before_it_says_what_it_found():
    """Two reports, and the first one is what makes the manager's countdown honest.

    Devices are polled once a minute, so counting down from the moment the button was
    pressed is wrong by up to a minute — and wrong in the direction that makes the page
    claim a measurement has finished while the radio is still busy. The device says when
    it has actually started, and how long it expects to take, and the countdown runs from
    that."""
    print("calibration — reporting in")
    with tempfile.TemporaryDirectory() as spool:
        channel = transcriber.Channel({"id": "rx1-147465", "frequency": "147465000",
                                       "serial": "1"})
        rc, lines = calibration_output(channel, spool, band(thermal=-10), site(floor=25))
        check("succeeds", rc, 0)
        check("says it has started first", lines[0]["state"], "started")
        check("with how long it expects to take", lines[0]["expected"] > 0, True)
        check("then what it measured", lines[1],
              {"state": "done", "gain": 16.6, "squelch": 30, "knee": True})

        # A gain that is the top of the sweep rather than a knee travels as such, so the
        # manager can show the difference instead of presenting a guess as a measurement.
        rc, lines = calibration_output(channel, spool, band(thermal=-200), site(floor=25))
        check("a caveated gain still succeeds", rc, 0)
        check("but is reported as not a knee", lines[1]["knee"], False)
        check("and carries the reason", "antenna" in lines[1].get("note", ""), True)


def test_a_failed_calibration_says_why_and_caches_nothing():
    """A failed calibration that says so is far better than a plausible one that is
    wrong. Nothing is written, so the channel goes back on the air with whatever it was
    already using, and the manager has a sentence to show rather than a spinner that
    stops."""
    print("calibration — failure is reported, not cached")
    with tempfile.TemporaryDirectory() as spool:
        channel = transcriber.Channel({"id": "rx1-147465", "frequency": "147465000",
                                       "serial": "1"})
        rc, lines = calibration_output(channel, spool,
                                       band(thermal=-10, transmits_at=20.7),
                                       site(floor=25))
        check("fails", rc, 1)
        check("having said it started", lines[0]["state"], "started")
        check("and then why it stopped", lines[1]["state"], "failed")
        check("in words", "transmitting" in lines[1]["error"], True)
        check("nothing is cached", os.path.exists(os.path.join(spool, "calibration.json")),
              False)


# ── where one transmission ends and the next begins ──────────────────────────

def emitter(script):
    """A fake rtl_fm. `script` is [(seconds, emit?), ...] played in real time.

    "emit?" is the whole point. rtl_fm's RF squelch gates before demodulation, so while
    it is closed the process writes NOTHING — measured on a real receiver at exactly zero
    bytes over eight seconds of idle channel. A gap in the byte stream is therefore not a
    quiet passage; it is the carrier dropping.
    """
    body = (
        "import sys, time\n"
        "w = sys.stdout.buffer\n"
        "chunk = (%d).to_bytes(2, 'little', signed=True) * %d\n"
        "for seconds, emit, level in %r:\n"
        "    end = time.time() + seconds\n"
        "    while time.time() < end:\n"
        "        if emit:\n"
        "            w.write((level).to_bytes(2, 'little', signed=True) * %d); w.flush()\n"
        "        time.sleep(0.05)\n"
    ) % (3000, transcriber.BLOCK_SAMPLES, script, transcriber.BLOCK_SAMPLES)
    return [sys.executable, "-c", body]


def capture_clips(script, seconds, carrier=None):
    """Run the capture loop against a fake radio.

    Returns (name, duration) for each clip it wrote, in order — the name because a
    segment cut at the cap is named differently from an over that ended on its own, and
    that mark is what tells the transcribing thread which is which.

    `carrier` replaces the OpenCarrier the loop would build for itself, so a test can
    say "stop transcribing this one" without waiting for a real verdict.
    """
    import subprocess as sp, time as _time
    with tempfile.TemporaryDirectory() as tmp:
        models = os.path.join(tmp, "models"); os.makedirs(models)
        open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()
        clips = os.path.join(tmp, "clips"); os.makedirs(clips)
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1-146520", "token": "t",
                                     "frequency": "146520000", "serial": "1"}]}, fh)

        written = []
        real_put, real_capture = transcriber.ClipQueue.put, transcriber.start_capture
        # The liveness probe opens the real dongle, which these tests do not have. It is
        # covered on its own in test_a_wedged_tuner_is_not_mistaken_for_a_quiet_frequency.
        real_alive, transcriber.receiver_alive = transcriber.receiver_alive, lambda ch: True
        real_carrier = transcriber.OpenCarrier
        if carrier is not None:
            transcriber.OpenCarrier = lambda: carrier
        transcriber.ClipQueue.put = lambda self, p: written.append(p)
        transcriber.start_capture = lambda ch, c, sp_: sp.Popen(
            emitter(script), stdout=sp.PIPE, stderr=sp.DEVNULL)
        died = []
        try:
            def run():
                try:
                    transcriber.main(["--channel", "rx1-146520", "--config", config,
                                      "--spool", tmp, "--clips", clips,
                                      "--whisper", stub_whisper(tmp, "x"),
                                      "--models", models])
                except BaseException as e:                   # noqa: BLE001
                    died.append(repr(e))
            t = threading.Thread(target=run, daemon=True); t.start()
            _time.sleep(seconds)
            if died:
                FAILURES.append("capture loop died: %s" % died[0])
        finally:
            transcriber.ClipQueue.put = real_put
            transcriber.start_capture = real_capture
            transcriber.receiver_alive = real_alive
            transcriber.OpenCarrier = real_carrier
        # Measured before the temporary directory goes, since the files go with it.
        return [(os.path.basename(p), transcriber.clip_seconds(p))
                for p in written if os.path.exists(p)]


def capture_lengths(script, seconds):
    """Just the durations, for the tests that only care where the cuts fell."""
    return [d for _, d in capture_clips(script, seconds)]


def test_a_pause_in_speech_does_not_end_the_transmission():
    """The regression that reached the air.

    An audio-level squelch ran on top of rtl_fm's, and since rtl_fm emits nothing while
    closed, the only audio it ever saw was speech — so the "noise floor" it computed was
    a speech level. It then dropped anything quieter, which meant the opening syllables
    of every over, and cut the over in two at the first pause. A station saying
    "monitoring channel, K6DRK" was logged as "ring channel K6DRK." followed by a 1.2s
    fragment whisper could make nothing of.

    So: quiet audio is still audio, and only a gap in the BYTES ends a transmission.
    """
    print("segmentation — a quiet passage mid-over")
    #        (seconds, emitting?, level)
    got = capture_lengths([(1.0, True, 3000),   # speech
                           (0.6, True, 40),     # a pause — carrier up, barely audible
                           (1.0, True, 3000),   # more speech
                           (1.5, False, 0),     # carrier drops: THIS ends it
                           (1.0, True, 3000),   # a second over
                           (1.5, False, 0)],
                          seconds=7.0)
    check("two transmissions, not four", len(got), 2)
    if len(got) == 2:
        # ~2.6s means the quiet middle survived and nothing was trimmed off the front.
        check("the first keeps its quiet middle", 2.2 <= got[0] <= 3.0, True)
        check("and the second stands alone", 0.7 <= got[1] <= 1.5, True)


def test_a_long_gap_separates_two_overs():
    print("segmentation — two overs")
    got = capture_lengths([(1.0, True, 3000), (1.5, False, 0),
                           (1.0, True, 3000), (1.5, False, 0)],
                          seconds=6.0)
    check("two clips", len(got), 2)


def test_a_single_over_is_one_clip():
    print("segmentation — one over")
    got = capture_lengths([(2.0, True, 3000), (1.5, False, 0)], seconds=4.5)
    check("one clip", len(got), 1)
    if got:
        check("of about the right length", 1.7 <= got[0] <= 2.4, True)


# ── a carrier that does not drop ─────────────────────────────────────────────

class _FakeCarrier:
    """An OpenCarrier whose answers the test chooses, so the capture loop can be asked
    what it does with them without waiting on a real transcription."""

    def __init__(self, keep):
        self.keep = list(keep)
        self.asked = 0
        self.skipping = False
        self.drops = 0

    def segment(self):
        self.asked += 1
        return self.keep.pop(0) if self.keep else False

    def verdict(self, logged):
        pass

    def dropped(self):
        self.drops += 1


def test_a_carrier_that_does_not_drop_is_cut_at_exactly_the_cap():
    """The bug that threw away every long capture the channel ever made.

    Reads overshoot, so writing the whole buffer produced a clip measuring 120.1s
    against a downstream test of `> MAX_CLIP_SECONDS`. Every capped clip therefore
    failed it and was deleted without reaching whisper, and the journal recorded
    "discarding 120s clip — open carrier?" as though that had been the intention.

    Cut at the cap exactly and carry the remainder into the next segment. The tenth of
    a second is not the point; being on the wrong side of the ceiling is.
    """
    print("segmentation — a carrier that does not drop")
    # 1.03 seconds, and the odd number is the entire point. The reads arrive in 50 ms
    # blocks, so a cap of a round 1.0s would be reached exactly, there would be no
    # overshoot, and this test would pass against the very code it was written for —
    # which it did, the first time it was run. On the air rtl_fm's writes do not line up
    # with the cap either, which is how a 120s cap produced a 120.1s clip.
    cap = 1.03
    saved, transcriber.MAX_CLIP_SECONDS = transcriber.MAX_CLIP_SECONDS, cap
    try:
        got = capture_clips([(4.0, True, 3000)], seconds=4.2)
    finally:
        transcriber.MAX_CLIP_SECONDS = saved

    check("it is cut into segments rather than growing forever", len(got) >= 3, True)
    check("none of which is over the cap", [n for n, d in got if d > cap], [])
    check("each being exactly the cap", sorted({round(d, 3) for _, d in got}), [cap])
    # The mark travels with the file, because it is what tells the transcribing thread
    # that this clip answers the open-carrier question and an ordinary over does not.
    check("and all of them are marked as capped",
          [n for n, d in got if transcriber.CAPPED_MARK not in n], [])


def test_a_carrier_judged_stuck_stops_being_recorded():
    """A stuck transmitter must not keep the careful model busy for as long as it lasts.

    The model takes about 100 seconds per 120-second segment, so transcribing an
    unbroken carrier occupies the worker permanently, puts every real transmission
    behind it, and leaves the log further behind the radio every minute. Once the
    verdicts say there are no words in it, the capture loop stops writing it down.
    """
    print("segmentation — a carrier already judged stuck")
    carrier = _FakeCarrier([True, False, False, True])
    saved, transcriber.MAX_CLIP_SECONDS = transcriber.MAX_CLIP_SECONDS, 1.0
    try:
        got = capture_clips([(5.0, True, 3000)], seconds=5.0, carrier=carrier)
    finally:
        transcriber.MAX_CLIP_SECONDS = saved
    check("the loop asks about every segment", carrier.asked >= 4, True)
    check("and writes down only the ones it is told to", len(got), 2)


def test_open_carrier_tells_a_stuck_transmitter_from_a_busy_channel():
    """The two look identical from the capture loop — samples that never stop — and
    the audio cannot separate them either, since an RF squelch has already decided
    something is transmitting and a stuck microphone in a car is as loud as a
    conversation. What separates them is whether there are words in it, which only the
    transcribing thread knows.

    Giving up outright would be the other mistake. One of the two faults that produces
    an endless carrier is a squelch that has stopped gating, and real traffic is still
    arriving inside it; going deaf would turn a degraded channel into a dead one.
    """
    print("OpenCarrier")
    c = transcriber.OpenCarrier(tolerate=2, recheck=5)
    check("the first segment of anything is transcribed", c.segment(), True)
    c.verdict(True)                       # there were words in it
    check("and so is the next, while there are words in them", c.segment(), True)

    c.verdict(False)
    check("one silent segment is not enough to give up", c.segment(), True)
    c.verdict(False)
    check("two in a row is", c.segment(), False)
    check("and it stays given up", [c.segment() for _ in range(3)], [False] * 3)
    check("but looks again every fifth segment", c.segment(), True)

    c.verdict(True)                       # somebody is talking after all
    check("and words put it straight back to normal", c.segment(), True)

    # A long transmission that keeps producing text is never throttled, however long it
    # runs. That is the case this must not break.
    for _ in range(20):
        c.verdict(True)
        check_quiet(c.segment() is True)

    c.verdict(False); c.verdict(False)
    check("a carrier dropping forgets all of it", (c.segment(), c.dropped(), c.segment()),
          (False, None, True))


def test_only_a_capped_clip_answers_the_open_carrier_question():
    """An ordinary over that came back empty says nothing about a stuck carrier — it is
    a squelch tail, and there are hundreds of those a day. Only a clip the cap cut can
    be evidence, which is why the mark is on the file rather than in a variable."""
    print("open carrier — which clips count as evidence")
    import time as _time
    work, stopping, said = transcriber.ClipQueue(), threading.Event(), []

    class Listening:
        def verdict(self, logged):
            said.append(logged)

    def stub(channel, path, whisper, model, outbox, retention=None):
        return "yes" in path

    real, transcriber.handle_clip = transcriber.handle_clip, stub
    try:
        worker = threading.Thread(
            target=transcriber.transcribe_loop,
            args=(work, None, None, None, _FakeOutbox(), stopping, Listening()),
            daemon=True)
        worker.start()
        for name in ["clip_00001-yes.wav",                    # an ordinary over, logged
                     "clip_00002.wav",                        # an ordinary over, silent
                     "clip_00003%s.wav" % transcriber.CAPPED_MARK,
                     "clip_00004-yes%s.wav" % transcriber.CAPPED_MARK]:
            work.put(name)
        deadline = _time.time() + 5
        while len(said) < 2 and _time.time() < deadline:
            _time.sleep(0.02)
        stopping.set(); worker.join(timeout=5)
    finally:
        transcriber.handle_clip = real
    check("only the capped clips are reported on", said, [False, True])


def test_non_speech_tokens_are_suppressed_at_the_decoder_where_the_build_allows():
    """Better than deleting them afterwards, which is what clean() does: a non-speech
    token takes part in the decode and pulls the words around it out of shape, so
    removing it later leaves the damage behind.

    But only where the build has the flag. whisper.cpp treats an unknown option as
    fatal — usage and a non-zero exit — so passing it blind would turn better text into
    a channel that transcribes nothing, on the oldest and least watched device.
    """
    print("whisper — non-speech tokens")

    class Ran:
        returncode, stdout, stderr = 0, "K6DRK on West Marin", ""

    def whisper_that(help_text):
        def run(argv, **kw):
            if argv[1:] == ["-h"]:
                return type("Help", (), {"returncode": 0, "stdout": help_text,
                                         "stderr": ""})()
            seen.append(argv)
            return Ran()
        return run

    real = transcriber.subprocess.run
    try:
        for label, help_text, want in [
                ("passes the flag when the build advertises it",
                 "  -sns, --suppress-nst  [false] suppress non-speech tokens", True),
                ("and leaves it off a build that has never heard of it",
                 "  -np,  --no-prints     [false] do not print anything", False)]:
            seen = []
            transcriber._flag_support.clear()
            transcriber.subprocess.run = whisper_that(help_text)
            transcriber.transcribe("whisper-cli", "model.bin", "clip.wav")
            check(label, transcriber.SUPPRESS_NON_SPEECH in seen[0], want)
    finally:
        transcriber.subprocess.run = real
        transcriber._flag_support.clear()


def test_clean_strips_sound_effects():
    print("clean")
    check("asterisks are stripped like brackets",
          transcriber.clean("*BANG* K6DRK on West Marin *static*"), "K6DRK on West Marin")
    check("and an entry that is only a sound effect empties out",
          transcriber.clean("*gunshot*"), "")
    real = "(water splashing) (water splashing) K-6 DRK testing on West Marin K-6 DRK (water splashing)"
    check("keeps the speech, drops the hiss",
          transcriber.clean(real), "K-6 DRK testing on West Marin K-6 DRK")
    check("pure narration becomes nothing", transcriber.clean("(water splashing)"), "")
    check("plain speech is untouched",
          transcriber.clean("aid three we have a rider down"), "aid three we have a rider down")
    check("and nothing left is not worth logging",
          transcriber.worth_logging(transcriber.clean("[MUSIC]")), False)


def test_the_last_sentence_is_closed():
    """whisper punctuates, but not every time, and a log column shows the difference.

    All four of the "already ended" cases are real whisper output off this channel on
    2026-08-16/17; so is the bare one it is contrasted with.
    """
    print("closing the last sentence")
    closed = transcriber.close_sentence
    check("an entry whisper left open gets its period",
          closed("K6DRK testing West Marilyn K6DRK"),
          "K6DRK testing West Marilyn K6DRK.")
    check("one that already ends is untouched",
          closed("Second test to West Marin K6DRK."),
          "Second test to West Marin K6DRK.")
    check("a question mark ends a sentence too",
          closed("Are you mobile?"), "Are you mobile?")
    check("so does an exclamation", closed("Break!"), "Break!")
    check("trailing off is an ending, not a missing one",
          closed("I think he said..."), "I think he said...")
    check("a dangling comma is replaced rather than written over",
          closed("K6DRK testing, West Marin,"), "K6DRK testing, West Marin.")
    check("and so is a dangling comma with a space after it",
          closed("go ahead, "), "go ahead.")
    check("a closing quote counts as after the words, not as an ending",
          closed('he said "go ahead"'), 'he said "go ahead".')
    check("but not when the sentence ended inside it",
          closed('he said "go ahead."'), 'he said "go ahead."')
    # Nothing here should ever reach the log — loggable() runs first — but a cosmetic
    # step must not be the thing that raises on an entry the guards would have dropped.
    check("empty text is left alone", closed(""), "")
    check("and so is text with no words in it at all", closed("--"), "--")


# ── clip length ──────────────────────────────────────────────────────────────

def write_wav(path, seconds, rate=16000, amplitude=0):
    """Silence by default, which is what most of these tests want. `amplitude` gives a
    tone instead, for the level-dependent ones — a sine rather than a square so the RMS
    is a believable stand-in for speech."""
    n = int(rate * seconds)
    with wave.open(path, "w") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        if not amplitude:
            w.writeframes(struct.pack("<h", 0) * n)
        else:
            w.writeframes(b"".join(
                struct.pack("<h", int(amplitude * math.sin(2 * math.pi * 440 * i / rate)))
                for i in range(n)))


def test_clip_seconds():
    print("clip_seconds")
    with tempfile.TemporaryDirectory() as d:
        p = os.path.join(d, "a.wav")
        write_wav(p, 2.0)
        check("measures duration", round(transcriber.clip_seconds(p), 1), 2.0)
        check("unreadable file is 0", transcriber.clip_seconds(os.path.join(d, "nope.wav")), 0.0)


# ── a carrier left open ──────────────────────────────────────────────────────

def write_speechlike(path, seconds, rate=16000, amplitude=6000):
    """A signal with SYLLABLES: bursts of tone separated by near-silence.

    Not a stand-in for the sound of speech, which nothing here judges — a stand-in for its
    SHAPE, which is the only thing the detector looks at. Speech stops between words; a
    carrier sitting open does not.
    """
    n = int(rate * seconds)
    with wave.open(path, "w") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(rate)
        out = []
        for i in range(n):
            loud = (i // int(rate * 0.25)) % 2 == 0       # 250 ms on, 250 ms off
            # The gap SCALES with the signal, because that is what turning the gain up
            # does. Holding it at a fixed couple of counts made the quiet version's floor
            # the converter's own resolution rather than its signal, so the two versions
            # measured 36 dB apart and the test caught its own fixture rather than a bug.
            a = amplitude if loud else amplitude / 100.0
            out.append(struct.pack("<h", int(a * math.sin(2 * math.pi * 300 * i / rate))))
        w.writeframes(b"".join(out))


def test_a_carrier_left_open_is_not_a_transmission():
    """Whisper already recognises these and answers 'you' or nothing — but the clip is
    uploaded BEFORE whisper runs, so the static has played on every listening phone by the
    time its transcription is thrown away. This is the same verdict, reached early enough
    to matter.

    Measured on this receiver: dead carriers came in at 1.08, 1.31, 1.40 and 1.80 dB of
    envelope movement, real speech at 47.68 and 63.06."""
    print("dead carrier")
    with tempfile.TemporaryDirectory() as d:
        flat = os.path.join(d, "flat.wav")
        write_wav(flat, 10.0, amplitude=8000)            # one unbroken tone: never moves
        scan = transcriber.flat_scan(flat)
        check("an unmoving level is measured as such", scan["dyn_db"] < 5.0, True)
        check("and named", "dead carrier" in transcriber.flat_reason(scan), True)
        check("the reason says how long it went on", "10.0s" in transcriber.flat_reason(scan), True)

        speech = os.path.join(d, "speech.wav")
        write_speechlike(speech, 10.0)
        s2 = transcriber.flat_scan(speech)
        check("something with pauses in it is not", transcriber.flat_reason(s2), "")
        check("and its envelope moves a long way", s2["dyn_db"] > 30, True)


def test_the_dead_carrier_test_is_a_ratio_so_gain_cannot_move_it():
    """The whole reason this threshold is trustworthy where an absolute level is not. The
    level threshold measured beside it was taken at 30 dB gain and stopped meaning anything
    the day this receiver was recalibrated to 38.6; p90-over-p10 multiplies out."""
    print("dead carrier — gain independence")
    with tempfile.TemporaryDirectory() as d:
        quiet, loud = os.path.join(d, "q.wav"), os.path.join(d, "l.wav")
        write_speechlike(quiet, 6.0, amplitude=2000)     # a weak station
        write_speechlike(loud, 6.0, amplitude=20000)     # the same shape, 20 dB louder
        a, b = transcriber.flat_scan(quiet), transcriber.flat_scan(loud)
        check("a weak signal is not called a dead carrier", transcriber.flat_reason(a), "")
        check("nor a strong one", transcriber.flat_reason(b), "")
        check("and the level really is 20 dB apart",
              round(b["level_db"] - a["level_db"]) in (19, 20, 21), True)
        check("yet the two measure alike, which is the point",
              abs(a["dyn_db"] - b["dyn_db"]) < 2.0, True)


def test_a_clip_too_short_to_have_an_envelope_is_not_judged():
    """A two-second scrap can be flat by accident. MIN_CLIP_SECONDS has already thrown out
    what is too short to be speech, so there is nothing to gain by guessing here."""
    print("dead carrier — too short to say")
    with tempfile.TemporaryDirectory() as d:
        p = os.path.join(d, "short.wav")
        write_wav(p, 1.0, amplitude=8000)
        check("says nothing", transcriber.flat_scan(p), None)
        check("and no opinion is never a reason to drop", transcriber.flat_reason(None), "")

        check("an unreadable file says nothing either",
              transcriber.flat_scan(os.path.join(d, "nope.wav")), None)



# ── the outbox ───────────────────────────────────────────────────────────────

def clear_backoff(box):
    """Pretend the backoff window has elapsed, so a test need not sleep through it."""
    for path in box.pending():
        with open(path) as fh:
            entry = json.load(fh)
        entry["next_try"] = 0
        with open(path, "w") as fh:
            json.dump(entry, fh)


def test_outbox_order_and_retry():
    print("Outbox")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(d)
        box.add("first", 1000.0)
        box.add("second", 1001.0)
        box.add("third", 1002.0)

        sent = []

        # Second send fails: everything after it must stay put, or a later entry would
        # overtake an earlier one and the log would be out of order.
        def flaky(entry):
            sent.append(entry["text"])
            return transcriber.POST_OK if entry["text"] != "second" else transcriber.POST_RETRY

        check("stops at the failure", box.flush(flaky), False)
        check("sent up to the failure", sent, ["first", "second"])
        check("two still waiting", len(box.pending()), 2)

        # A failure schedules a retry in the future, so an immediate second pass must
        # not hammer the server — it should decline to send anything at all.
        sent.clear()
        check("backs off rather than retrying at once", box.flush(flaky), False)
        check("and sent nothing while backing off", sent, [])

        clear_backoff(box)
        sent.clear()
        ok = lambda e: (sent.append(e["text"]), transcriber.POST_OK)[1]
        check("drains once the server recovers", box.flush(ok), True)
        check("in order", sent, ["second", "third"])
        check("nothing left", box.pending(), [])


def test_outbox_drops_corrupt_entries():
    print("Outbox — corrupt entry")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(d)
        with open(os.path.join(d, "1000.000000.json"), "w") as fh:
            fh.write("{not json")
        box.add("good", 1001.0)
        sent = []
        check("flushes past it", box.flush(lambda e: (sent.append(e["text"]), transcriber.POST_OK)[1]), True)
        check("kept the good one", sent, ["good"])
        check("removed the bad one", box.pending(), [])


# ── posting ──────────────────────────────────────────────────────────────────

class Handler(BaseHTTPRequestHandler):
    status = 200
    body = b'{"ok":true}'
    seen = []

    def do_POST(self):
        n = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(n)
        ct = self.headers.get("Content-Type", "")
        if "multipart/form-data" in ct:
            entry = parse_multipart(raw, ct)
        else:
            entry = json.loads(raw or b"{}")
        entry["_ct"] = ct
        entry["_ua"] = self.headers.get("User-Agent", "")
        Handler.seen.append(entry)
        self.send_response(Handler.status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(Handler.body)))
        self.end_headers()
        self.wfile.write(Handler.body)

    def log_message(self, *_a):
        pass


def parse_multipart(raw, content_type):
    """Enough multipart to check what the device actually sent.

    Deliberately parses the wire bytes rather than trusting the builder that produced
    them: the point of these tests is that PHP's parser will find the fields, and a
    round trip through the same code that made them would prove nothing about that.
    """
    boundary = content_type.split("boundary=", 1)[1].strip().encode()
    out = {}
    for part in raw.split(b"--" + boundary):
        if not part.strip(b"-\r\n"):
            continue
        head, _, payload = part.partition(b"\r\n\r\n")
        payload = payload.rstrip(b"\r\n")
        m = re.search(rb'name="([^"]+)"', head)
        if not m:
            continue
        name = m.group(1).decode()
        if b"filename=" in head:
            out["_file_" + name] = payload
            fn = re.search(rb'filename="([^"]*)"', head)
            out["_filename"] = fn.group(1).decode() if fn else ""
        else:
            out[name] = payload.decode("utf-8", "replace")
    return out


def serve():
    srv = HTTPServer(("127.0.0.1", 0), Handler)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


def channel_for(port, token="tok-rx"):
    return transcriber.Channel({
        "id": "rx1-146520", "label": "146.520", "token": token,
        "frequency": "146520000", "serial": "00000001",
        "server": f"http://127.0.0.1:{port}",
    })


def test_posting():
    print("post_log_entry")
    srv = serve()
    port = srv.server_address[1]
    ch = channel_for(port)

    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true}'
    check("accepted", transcriber.post_log_entry(ch, "aid three clear"), transcriber.POST_OK)
    check("sent the token", Handler.seen[-1]["token"], "tok-rx")
    check("sent the text", Handler.seen[-1]["text"], "aid three clear")
    # Cloudflare blocks urllib's default agent with a 403 that looks exactly like a
    # rejected token. An explicit one is required, not cosmetic.
    check("identifies itself", Handler.seen[-1]["_ua"].startswith("MARS-Transcriber/"), True)
    check("is not the urllib default", "Python-urllib" in Handler.seen[-1]["_ua"], False)

    # 5xx is the server's problem and may pass; keep the entry and retry.
    Handler.status, Handler.body = 503, b"busy"
    check("retries a 5xx", transcriber.post_log_entry(ch, "x"), transcriber.POST_RETRY)

    # 403 is kept, not dropped. An auth failure is nearly always a token change still
    # propagating, and discarding on it lost four real transmissions the first time a
    # channel was renamed under a running device.
    Handler.status, Handler.body = 403, b"Forbidden"
    check("KEEPS a 403 to retry", transcriber.post_log_entry(ch, "x"), transcriber.POST_RETRY)

    # A malformed body stays malformed however many times it is sent.
    Handler.status, Handler.body = 400, b"Bad Request"
    check("drops a 400", transcriber.post_log_entry(ch, "x"), transcriber.POST_DROP)

    Handler.status, Handler.body = 200, b'{"error":"text required"}'
    check("drops an application error", transcriber.post_log_entry(ch, "x"), transcriber.POST_DROP)
    srv.shutdown()


def test_unreachable_server_is_retried():
    print("post_log_entry — server down")
    ch = channel_for(1)          # nothing listening on port 1
    check("retries", transcriber.post_log_entry(ch, "x", timeout=2), transcriber.POST_RETRY)


# ── sending the audio ────────────────────────────────────────────────────────

def test_an_entry_with_no_clip_is_still_plain_json():
    print("post_log_entry — no audio")
    srv = serve()
    ch = channel_for(srv.server_address[1])
    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true}'

    transcriber.post_log_entry(ch, "aid three clear")

    # The overwhelmingly common case, and it must not have grown a multipart envelope
    # around it just because the code can now build one.
    check("stays JSON", Handler.seen[-1]["_ct"], "application/json")
    check("and carries the text", Handler.seen[-1]["text"], "aid three clear")
    srv.shutdown()


def test_a_clip_is_sent_alongside_the_text():
    print("post_log_entry — with audio")
    srv = serve()
    ch = channel_for(srv.server_address[1])
    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true}'

    with tempfile.TemporaryDirectory() as d:
        clip = os.path.join(d, "1000.500000.m4a")
        with open(clip, "wb") as fh:
            fh.write(b"\x00\x00\x00\x20ftypM4A ")
        check("accepted", transcriber.post_log_entry(ch, "aid three clear", clip, 4.5),
              transcriber.POST_OK)

    sent = Handler.seen[-1]
    check("switches to multipart", "multipart/form-data" in sent["_ct"], True)
    check("token is a field", sent["token"], "tok-rx")
    check("text is a field", sent["text"], "aid three clear")
    check("duration goes with it", sent["audio_secs"], "4.50")
    check("the bytes arrive intact", sent["_file_audio"], b"\x00\x00\x00\x20ftypM4A ")
    check("named .m4a", sent["_filename"].endswith(".m4a"), True)
    # Still the explicit agent: Cloudflare's bot rule does not care that the body shape
    # changed, and a 403 here would look exactly like a rejected token.
    check("still identifies itself", sent["_ua"].startswith("MARS-Transcriber/"), True)
    srv.shutdown()


def test_a_missing_clip_does_not_hold_back_the_entry():
    print("post_log_entry — clip gone")
    srv = serve()
    ch = channel_for(srv.server_address[1])
    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true}'

    # The card was cleared, or the entry outlived its clip. The transcription is the
    # record; losing the audio must not lose the line.
    check("sends anyway", transcriber.post_log_entry(ch, "aid three clear", "/nope/gone.m4a"),
          transcriber.POST_OK)
    check("as plain JSON", Handler.seen[-1]["_ct"], "application/json")
    check("with the text intact", Handler.seen[-1]["text"], "aid three clear")
    srv.shutdown()


def test_the_recording_is_sent_before_anything_is_transcribed():
    print("post_log_audio — audio goes first")
    srv = serve()
    ch = channel_for(srv.server_address[1])
    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true,"id":991}'

    with tempfile.TemporaryDirectory() as d:
        clip = os.path.join(d, "1000.000000.m4a")
        with open(clip, "wb") as fh:
            fh.write(b"\x00\x00\x00\x20ftypM4A ")
        check("returns the entry it created",
              transcriber.post_log_audio(ch, clip, 4.5), 991)

    sent = Handler.seen[-1]
    check("multipart", "multipart/form-data" in sent["_ct"], True)
    check("carries the token", sent["token"], "tok-rx")
    check("and the duration", sent["audio_secs"], "4.50")
    check("and the bytes", sent["_file_audio"], b"\x00\x00\x00\x20ftypM4A ")
    check("but no text — there is none yet", "text" in sent, False)
    srv.shutdown()


def test_a_failed_recording_does_not_cost_the_entry():
    print("post_log_audio — server refuses")
    srv = serve()
    ch = channel_for(srv.server_address[1])
    Handler.status, Handler.body = 500, b"nope"
    with tempfile.TemporaryDirectory() as d:
        clip = os.path.join(d, "1000.000000.m4a")
        with open(clip, "wb") as fh:
            fh.write(b"x")
        # None, not an exception and not a retry. Audio is a listening aid with a
        # six-hour life; the text still goes through the outbox and is never at risk.
        check("returns nothing rather than raising",
              transcriber.post_log_audio(ch, clip, 1.0), None)
    srv.shutdown()


def test_the_words_name_the_entry_the_recording_made():
    print("post_log_entry — completing an audio-first entry")
    srv = serve()
    ch = channel_for(srv.server_address[1])
    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true,"id":991}'

    transcriber.post_log_entry(ch, "aid three clear", entry_id=991)
    sent = Handler.seen[-1]
    check("plain JSON — the audio already went", sent["_ct"], "application/json")
    check("names the entry", sent["entry_id"], 991)
    check("with the words", sent["text"], "aid three clear")

    # Without one, it creates its own entry exactly as before.
    transcriber.post_log_entry(ch, "on its own")
    check("no entry_id when there was no recording",
          "entry_id" in Handler.seen[-1], False)
    srv.shutdown()


def test_the_outbox_carries_the_clip_and_cleans_it_up():
    print("Outbox — audio")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(os.path.join(d, "outbox"))
        os.makedirs(box.audio_dir, exist_ok=True)
        clip = os.path.join(box.audio_dir, "1000.000000.m4a")
        with open(clip, "wb") as fh:
            fh.write(b"audio")
        box.add("heard this", 1000.0, clip, 3.25)

        seen = []
        check("delivered", box.flush(lambda e: (seen.append(e), transcriber.POST_OK)[1]), True)
        check("the entry named its clip", seen[0]["audio"], clip)
        check("and its duration", seen[0]["seconds"], 3.25)
        # The clip is the one thing that does not clean itself up: the entry is a file
        # the outbox made, the audio is a file it was handed. Leaking it on any exit
        # fills the card over a long net.
        check("the clip is gone once sent", os.path.exists(clip), False)
        check("and so is the entry", box.pending(), [])


def test_a_refused_entry_takes_its_clip_with_it():
    print("Outbox — audio, entry refused")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(os.path.join(d, "outbox"))
        os.makedirs(box.audio_dir, exist_ok=True)
        clip = os.path.join(box.audio_dir, "1000.000000.m4a")
        with open(clip, "wb") as fh:
            fh.write(b"audio")
        box.add("malformed somehow", 1000.0, clip, 1.0)

        box.flush(lambda e: transcriber.POST_DROP)
        check("dropped entries do not leak their audio", os.path.exists(clip), False)


def test_a_clip_survives_while_its_entry_is_still_waiting():
    print("Outbox — audio, still retrying")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(os.path.join(d, "outbox"))
        os.makedirs(box.audio_dir, exist_ok=True)
        clip = os.path.join(box.audio_dir, "1000.000000.m4a")
        with open(clip, "wb") as fh:
            fh.write(b"audio")
        box.add("heard this", 1000.0, clip, 1.0)

        box.flush(lambda e: transcriber.POST_RETRY)
        # The whole reason the outbox exists is an outage. Deleting the clip on a failed
        # attempt would mean everything sent after one blinks arrives without audio.
        check("kept for the retry", os.path.exists(clip), True)
        check("and so is the entry", len(box.pending()), 1)


def test_evicting_an_old_entry_deletes_its_clip_too():
    print("Outbox — audio, cap eviction")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(os.path.join(d, "outbox"))
        os.makedirs(box.audio_dir, exist_ok=True)
        box.cap = 2
        clips = []
        for i in range(4):
            c = os.path.join(box.audio_dir, "%d.m4a" % i)
            with open(c, "wb") as fh:
                fh.write(b"audio")
            clips.append(c)
            box.add("entry %d" % i, 1000.0 + i, c, 1.0)

        check("only the cap is kept", len(box.pending()), 2)
        check("the evicted clips went with them", [os.path.exists(c) for c in clips],
              [False, False, True, True])


def test_an_outbox_written_by_an_older_build_still_flushes():
    print("Outbox — entry from an older build")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(os.path.join(d, "outbox"))
        # No "audio" or "seconds" key at all — exactly what upgrading mid-net leaves
        # behind. It must read as "no clip", not as a broken entry.
        with open(os.path.join(box.dir, "1000.000000.json"), "w") as fh:
            json.dump({"text": "from before", "ts": 1000.0, "attempts": 0, "next_try": 0}, fh)

        seen = []
        check("flushes", box.flush(lambda e: (seen.append(e), transcriber.POST_OK)[1]), True)
        check("with its text", seen[0]["text"], "from before")
        check("and no clip", seen[0].get("audio"), None)


def test_clips_are_levelled_to_one_volume():
    print("normalize — every over the same loudness")
    with tempfile.TemporaryDirectory() as d:
        def wav_at(name, amplitude):
            p = os.path.join(d, name)
            write_wav(p, 2.0, amplitude=amplitude)
            return p

        # Real traffic measured on this receiver ran -11.5 to -50.8 dBFS. Both ends have
        # to arrive at the same place or the listener is still working the volume knob.
        loud = wav_at("loud.wav", 8000)     # ~ -12 dBFS
        quiet = wav_at("quiet.wav", 100)    # ~ -50 dBFS

        lvl_loud = transcriber.clip_level_dbfs(loud)
        lvl_quiet = transcriber.clip_level_dbfs(quiet)
        check("the loud one measures loud", lvl_loud > -20, True)
        check("the quiet one measures quiet", lvl_quiet < -40, True)

        f_loud = transcriber.normalize_filter(loud)
        f_quiet = transcriber.normalize_filter(quiet)
        # A loud over is brought DOWN, not merely left alone: boost-only narrows the
        # spread without closing it.
        check("the loud one is attenuated", "volume=-" in f_loud, True)
        check("the quiet one is boosted", "volume=2" in f_quiet or "volume=3" in f_quiet, True)
        # RMS says nothing about peaks, and a clipped consonant is worse than a quiet clip.
        check("both keep a peak ceiling",
              "alimiter" in f_loud and "alimiter" in f_quiet, True)


def test_silence_is_never_amplified():
    """The mistake the first attempt made.

    ffmpeg's loudnorm targets an absolute loudness and has no ceiling on the boost it
    will apply to reach it. Handed a scrap of near-silence at -73 dBFS it produced -1.5
    — a full-scale blast of nothing. The audio path now runs BEFORE whisper, so at this
    point nothing has judged whether there is any speech in the clip at all, and a clip
    that is mostly squelch hiss would get exactly that treatment."""
    print("normalize — silence stays silent")
    with tempfile.TemporaryDirectory() as d:
        p = os.path.join(d, "silence.wav")
        write_wav(p, 2.0, amplitude=1)
        lvl = transcriber.clip_level_dbfs(p)
        check("measures as silence", lvl < transcriber.AUDIO_SILENCE_DBFS, True)
        check("and is left alone entirely", transcriber.normalize_filter(p), None)


def test_the_boost_is_bounded():
    print("normalize — the boost has a ceiling")
    # Whatever the measurement says. A clip just above the silence floor must not be
    # lifted by fifty decibels merely because the arithmetic allows it.
    for level in (-64.0, -60.0, -55.0):
        gain = max(-transcriber.AUDIO_MAX_CUT_DB,
                   min(transcriber.AUDIO_MAX_GAIN_DB, transcriber.AUDIO_TARGET_DBFS - level))
        check(f"{level} dBFS is boosted no more than the cap",
              gain <= transcriber.AUDIO_MAX_GAIN_DB, True)


def test_a_missing_encoder_costs_the_audio_and_nothing_else():
    print("encode_audio — no ffmpeg")
    with tempfile.TemporaryDirectory() as d:
        wav = os.path.join(d, "a.wav")
        write_wav(wav, 1.0)
        real = transcriber.subprocess.run

        def missing(*_a, **_k):
            raise FileNotFoundError("ffmpeg")

        transcriber.subprocess.run = missing
        try:
            # None is an ordinary outcome here, not an error to fail over. A Pi without
            # ffmpeg must keep transcribing and logging exactly as it did before.
            check("returns nothing rather than raising",
                  transcriber.encode_audio(wav, os.path.join(d, "audio"), 1000.0), None)
        finally:
            transcriber.subprocess.run = real


def test_a_failed_encode_leaves_nothing_behind():
    print("encode_audio — encoder fails")
    with tempfile.TemporaryDirectory() as d:
        wav = os.path.join(d, "a.wav")
        write_wav(wav, 1.0)
        out = os.path.join(d, "audio")
        real = transcriber.subprocess.run

        class Failed:
            returncode = 1
            stderr = b"Invalid data found when processing input"

        def fails(cmd, **_k):
            # Half-write the destination, as a real encoder would before giving up.
            os.makedirs(out, exist_ok=True)
            with open(cmd[-1], "wb") as fh:
                fh.write(b"partial")
            return Failed()

        transcriber.subprocess.run = fails
        try:
            check("no path returned",
                  transcriber.encode_audio(wav, out, 1000.0), None)
            # A truncated .m4a that reached the server would be a clip every subscribed
            # phone fetches and fails to play.
            check("and no half-written file left", os.listdir(out), [])
        finally:
            transcriber.subprocess.run = real


def test_an_oversized_clip_is_refused_locally():
    print("encode_audio — too big for the server")
    with tempfile.TemporaryDirectory() as d:
        wav = os.path.join(d, "a.wav")
        write_wav(wav, 1.0)
        out = os.path.join(d, "audio")
        real = transcriber.subprocess.run

        class Ok:
            returncode = 0
            stderr = b""

        def huge(cmd, **_k):
            os.makedirs(out, exist_ok=True)
            with open(cmd[-1], "wb") as fh:
                fh.write(b"x" * (transcriber.AUDIO_MAX_BYTES + 1))
            return Ok()

        transcriber.subprocess.run = huge
        try:
            # Caught here rather than at the server: an entry must never be held back
            # retrying a clip that can never be accepted.
            check("refused", transcriber.encode_audio(wav, out, 1000.0), None)
            check("and deleted", os.listdir(out), [])
        finally:
            transcriber.subprocess.run = real


# ── the whole pipeline, no radio ─────────────────────────────────────────────

def stub_whisper(directory, says):
    """A stand-in for whisper.cpp that prints whatever the test wants it to hear."""
    path = os.path.join(directory, "whisper-stub")
    with open(path, "w") as fh:
        fh.write("#!/bin/sh\ncat <<'EOF'\n" + says + "\nEOF\n")
    os.chmod(path, 0o755)
    return path


def run_pipeline(tmp, says, clip_seconds=3.0, vocabulary=None, channel=None, audio=None):
    """One --spool-only pass over a single clip. Returns the entries the server got.

    `channel` is merged into the channel entry, for the settings a test wants to set the
    way the manager would rather than by reaching into the module.

    `audio` writes the clip when the test cares what is in it — silence otherwise, which
    is what every test that only exercises the text filters wants.
    """
    spool = os.path.join(tmp, "spool")
    os.makedirs(spool, exist_ok=True)
    clip = os.path.join(spool, "clip_001.wav")
    if audio:
        audio(clip)
    else:
        write_wav(clip, clip_seconds)
    # settled_clips skips anything sox might still be appending to, so backdate the
    # mtime past that window. Without this the clip is simply not picked up and every
    # assertion about the filters passes for the wrong reason.
    old = os.path.getmtime(clip) - 5
    os.utime(clip, (old, old))

    models = os.path.join(tmp, "models")
    os.makedirs(models, exist_ok=True)
    open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()

    srv = serve()
    port = srv.server_address[1]
    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true}'

    config = os.path.join(tmp, "channels.json")
    entry = {
        "id": "rx1-146520", "label": "146.520", "token": "tok-rx",
        "frequency": "146520000", "serial": "00000001",
        "server": f"http://127.0.0.1:{port}",
    }
    entry.update(channel or {})
    with open(config, "w") as fh:
        json.dump({"channels": [entry], "vocabulary": vocabulary or {}}, fh)

    rc = transcriber.main([
        "--channel", "rx1-146520", "--config", config, "--spool", spool,
        "--whisper", stub_whisper(tmp, says), "--models", models,
        "--spool-only", "--once",
    ])
    srv.shutdown()
    return rc, [e["text"] for e in Handler.seen if "text" in e]


def test_a_clip_is_queued_once_not_once_per_loop():
    """With a radio attached, the directory scanner must never run.

    The capture loop writes each clip and queues it itself. Scanning the same directory
    queues a SECOND reference to a file that is already waiting — then a third, and a
    fourth, several times a second for as long as it sits there.

    That was invisible while transcription ran inline: the clip was unlinked before the
    next scan. With a worker thread the file waits, the duplicates pile up, and the
    backlog cap starts dropping the oldest entry — which deletes real clips nobody has
    transcribed yet. On the air it looked like the second transmission of the day simply
    never arriving, with the journal filling with FileNotFoundError from duplicates
    chasing a file the worker had already finished with.
    """
    print("one clip, one queue entry")
    with tempfile.TemporaryDirectory() as tmp:
        models = os.path.join(tmp, "models"); os.makedirs(models)
        open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()
        clips = os.path.join(tmp, "clips"); os.makedirs(clips)
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1-146520", "token": "t",
                                     "frequency": "146520000", "serial": "1"}]}, fh)

        # A radio that produces nothing. Enough to make rtl non-None, which is the whole
        # condition under test; what it emits does not matter.
        import subprocess as sp
        fake = [sys.executable, "-c", "import time; time.sleep(30)"]
        scans, queued = [], []

        def no_capture(channel, clips_dir, spool):
            return sp.Popen(fake, stdout=sp.PIPE, stderr=sp.DEVNULL)

        real_settled = transcriber.settled_clips
        real_put = transcriber.ClipQueue.put
        # Calls through to the real scanner rather than returning nothing, so the
        # "nothing is queued" check below would actually see the duplicate.
        transcriber.settled_clips = lambda d, **kw: (scans.append(d),
                                                     real_settled(d, **kw))[1]
        transcriber.ClipQueue.put = lambda self, p: queued.append(p)
        real_capture, transcriber.start_capture = transcriber.start_capture, no_capture
        real_alive, transcriber.receiver_alive = transcriber.receiver_alive, lambda ch: True
        died = []
        try:
            def run():
                try:
                    transcriber.main([
                        "--channel", "rx1-146520", "--config", config, "--spool", tmp,
                        "--clips", clips, "--whisper", stub_whisper(tmp, "hello"),
                        "--models", models])
                except BaseException as e:                       # noqa: BLE001
                    died.append(repr(e))

            worker = threading.Thread(target=run, daemon=True)
            worker.start()
            __import__("time").sleep(0.4)      # let it sweep and enter the loop

            # Now put a clip where the capture loop would have written one. It has to go
            # in after startup, because sweep_clips clears the directory first — placing
            # it earlier is how the previous version of this check came to be testing
            # nothing at all. Backdate it past the settle window so the scanner, if it
            # ran, would take it immediately.
            stray = os.path.join(clips, "clip_00001.wav")
            write_wav(stray, 3.0)
            old = os.path.getmtime(stray) - 5
            os.utime(stray, (old, old))
            __import__("time").sleep(1.2)      # many passes through the main loop
            # The assertions below are about something NOT happening, so they pass for
            # free if the loop never ran. The first version of this test did exactly
            # that — main() died installing signal handlers off the main thread — and
            # reported a pass against the very bug it was written for.
            check("the capture loop is actually running", (worker.is_alive(), died),
                  (True, []))
        finally:
            transcriber.settled_clips = real_settled
            transcriber.ClipQueue.put = real_put
            transcriber.start_capture = real_capture
            transcriber.receiver_alive = real_alive

        check("the directory is never scanned with a radio attached", scans, [])
        check("and nothing is queued from it", queued, [])


def test_pipeline_logs_speech():
    print("pipeline — a real transmission")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "aid three we have a rider down")
        check("exit 0", rc, 0)
        check("one entry", sent, ["aid three we have a rider down."])


def test_pipeline_corrects_a_callsign_but_only_after_the_guards():
    """Where the correction sits in the pipeline is the whole of its safety.

    It runs on an entry loggable() has already accepted, never before it. Both guards
    are calibrated on what whisper emits — HALLUCINATIONS on its exact wording,
    loop_ratio on its repetition — so rewriting the words underneath them changes what
    they are measuring, and the cost of that is a real transmission thrown away.

    Net control working down a list is the case that shows it, and it is an ordinary
    evening's traffic: the same station answered four times, spelled four different ways
    by whisper because it heard four different manglings. As heard, every trigram in it
    is distinct and it is plainly real. Corrected, it is the same six words four times
    over and scores 0.30 — well under LOOP_RATIO — so correcting first hands the guard a
    text that looks exactly like the failure it exists to catch, and the whole entry
    goes in the bin.
    """
    print("pipeline — a callsign on the way to the log")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "K-6 DRK testing on West Marin",
                                vocabulary=ROSTER)
        check("exit 0", rc, 0)
        check("the entry reaches the log spelled properly",
              sent, ["K6DRK testing on West Marin."])

    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(
            tmp, "K-60RK go ahead. K-6 DRK go ahead. kilo six delta romeo kilo go "
                 "ahead. K-60 RK go ahead.", vocabulary=ROSTER)
        check("a roll call survives being corrected, because it is judged first",
              sent, ["K6DRK go ahead. K6DRK go ahead. K6DRK go ahead. K6DRK go ahead."])

    # The same roster, over text the guards reject. Nothing is posted, and in particular
    # nothing is posted with a tidied-up callsign in it.
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(
            tmp,
            "K-6 DRK. I love you. I love you. I love you. I love you. I love you. "
            "I love you. I love you.",
            vocabulary=ROSTER)
        check("a looping transcription is still rejected whole", sent, [])

    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "Thank you.", vocabulary=ROSTER)
        check("and so is squelch noise", sent, [])


def test_pipeline_discards_hallucination():
    print("pipeline — squelch noise")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "Thank you.")
        check("exit 0", rc, 0)
        check("nothing logged", sent, [])


def test_pipeline_transcribes_a_capped_clip_rather_than_binning_it():
    """What the field failure looked like from the log: nothing.

    The capture loop cut a clip at the cap, the reads overshot to 120.1s, and
    `if seconds > MAX_CLIP_SECONDS` deleted it before whisper ever saw it. A hundred
    and twenty seconds of a repeater went in the bin for every one of them, and all the
    journal said was "discarding 120s clip — open carrier?".

    Two minutes of unbroken carrier is still a fault worth a warning — an over here runs
    ten to twenty seconds — but the audio was recorded, and whether there are voices in
    it is the one question that says which fault it is. So it gets transcribed.
    """
    print("pipeline — a clip cut at the cap")
    with tempfile.TemporaryDirectory() as tmp:
        # The exact length the field failure produced, not a round number over it.
        rc, sent = run_pipeline(tmp, "aid three we have a rider down",
                                clip_seconds=transcriber.MAX_CLIP_SECONDS + 0.1)
        check("exit 0", rc, 0)
        check("what was on the air reaches the log",
              sent, ["aid three we have a rider down."])


def test_pipeline_discards_short_clip():
    print("pipeline — key-up")
    with tempfile.TemporaryDirectory() as tmp:
        # Under MIN_CLIP_SECONDS, so whisper is never even asked.
        rc, sent = run_pipeline(tmp, "aid three we have a rider down", clip_seconds=0.5)
        check("exit 0", rc, 0)
        check("nothing logged", sent, [])


# ── keeping the audio ────────────────────────────────────────────────────────
#
# Retention exists to build a corpus, and a corpus is only useful if it is complete and
# if nothing about it can take the receiver off the air. Both halves are tested here: what
# gets kept, and what happens when keeping it fails.

def retained(tmp):
    """(the wavs kept, the manifest lines) after a run_pipeline pass."""
    directory = os.path.join(tmp, "spool", "recordings")
    if not os.path.isdir(directory):
        return [], []
    wavs = sorted(n for n in os.listdir(directory) if n.endswith(".wav"))
    lines = []
    manifest = os.path.join(directory, "manifest.jsonl")
    if os.path.exists(manifest):
        with open(manifest) as fh:
            lines = [json.loads(ln) for ln in fh if ln.strip()]
    return wavs, lines


def test_the_audio_is_thrown_away_unless_somebody_asked_to_keep_it():
    """Off is the normal state and the default, and with it off nothing about the
    channel is different — the card is not touched at all, not even to make the
    directory."""
    print("retention — off unless asked")
    check("a channel that says nothing keeps nothing",
          transcriber.Channel({"id": "rx1"}).record_until, 0.0)
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "aid three we have a rider down")
        check("exit 0", rc, 0)
        check("the entry reaches the log as before",
              sent, ["aid three we have a rider down."])
        check("and nothing is kept", retained(tmp), ([], []))
        check("not even a directory to keep it in",
              os.path.exists(os.path.join(tmp, "spool", "recordings")), False)


def test_a_window_that_has_passed_keeps_nothing():
    """The whole reason retention is asked for with an expiry rather than a switch: a
    switch left on records until the card fills, and a card that fills takes the receiver
    off the air. An hour after the net, this has to be over whether or not anybody
    remembered."""
    print("retention — an expired window")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "aid three we have a rider down",
                                channel={"record_until": time.time() - 60})
        check("exit 0", rc, 0)
        check("the entry still reaches the log",
              sent, ["aid three we have a rider down."])
        check("and nothing is kept", retained(tmp), ([], []))

    # The control, and it is not optional: "nothing was kept" passes for free on a
    # channel where nothing is ever kept, which is how a test comes to pass against the
    # very code it was written for. The same setting an hour the other way must record.
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "aid three we have a rider down",
                                channel={"record_until": time.time() + 3600})
        check("while the same setting still in the future does keep the clip",
              len(retained(tmp)[0]), 1)

    # And the case that will actually happen, which the two above between them do not
    # cover: the deadline passes while the channel is running. Nothing restarts at that
    # moment and nothing is watching the clock, so it is the check made as each clip
    # arrives that has to stop it — with only the startup check, both of the tests above
    # still pass and the recording runs until the card fills.
    with tempfile.TemporaryDirectory() as tmp:
        clip = os.path.join(tmp, "clip_00001.wav")
        write_wav(clip, 1.0)
        keep_dir = os.path.join(tmp, "recordings")
        r = transcriber.Retention(keep_dir, time.time() + 0.3)
        r.keep(clip, 1.0, "inside the window", "inside the window",
               "inside the window", "")
        time.sleep(0.4)
        r.keep(clip, 1.0, "after it closed", "after it closed", "after it closed", "")
        check("the clip inside the window is kept, the one after it is not",
              len([n for n in os.listdir(keep_dir) if n.endswith(".wav")]), 1)
        check("and it has stopped of its own accord", r.on, False)


def test_the_manifest_says_what_each_clip_became():
    """Audio alone is half a corpus. Judging whether a tone detector would have helped
    means knowing what each clip actually produced — so every retained wav has a line
    beside it, and the line names the file it is about."""
    print("retention — the manifest beside the audio")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "(water splashing) aid three we have a rider down",
                                channel={"record_until": time.time() + 3600})
        wavs, lines = retained(tmp)
        check("the clip is on the card", len(wavs), 1)
        check("with exactly one line about it", len(lines), 1)
        line = lines[0] if lines else {}
        check("the line names the wav", line.get("file"), wavs[0] if wavs else None)
        check("and its length", round(line.get("seconds", 0)), 3)
        check("what whisper returned", line.get("whisper"),
              "(water splashing) aid three we have a rider down")
        check("what clean() left of it", line.get("clean"),
              "aid three we have a rider down")
        check("that loggable() kept it", line.get("kept"), True)
        check("with nothing to explain", line.get("why"), "")
        check("and the log agrees", sent, ["aid three we have a rider down."])

    # And it lines up with the event log word for word, callsign corrections and all.
    # Anyone using this corpus starts from a line in the log — "the ID at about ten
    # past" — and has to be able to find the clip it came from; a manifest holding the
    # text as whisper spelled it would not match the entry anybody is looking at.
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "K-6 DRK testing on West Marin", vocabulary=ROSTER,
                                channel={"record_until": time.time() + 3600})
        wavs, lines = retained(tmp)
        line = lines[0] if lines else {}
        check("the manifest holds what whisper heard", line.get("whisper"),
              "K-6 DRK testing on West Marin")
        check("and what the log was actually sent", [line.get("logged")], sent)


def test_the_manifest_says_why_a_clip_was_not_logged():
    """The clips that produced nothing are the interesting ones — a courtesy beep and a
    Morse ID both land here — so the reason has to be recorded, not just the fact."""
    print("retention — why a clip produced nothing")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "Beep", channel={"record_until": time.time() + 3600})
        wavs, lines = retained(tmp)
        check("nothing is logged", sent, [])
        check("but the audio is kept", len(wavs), 1)
        line = lines[0] if lines else {}
        check("the line says whisper heard the tone as a word", line.get("whisper"), "Beep")
        check("that it was not logged", line.get("kept"), False)
        check("and says why", bool(line.get("why")), True)


def test_a_clip_too_short_to_transcribe_is_still_worth_keeping():
    """MIN_CLIP_SECONDS throws away key-ups and squelch tails without asking whisper —
    and a courtesy beep in a clip of its own is often exactly that long. The clips that
    rule discards are therefore among the ones a tone detector most needs to be built
    against, so retention takes them too and the manifest says whisper never ran."""
    print("retention — the clips the pipeline never transcribes")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "aid three we have a rider down", clip_seconds=0.5,
                                channel={"record_until": time.time() + 3600})
        wavs, lines = retained(tmp)
        check("nothing is logged, exactly as before", sent, [])
        check("the audio is kept anyway", len(wavs), 1)
        line = lines[0] if lines else {}
        check("whisper was never asked", line.get("whisper"), "")
        check("and the line says so", "short" in (line.get("why") or ""), True)


def test_a_clip_that_cannot_be_kept_does_not_stop_the_channel():
    """Recording is strictly secondary to receiving. A card that has filled, a directory
    that has gone, a permission changed underneath us — every one of those stops the
    recording and none of them may cost the log a single entry."""
    print("retention — a write that fails")
    # The control first. Without it this test asks only that the log still works, which
    # it does on a channel that never tried to record anything at all.
    with tempfile.TemporaryDirectory() as tmp:
        run_pipeline(tmp, "aid three we have a rider down",
                     channel={"record_until": time.time() + 3600})
        check("with the card writable the clip is kept", len(retained(tmp)[0]), 1)

    with tempfile.TemporaryDirectory() as tmp:
        os.makedirs(os.path.join(tmp, "spool"), exist_ok=True)
        # A plain file where the directory belongs. Everything under it then fails, and
        # it fails for root as well — which a chmod would not, and these run as root.
        with open(os.path.join(tmp, "spool", "recordings"), "w") as fh:
            fh.write("in the way")
        rc, sent = run_pipeline(tmp, "aid three we have a rider down",
                                channel={"record_until": time.time() + 3600})
        check("exit 0", rc, 0)
        check("and the transmission still reaches the log",
              sent, ["aid three we have a rider down."])


def test_the_byte_cap_stops_recording_but_not_receiving():
    """The second bound. Time alone does not bound the card: a stuck carrier or a
    squelch that has failed open would write for the whole window at the full rate."""
    print("retention — the second bound")
    with tempfile.TemporaryDirectory() as tmp:
        clip = os.path.join(tmp, "clip_00001.wav")
        write_wav(clip, 1.0)                    # 32 KB of audio, plus the header
        keep_dir = os.path.join(tmp, "recordings")
        r = transcriber.Retention(keep_dir, time.time() + 3600, max_bytes=40000)
        r.keep(clip, 1.0, "hello there", "hello there", "hello there", "")
        check("the first clip fits and is kept",
              len([n for n in os.listdir(keep_dir) if n.endswith(".wav")]), 1)
        r.keep(clip, 1.0, "hello there", "hello there", "hello there", "")
        check("the second would cross the cap, so it is not written",
              len([n for n in os.listdir(keep_dir) if n.endswith(".wav")]), 1)
        check("and nothing more will be", r.on, False)
        check("a clip is never half-written to fit",
              [ln for ln in open(os.path.join(keep_dir, "manifest.jsonl"))] != [], True)

        # And the cap survives a restart, which is not a rare thing for a channel to do
        # — DEAF_CHECK_SECONDS restarts one on purpose. A budget that started again from
        # zero each time would bound nothing at all over the length of a net.
        again = transcriber.Retention(keep_dir, time.time() + 3600, max_bytes=40000)
        again.keep(clip, 1.0, "after a restart", "after a restart",
                   "after a restart", "")
        check("what an earlier run kept still counts against it",
              len([n for n in os.listdir(keep_dir) if n.endswith(".wav")]), 1)

    # And the same thing through the channel: recording stops, the log does not.
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "aid three we have a rider down",
                                channel={"record_until": time.time() + 3600,
                                         "record_max_bytes": 1})
        check("nothing is kept", retained(tmp)[0], [])
        check("and the channel carries on logging",
              sent, ["aid three we have a rider down."])


def test_kept_clips_sort_in_the_order_they_were_heard():
    """The clip's own name can do neither job: seq starts again at 1 on every restart,
    so a clip from this run lands on one from the last run, and the order of the names
    is the order of the process rather than the order of the radio."""
    print("retention — names that sort and do not collide")
    with tempfile.TemporaryDirectory() as tmp:
        keep_dir = os.path.join(tmp, "recordings")
        # The same file name twice, as two restarts would produce it, an hour apart.
        first = os.path.join(tmp, "a", "clip_00001.wav")
        second = os.path.join(tmp, "b", "clip_00001.wav")
        for path in (first, second):
            os.makedirs(os.path.dirname(path), exist_ok=True)
            write_wav(path, 1.0)
        os.utime(first, (time.time() - 3600, time.time() - 3600))

        r = transcriber.Retention(keep_dir, time.time() + 3600)
        r.keep(second, 1.0, "later", "later", "later", "")     # newer one kept first
        r.keep(first, 1.0, "earlier", "earlier", "earlier", "")
        wavs = sorted(n for n in os.listdir(keep_dir) if n.endswith(".wav"))
        check("both survive", len(wavs), 2)
        with open(os.path.join(keep_dir, "manifest.jsonl")) as fh:
            lines = [json.loads(ln) for ln in fh if ln.strip()]
        by_name = {ln["file"]: ln["whisper"] for ln in lines}
        check("and sorting the names puts the earlier transmission first",
              [by_name.get(n) for n in wavs], ["earlier", "later"])


def test_an_idle_frequency_is_not_fatal():
    """sox creates its output file the instant it opens one and then waits, so on a
    quiet frequency there is always a 44-byte header sitting in the spool. Reading it
    raises EOFError, not wave.Error, and an uncaught one took the channel down within
    seconds of pointing it at an idle repeater — which is what a repeater is, most of
    the time. The empty file must be left alone: sox is about to write the next
    transmission into it."""
    print("idle frequency")
    with tempfile.TemporaryDirectory() as spool:
        header_only = os.path.join(spool, "clip_001.wav")
        with wave.open(header_only, "w") as w:      # opened, no frames written
            w.setnchannels(1); w.setsampwidth(2); w.setframerate(16000)
        old = os.path.getmtime(header_only) - 5
        os.utime(header_only, (old, old))

        check("header-only file measures 0s", transcriber.clip_seconds(header_only), 0.0)
        check("and is not harvested", transcriber.settled_clips(spool), [])
        check("and is left for sox to fill", os.path.exists(header_only), True)

        # A real clip beside it still gets picked up.
        real = os.path.join(spool, "clip_002.wav")
        write_wav(real, 3.0)
        os.utime(real, (old, old))
        check("a real clip is still harvested", transcriber.settled_clips(spool), [real])


def test_a_broken_whisper_is_fatal_not_silent():
    """The failure this guards against actually happened on the first real install:
    whisper-cli was present and executable but missing libwhisper.so.1, so every
    transcription returned nothing, the filters discarded it exactly as designed, and
    the channel was indistinguishable from a quiet frequency. Refusing to start makes
    systemd mark the unit failed, which somebody notices."""
    print("broken whisper")
    with tempfile.TemporaryDirectory() as tmp:
        broken = os.path.join(tmp, "whisper-broken")
        with open(broken, "w") as fh:
            fh.write("#!/bin/sh\necho 'error while loading shared libraries' >&2\nexit 127\n")
        os.chmod(broken, 0o755)

        models = os.path.join(tmp, "models")
        os.makedirs(models, exist_ok=True)
        open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1-146520", "label": "146.520",
                                     "token": "t", "frequency": "1", "serial": "1"}]}, fh)
        try:
            transcriber.main(["--channel", "rx1-146520", "--config", config,
                              "--spool", tmp, "--whisper", broken, "--models", models,
                              "--spool-only", "--once"])
            FAILURES.append("broken whisper: expected SystemExit, got a clean start")
        except SystemExit:
            print("  ok  refuses to start rather than logging nothing forever")

    # And a missing binary entirely.
    with tempfile.TemporaryDirectory() as tmp:
        models = os.path.join(tmp, "models")
        os.makedirs(models, exist_ok=True)
        open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1-146520", "label": "146.520",
                                     "token": "t", "frequency": "1", "serial": "1"}]}, fh)
        try:
            transcriber.main(["--channel", "rx1-146520", "--config", config,
                              "--spool", tmp, "--whisper", "/nonexistent/whisper",
                              "--models", models, "--spool-only", "--once"])
            FAILURES.append("missing whisper: expected SystemExit")
        except SystemExit:
            print("  ok  a missing binary is fatal too")


def test_a_disabled_channel_can_still_be_calibrated():
    """Measuring the site must not require the channel to be on the air first.

    The enabled check used to sit above the calibrate branch, so calibrating a channel
    that was switched off exited in three seconds with "nothing to do" and the manager
    reported "the receiver did not run the measurement" — true, and no help. Measuring
    BEFORE putting a channel on the air is the right order, and a disabled channel is
    the safest one to take off the air for a minute.
    """
    with tempfile.TemporaryDirectory() as tmp:
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "x@rx1", "label": "off", "token": "t",
                                     "frequency": "146700000", "serial": "0001",
                                     "enabled": False}]}, fh)
        # Reaching run_calibration is the whole assertion — it is allowed to fail for
        # want of a radio on the machine running the tests. What must NOT happen is the
        # early "disabled; nothing to do" return, which never touches the tuner at all.
        reached = {"yes": False}
        real = transcriber.run_calibration
        transcriber.run_calibration = lambda ch, spool: (reached.update(yes=True), 0)[1]
        try:
            rc = transcriber.main(["--channel", "x@rx1", "--config", config,
                                   "--spool", tmp, "--calibrate"])
        finally:
            transcriber.run_calibration = real
        check("calibration runs on a disabled channel", reached["yes"], True)
        check("and exits cleanly", rc, 0)


def test_a_disabled_channel_still_does_nothing_when_asked_to_listen():
    """The enabled check still applies to everything that is not calibration."""
    with tempfile.TemporaryDirectory() as tmp:
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "x@rx1", "label": "off", "token": "t",
                                     "frequency": "146700000", "serial": "0001",
                                     "enabled": False}]}, fh)
        called = {"yes": False}
        real = transcriber.run_calibration
        transcriber.run_calibration = lambda ch, spool: (called.update(yes=True), 0)[1]
        try:
            rc = transcriber.main(["--channel", "x@rx1", "--config", config, "--spool", tmp])
        finally:
            transcriber.run_calibration = real
        check("listening on a disabled channel exits 0", rc, 0)
        check("and does not calibrate", called["yes"], False)


def test_disabled_channel_does_nothing():
    print("disabled channel")
    with tempfile.TemporaryDirectory() as tmp:
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "x@rx1", "label": "x", "enabled": False}]}, fh)
        check("exits cleanly", transcriber.main(["--channel", "x@rx1", "--config", config]), 0)


def test_unknown_channel_is_fatal():
    print("unknown channel")
    with tempfile.TemporaryDirectory() as tmp:
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": []}, fh)
        try:
            transcriber.main(["--channel", "nope@rx1", "--config", config])
            FAILURES.append("unknown channel: expected SystemExit")
        except SystemExit:
            print("  ok  refuses to start")


# ── tones: courtesy beeps and Morse IDs ──────────────────────────────────────

def _tone_wav(path, seconds, hz=800, rate=16000, amplitude=9000, keyed=None):
    """A clip on one frequency. `keyed` gives Morse-like on/off in seconds per element."""
    n = int(rate * seconds)
    frames = []
    for i in range(n):
        on = True
        if keyed:
            on = int(i / (rate * keyed)) % 2 == 0
        v = amplitude * math.sin(2 * math.pi * hz * i / rate) if on else 0
        frames.append(struct.pack("<h", int(v)))
    with wave.open(path, "w") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(rate)
        w.writeframes(b"".join(frames))


def _speechlike_wav(path, seconds, rate=16000):
    """A stand-in for speech: pitch that moves, harmonics, and consonant-like noise.

    Not real speech, but it has the property the detector is built to test for — the
    frequency does not hold still — so a detector that fires on this would fire on a
    person, which is the failure that matters.
    """
    n = int(rate * seconds)
    frames = []
    state = 12345
    for i in range(n):
        t = i / float(rate)
        f0 = 120 + 60 * math.sin(2 * math.pi * 3.1 * t)      # pitch sweeping, as speech does
        v = 6000 * math.sin(2 * math.pi * f0 * t) + 2500 * math.sin(2 * math.pi * 2 * f0 * t)
        if int(t * 7) % 3 == 0:                               # bursts of consonant noise
            state = (1103515245 * state + 12345) % (1 << 31)
            v = (state % 12000) - 6000
        frames.append(struct.pack("<h", max(-32000, min(32000, int(v)))))
    with wave.open(path, "w") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(rate)
        w.writeframes(b"".join(frames))


def test_a_courtesy_tone_is_recognised_as_a_tone():
    with tempfile.TemporaryDirectory() as tmp:
        wav = os.path.join(tmp, "beep.wav")
        _tone_wav(wav, 1.5, hz=800)
        scan = transcriber.tone_scan(wav)
        check("a beep is measured", bool(scan), True)
        check("its frequency is found", abs(scan["hz"] - 800) < 40, True)
        check("and it is called a tone", "steady tone" in transcriber.tone_reason(scan), True)


def test_a_morse_identifier_is_recognised_and_named_as_one():
    with tempfile.TemporaryDirectory() as tmp:
        wav = os.path.join(tmp, "cw.wav")
        _tone_wav(wav, 4.0, hz=700, keyed=0.06)      # ~20 wpm dits
        scan = transcriber.tone_scan(wav)
        reason = transcriber.tone_reason(scan)
        check("keyed carrier is a tone", reason != "", True)
        check("and the keying tells it from a held tone", "Morse" in reason, True)


def test_a_long_clip_is_never_judged_a_tone():
    """The guard that protects real transmissions.

    A voice over a steady hum or carrier measures as tonal — this test asks whether one
    frequency dominates, not whether the clip is only that frequency. The repeater's own
    spoken identifier did exactly that on 2026-08-20 (tonal=1.00, agree=1.00) and would
    have been deleted. Duration is what separates them: a beep is short.
    """
    with tempfile.TemporaryDirectory() as tmp:
        short = os.path.join(tmp, "beep.wav")
        _tone_wav(short, 3.0, hz=1454)
        check("a short pure tone is a tone",
              transcriber.tone_reason(transcriber.tone_scan(short)) != "", True)
        long = os.path.join(tmp, "long.wav")
        _tone_wav(long, 13.0, hz=1454)      # same signal, transmission length
        check("the same signal at 13s is left alone",
              transcriber.tone_reason(transcriber.tone_scan(long)), "")


def test_a_beep_with_few_audible_frames_is_still_caught():
    """The quantisation that made this miss half the beeps it should have caught.

    A 2-3 second beep yields only 7-9 audible frames, so the tonal fraction can only take
    values like 5/7, 6/8, 7/8. The original 0.75 threshold sat mid-quantisation and
    identical beeps fell either side of it at random.
    """
    with tempfile.TemporaryDirectory() as tmp:
        for secs in (2.3, 2.56, 2.82, 4.6):
            w = os.path.join(tmp, f"b{secs}.wav")
            _tone_wav(w, secs, hz=1454, keyed=0.06)
            scan = transcriber.tone_scan(w)
            check(f"{secs}s keyed tone is caught",
                  transcriber.tone_reason(scan) != "", True)


def test_speech_is_not_mistaken_for_a_tone():
    with tempfile.TemporaryDirectory() as tmp:
        wav = os.path.join(tmp, "voice.wav")
        _speechlike_wav(wav, 3.0)
        check("a moving pitch is not a tone", transcriber.tone_reason(transcriber.tone_scan(wav)), "")


def test_an_unreadable_or_tiny_clip_is_no_opinion_not_a_drop():
    with tempfile.TemporaryDirectory() as tmp:
        missing = os.path.join(tmp, "gone.wav")
        check("a missing file says nothing", transcriber.tone_scan(missing), None)
        tiny = os.path.join(tmp, "tiny.wav")
        write_wav(tiny, 0.05, amplitude=9000)
        check("too short to judge says nothing", transcriber.tone_scan(tiny), None)
        silent = os.path.join(tmp, "silent.wav")
        write_wav(silent, 2.0)
        check("silence says nothing", transcriber.tone_scan(silent), None)
        check("and no opinion is never a reason to drop", transcriber.tone_reason(None), "")


def test_the_filter_defaults_to_observing_and_rejects_a_typo():
    ch = lambda d: transcriber.Channel(dict({"id": "rx1"}, **d))
    check("absent means observe", ch({}).tone_filter, "observe")
    check("drop is honoured", ch({"tone_filter": "drop"}).tone_filter, "drop")
    check("off is honoured", ch({"tone_filter": "off"}).tone_filter, "off")
    check("case does not matter", ch({"tone_filter": "DROP"}).tone_filter, "drop")
    # A typo must not silently start deleting transmissions.
    check("a typo falls back to observe", ch({"tone_filter": "dorp"}).tone_filter, "observe")


def test_a_standalone_tone_costs_the_log_neither_a_line_nor_a_second_of_audio():
    """Drop mode must beat the audio post, not just the transcription.

    The audio block deliberately runs even when the transcription is rejected, so a
    garbled human over is still audible. A courtesy tone is the case where that is
    wrong, and it is only wrong if the verdict is reached first.
    """
    with tempfile.TemporaryDirectory() as tmp:
        rc, texts = run_pipeline(
            tmp, "Beep.", channel={"tone_filter": "drop", "send_audio": True},
            audio=lambda p: _tone_wav(p, 3.0, hz=800))
        check("the channel finishes normally", rc, 0)
        check("nothing is written to the log", texts, [])
        check("and nothing at all is posted — no text row, no audio row",
              len(Handler.seen), 0)


def test_observing_a_tone_still_logs_and_still_sends_the_audio():
    """The default mode changes nothing. That is the whole point of it."""
    with tempfile.TemporaryDirectory() as tmp:
        rc, texts = run_pipeline(
            tmp, "K6DRK testing.", channel={"tone_filter": "observe", "send_audio": True},
            audio=lambda p: _tone_wav(p, 3.0, hz=800))
        check("the transcription still reaches the log", texts, ["K6DRK testing."])


def test_a_tone_in_front_of_speech_is_not_a_standalone_tone():
    """Dropping is for transmissions that are ONLY a tone.

    A beep with somebody talking after it leaves most of the clip broadband, so the
    frequency agreement never reaches the threshold and the whole transmission is kept —
    audio and text together. Trimming the beep off the front would mean editing a
    recording of what came over the air, which this deliberately does not do.
    """
    with tempfile.TemporaryDirectory() as tmp:
        def beep_then_voice(path):
            _tone_wav(path, 0.4, hz=800)
            head = wave.open(path); frames = head.readframes(head.getnframes()); head.close()
            voice = os.path.join(os.path.dirname(path), "_v.wav")
            _speechlike_wav(voice, 2.6)
            v = wave.open(voice); vf = v.readframes(v.getnframes()); v.close()
            with wave.open(path, "w") as w:
                w.setnchannels(1); w.setsampwidth(2); w.setframerate(16000)
                w.writeframes(frames + vf)
            os.unlink(voice)
        clip = os.path.join(tmp, "mixed.wav")
        beep_then_voice(clip)
        check("a tone with speech behind it is not judged a tone",
              transcriber.tone_reason(transcriber.tone_scan(clip)), "")


if __name__ == "__main__":
    for fn in [
        test_worth_logging, test_clean_strips_sound_effects,
        test_the_last_sentence_is_closed, test_clip_seconds,
        test_non_speech_tokens_are_suppressed_at_the_decoder_where_the_build_allows,
        test_a_looping_transcription_does_not_reach_the_log,
        test_repetition_on_the_air_is_not_a_hallucination,
        test_a_loop_on_the_end_is_trimmed_rather_than_thrown_away,
        test_a_transcription_that_is_mostly_loop_is_rejected_whole,
        test_a_callsign_is_recognized_by_its_shape_not_by_how_many_words_it_took,
        test_a_short_roster_word_does_not_eat_ordinary_english,
        test_ordinary_speech_is_not_turned_into_a_callsign,
        test_the_event_vocabulary_answers_what_a_guess_only_asks,
        test_a_partial_match_is_left_exactly_as_it_was_heard,
        test_an_event_with_no_vocabulary_is_the_normal_case,
        test_a_stated_term_is_matched_like_a_tactical_call,
        test_a_correction_is_exact_and_never_fuzzy,
        test_a_correction_is_applied_before_the_vocabulary_and_not_after,
        test_the_initial_prompt_is_off_unless_a_channel_asks_for_it,
        test_the_prompt_flags_are_only_passed_to_a_build_that_has_them,
        test_the_bench_compares_configurations_and_not_only_models,
        test_a_prompt_comparison_says_when_there_is_nothing_to_prime_with,
        test_a_line_off_static_that_names_the_roster_is_the_finding,
        test_the_static_report_calls_out_what_was_invented_and_clears_what_was_not,
        test_the_traffic_summary_separates_what_correction_can_fix,
        test_the_static_pass_takes_noise_the_channel_would_never_record,
        test_open_carrier_tells_a_stuck_transmitter_from_a_busy_channel,
        test_only_a_capped_clip_answers_the_open_carrier_question,
        test_a_carrier_that_does_not_drop_is_cut_at_exactly_the_cap,
        test_a_carrier_judged_stuck_stops_being_recorded,
        test_transcription_runs_off_the_capture_thread,
        test_backlog_is_bounded_by_size_not_just_count,
        test_start_capture_builds_a_command_and_keeps_the_two_directories_straight,
        test_clips_go_to_ram_but_never_at_the_cost_of_listening,
        test_calibration_finds_the_lowest_level_that_gates,
        test_calibration_is_not_fooled_by_a_transmission,
        test_a_wedged_tuner_is_not_mistaken_for_a_quiet_frequency,
        test_calibration_refuses_to_measure_a_dead_input,
        test_calibration_gives_up_rather_than_guessing,
        test_a_lull_is_not_mistaken_for_a_quiet_channel,
        test_the_length_guard_is_recorded_rather_than_silent,
        test_a_long_clip_nobody_spoke_in_is_recognised,
        test_a_squelch_crash_does_not_hide_the_tone_behind_it,
        test_a_capture_knows_how_many_transmissions_are_in_it,
        test_a_clip_nobody_said_anything_in_is_recognised,
        test_the_gain_is_the_knee_where_the_receiver_starts_hearing_the_band,
        test_a_gain_sweep_with_no_knee_is_used_but_never_called_measured,
        test_a_carrier_left_open_is_not_a_transmission,
        test_the_dead_carrier_test_is_a_ratio_so_gain_cannot_move_it,
        test_a_clip_too_short_to_have_an_envelope_is_not_judged,
        test_a_transmission_during_the_sweep_is_detected_rather_than_measured,
        test_the_gain_sweep_stays_below_what_this_tuner_stays_linear_at,
        test_a_site_quiet_enough_to_need_the_top_of_the_sweep_still_calibrates,
        test_gain_and_squelch_are_cached_together_or_not_at_all,
        test_a_measurement_is_never_re_measured_behind_your_back,
        test_a_channel_that_has_never_been_calibrated_runs_on_the_defaults,
        test_calibrating_says_it_has_started_before_it_says_what_it_found,
        test_a_failed_calibration_says_why_and_caches_nothing,
        test_a_pause_in_speech_does_not_end_the_transmission,
        test_a_long_gap_separates_two_overs,
        test_a_single_over_is_one_clip,
        test_outbox_order_and_retry, test_outbox_drops_corrupt_entries,
        test_posting, test_unreachable_server_is_retried,
        test_an_entry_with_no_clip_is_still_plain_json,
        test_a_clip_is_sent_alongside_the_text,
        test_a_missing_clip_does_not_hold_back_the_entry,
        test_the_recording_is_sent_before_anything_is_transcribed,
        test_a_failed_recording_does_not_cost_the_entry,
        test_the_words_name_the_entry_the_recording_made,
        test_the_outbox_carries_the_clip_and_cleans_it_up,
        test_a_refused_entry_takes_its_clip_with_it,
        test_a_clip_survives_while_its_entry_is_still_waiting,
        test_evicting_an_old_entry_deletes_its_clip_too,
        test_an_outbox_written_by_an_older_build_still_flushes,
        test_clips_are_levelled_to_one_volume,
        test_silence_is_never_amplified,
        test_the_boost_is_bounded,
        test_a_missing_encoder_costs_the_audio_and_nothing_else,
        test_a_failed_encode_leaves_nothing_behind,
        test_an_oversized_clip_is_refused_locally,
        test_a_clip_is_queued_once_not_once_per_loop,
        test_pipeline_logs_speech, test_pipeline_discards_hallucination,
        test_pipeline_corrects_a_callsign_but_only_after_the_guards,
        test_pipeline_discards_short_clip,
        test_pipeline_transcribes_a_capped_clip_rather_than_binning_it,
        test_the_audio_is_thrown_away_unless_somebody_asked_to_keep_it,
        test_a_window_that_has_passed_keeps_nothing,
        test_the_manifest_says_what_each_clip_became,
        test_the_manifest_says_why_a_clip_was_not_logged,
        test_a_clip_too_short_to_transcribe_is_still_worth_keeping,
        test_a_clip_that_cannot_be_kept_does_not_stop_the_channel,
        test_the_byte_cap_stops_recording_but_not_receiving,
        test_kept_clips_sort_in_the_order_they_were_heard,
        test_an_idle_frequency_is_not_fatal,
        test_a_broken_whisper_is_fatal_not_silent,
        test_disabled_channel_does_nothing, test_unknown_channel_is_fatal,
        test_a_disabled_channel_can_still_be_calibrated,
        test_a_disabled_channel_still_does_nothing_when_asked_to_listen,
        test_a_courtesy_tone_is_recognised_as_a_tone,
        test_a_morse_identifier_is_recognised_and_named_as_one,
        test_speech_is_not_mistaken_for_a_tone,
        test_a_long_clip_is_never_judged_a_tone,
        test_a_beep_with_few_audible_frames_is_still_caught,
        test_an_unreadable_or_tiny_clip_is_no_opinion_not_a_drop,
        test_the_filter_defaults_to_observing_and_rejects_a_typo,
        test_a_standalone_tone_costs_the_log_neither_a_line_nor_a_second_of_audio,
        test_observing_a_tone_still_logs_and_still_sends_the_audio,
        test_a_tone_in_front_of_speech_is_not_a_standalone_tone,
    ]:
        fn()
    if FAILURES:
        print("\nFAILED:")
        for f in FAILURES:
            print("  " + f)
        sys.exit(1)
    print("\nall passed")
