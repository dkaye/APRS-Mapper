/// The phrases a spoken message is broken into, with a pause between each.
///
/// Shares its rules with `splitSentences` in map/utils.js (tested in
/// map/tests/js/utils.test.js), `sentences` in ios/WatchApp/Sources/Announcer.swift,
/// and `splitSentences` in the Wear Announcer.kt. All four must break a message in
/// the same places, so these cases are deliberately the same cases.
import 'package:flutter_test/flutter_test.dart';
import 'package:aprs_map/speaker.dart';

void main() {
  group('splitSentences', () {
    test('two sentences split, each keeping its period', () {
      expect(splitSentences('Meet at the staging area. Bring the HT.'),
          ['Meet at the staging area.', 'Bring the HT.']);
    });

    test('a question mark ends a sentence', () {
      expect(splitSentences('Are you mobile? Go ahead.'),
          ['Are you mobile?', 'Go ahead.']);
    });

    test('so does an exclamation', () {
      expect(splitSentences('Break! Aid three needs a hand.'),
          ['Break!', 'Aid three needs a hand.']);
    });

    test('three sentences give three phrases', () {
      expect(splitSentences('Three. Sentences. Here.'),
          ['Three.', 'Sentences.', 'Here.']);
    });

    // The one that matters most on this channel: a frequency must never be read as
    // two phrases with a quarter second of silence in the middle of the number.
    test('a frequency is never split down the middle', () {
      expect(splitSentences('Monitoring 146.520 simplex tonight.'),
          ['Monitoring 146.520 simplex tonight.']);
    });

    test('initials stay with the name they belong to', () {
      expect(splitSentences('J. Kaye is net control.'),
          ['J. Kaye is net control.']);
    });

    test('and a run of them does too', () {
      expect(splitSentences('A. B. C. done.'), ['A. B. C. done.']);
    });

    test('an ellipsis is one break, not three', () {
      expect(splitSentences('Standing by... Nothing heard.'),
          ['Standing by...', 'Nothing heard.']);
    });

    test('a closing quote stays with the sentence it closes', () {
      expect(splitSentences('He said "go ahead." Then he left.'),
          ['He said "go ahead."', 'Then he left.']);
    });

    test('one sentence with no terminator is still one phrase', () {
      expect(splitSentences('One sentence only'), ['One sentence only']);
    });

    test('empty text gives nothing to say', () {
      expect(splitSentences(''), isEmpty);
    });

    test('whitespace only gives nothing to say', () {
      expect(splitSentences('   '), isEmpty);
    });

    // Documented cost, asserted so it is a decision rather than a surprise.
    test('an abbreviation does split, which is the accepted trade', () {
      expect(splitSentences('Mt. Tam repeater is down.'),
          ['Mt.', 'Tam repeater is down.']);
    });
  });
}
