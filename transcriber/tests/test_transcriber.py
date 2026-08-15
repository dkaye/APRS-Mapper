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
import struct
import sys
import tempfile
import threading
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

    def slow(channel, path, whisper, model, outbox):
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


def test_a_gain_sweep_with_no_knee_is_not_an_answer():
    """A floor that never follows the gain is a receiver hearing nothing but itself —
    a disconnected antenna, or a connector that has worked loose. There is no knee to
    find, and the honest answer is to say so rather than to return the top of the sweep
    and call it measured."""
    print("gain — no knee")
    gain, why = transcriber.choose_gain(band(thermal=-200))
    check("returns no gain", gain, None)
    check("and blames the antenna", "antenna" in why, True)

    # And a receiver that hands back nothing at all is the wedged tuner again, not a
    # wonderfully quiet site. It must never be measured against.
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
    the sweep measures would object to 40 dB. So the list simply does not offer it."""
    print("gain — the cap")
    check("stays well below the tuner's 49.6 dB maximum",
          max(transcriber.GAIN_CANDIDATES) <= 36, True)
    check("and never offers the 40 that overloaded the front end",
          [g for g in transcriber.GAIN_CANDIDATES if g >= 40], [])


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
              ["frequency", "gain", "squelch", "when"])

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
              {"state": "done", "gain": 16.6, "squelch": 30})


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

    def stub(channel, path, whisper, model, outbox):
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


# ── clip length ──────────────────────────────────────────────────────────────

def write_wav(path, seconds, rate=16000):
    with wave.open(path, "w") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(struct.pack("<h", 0) * int(rate * seconds))


def test_clip_seconds():
    print("clip_seconds")
    with tempfile.TemporaryDirectory() as d:
        p = os.path.join(d, "a.wav")
        write_wav(p, 2.0)
        check("measures duration", round(transcriber.clip_seconds(p), 1), 2.0)
        check("unreadable file is 0", transcriber.clip_seconds(os.path.join(d, "nope.wav")), 0.0)


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
        def flaky(text):
            sent.append(text)
            return transcriber.POST_OK if text != "second" else transcriber.POST_RETRY

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
        ok = lambda t: (sent.append(t), transcriber.POST_OK)[1]
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
        check("flushes past it", box.flush(lambda t: (sent.append(t), transcriber.POST_OK)[1]), True)
        check("kept the good one", sent, ["good"])
        check("removed the bad one", box.pending(), [])


# ── posting ──────────────────────────────────────────────────────────────────

class Handler(BaseHTTPRequestHandler):
    status = 200
    body = b'{"ok":true}'
    seen = []

    def do_POST(self):
        n = int(self.headers.get("Content-Length", 0))
        entry = json.loads(self.rfile.read(n) or b"{}")
        entry["_ua"] = self.headers.get("User-Agent", "")
        Handler.seen.append(entry)
        self.send_response(Handler.status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(Handler.body)))
        self.end_headers()
        self.wfile.write(Handler.body)

    def log_message(self, *_a):
        pass


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


# ── the whole pipeline, no radio ─────────────────────────────────────────────

def stub_whisper(directory, says):
    """A stand-in for whisper.cpp that prints whatever the test wants it to hear."""
    path = os.path.join(directory, "whisper-stub")
    with open(path, "w") as fh:
        fh.write("#!/bin/sh\ncat <<'EOF'\n" + says + "\nEOF\n")
    os.chmod(path, 0o755)
    return path


def run_pipeline(tmp, says, clip_seconds=3.0, vocabulary=None):
    """One --spool-only pass over a single clip. Returns the entries the server got."""
    spool = os.path.join(tmp, "spool")
    os.makedirs(spool, exist_ok=True)
    clip = os.path.join(spool, "clip_001.wav")
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
    with open(config, "w") as fh:
        json.dump({"channels": [{
            "id": "rx1-146520", "label": "146.520", "token": "tok-rx",
            "frequency": "146520000", "serial": "00000001",
            "server": f"http://127.0.0.1:{port}",
        }], "vocabulary": vocabulary or {}}, fh)

    rc = transcriber.main([
        "--channel", "rx1-146520", "--config", config, "--spool", spool,
        "--whisper", stub_whisper(tmp, says), "--models", models,
        "--spool-only", "--once",
    ])
    srv.shutdown()
    return rc, [e["text"] for e in Handler.seen]


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
        check("one entry", sent, ["aid three we have a rider down"])


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
              sent, ["K6DRK testing on West Marin"])

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
              sent, ["aid three we have a rider down"])


def test_pipeline_discards_short_clip():
    print("pipeline — key-up")
    with tempfile.TemporaryDirectory() as tmp:
        # Under MIN_CLIP_SECONDS, so whisper is never even asked.
        rc, sent = run_pipeline(tmp, "aid three we have a rider down", clip_seconds=0.5)
        check("exit 0", rc, 0)
        check("nothing logged", sent, [])


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


if __name__ == "__main__":
    for fn in [
        test_worth_logging, test_clean_strips_sound_effects, test_clip_seconds,
        test_non_speech_tokens_are_suppressed_at_the_decoder_where_the_build_allows,
        test_a_looping_transcription_does_not_reach_the_log,
        test_repetition_on_the_air_is_not_a_hallucination,
        test_a_loop_on_the_end_is_trimmed_rather_than_thrown_away,
        test_a_transcription_that_is_mostly_loop_is_rejected_whole,
        test_a_callsign_is_recognized_by_its_shape_not_by_how_many_words_it_took,
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
        test_the_gain_is_the_knee_where_the_receiver_starts_hearing_the_band,
        test_a_gain_sweep_with_no_knee_is_not_an_answer,
        test_a_transmission_during_the_sweep_is_detected_rather_than_measured,
        test_the_gain_sweep_stays_below_what_this_tuner_stays_linear_at,
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
        test_a_clip_is_queued_once_not_once_per_loop,
        test_pipeline_logs_speech, test_pipeline_discards_hallucination,
        test_pipeline_corrects_a_callsign_but_only_after_the_guards,
        test_pipeline_discards_short_clip,
        test_pipeline_transcribes_a_capped_clip_rather_than_binning_it,
        test_an_idle_frequency_is_not_fatal,
        test_a_broken_whisper_is_fatal_not_silent,
        test_disabled_channel_does_nothing, test_unknown_channel_is_fatal,
    ]:
        fn()
    if FAILURES:
        print("\nFAILED:")
        for f in FAILURES:
            print("  " + f)
        sys.exit(1)
    print("\nall passed")
