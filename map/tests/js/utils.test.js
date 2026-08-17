const path = require('path');
const { esc, relativeTime, haversineDistance, bearingTo, compassDir, Q_LABELS, formatAprsPath,
        splitSentences } =
    require(path.resolve(__dirname, '../../utils.js'));

// ── esc ───────────────────────────────────────────────────────────────────────

describe('esc', () => {
    test('ampersand', () => expect(esc('a&b')).toBe('a&amp;b'));
    test('less-than', () => expect(esc('<tag>')).toBe('&lt;tag&gt;'));
    test('greater-than', () => expect(esc('x>y')).toBe('x&gt;y'));
    test('double-quote', () => expect(esc('"quoted"')).toBe('&quot;quoted&quot;'));
    test('single-quote', () => expect(esc("it's")).toBe('it&#39;s'));
    test('all five at once', () => expect(esc('<a href="x&y">it\'s</a>')).toBe('&lt;a href=&quot;x&amp;y&quot;&gt;it&#39;s&lt;/a&gt;'));
    test('plain string unchanged', () => expect(esc('hello')).toBe('hello'));
    test('null coerced to empty string', () => expect(esc(null)).toBe(''));
    test('undefined coerced to empty string', () => expect(esc(undefined)).toBe(''));
});

// ── relativeTime ──────────────────────────────────────────────────────────────

describe('relativeTime', () => {
    const NOW_S = 1717500000;

    beforeEach(() => {
        jest.useFakeTimers();
        jest.setSystemTime(NOW_S * 1000);
    });

    afterEach(() => {
        jest.useRealTimers();
    });

    test('less than 10s → just now', () => expect(relativeTime(NOW_S - 5)).toBe('just now'));
    test('exactly 9s → just now', ()  => expect(relativeTime(NOW_S - 9)).toBe('just now'));
    test('exactly 10s → 10s ago', ()  => expect(relativeTime(NOW_S - 10)).toBe('10s ago'));
    test('59s → 59s ago', ()          => expect(relativeTime(NOW_S - 59)).toBe('59s ago'));
    test('exactly 60s → 1m 0s ago', () => expect(relativeTime(NOW_S - 60)).toBe('1m 0s ago'));
    test('65s → 1m 5s ago', ()        => expect(relativeTime(NOW_S - 65)).toBe('1m 5s ago'));
    test('3599s → 59m 59s ago', ()    => expect(relativeTime(NOW_S - 3599)).toBe('59m 59s ago'));
    test('3600s → 1h 0m ago', ()      => expect(relativeTime(NOW_S - 3600)).toBe('1h 0m ago'));
    test('3661s → 1h 1m ago', ()      => expect(relativeTime(NOW_S - 3661)).toBe('1h 1m ago'));
    test('7322s → 2h 2m ago', ()      => expect(relativeTime(NOW_S - 7322)).toBe('2h 2m ago'));
});

// ── haversineDistance ─────────────────────────────────────────────────────────

describe('haversineDistance', () => {
    test('same point = 0', () => expect(haversineDistance(37.0, -122.0, 37.0, -122.0)).toBe(0));

    test('SF to LA ≈ 338 mi', () => {
        // SFO (37.6213, -122.3790) to LAX (33.9425, -118.4081)
        const d = haversineDistance(37.6213, -122.379, 33.9425, -118.4081);
        expect(d).toBeGreaterThan(330);
        expect(d).toBeLessThan(345);
    });

    test('SF to NYC ≈ 2565 mi', () => {
        const d = haversineDistance(37.6213, -122.379, 40.6413, -73.7781);
        expect(d).toBeGreaterThan(2540);
        expect(d).toBeLessThan(2590);
    });

    test('equator same longitude = 0', () => {
        expect(haversineDistance(0, 0, 0, 0)).toBe(0);
    });

    test('north to south pole ≈ 12436 mi (half circumference)', () => {
        const d = haversineDistance(90, 0, -90, 0);
        expect(d).toBeGreaterThan(12430);
        expect(d).toBeLessThan(12445);
    });

    test('1 degree latitude north ≈ 69 mi', () => {
        const d = haversineDistance(37.0, -122.0, 38.0, -122.0);
        expect(d).toBeGreaterThan(68);
        expect(d).toBeLessThan(70);
    });
});

// ── bearingTo ─────────────────────────────────────────────────────────────────

describe('bearingTo', () => {
    test('due north = 0°', () => {
        const b = bearingTo(37.0, -122.0, 38.0, -122.0);
        expect(b).toBeCloseTo(0, 0);
    });

    test('due south ≈ 180°', () => {
        const b = bearingTo(38.0, -122.0, 37.0, -122.0);
        expect(b).toBeCloseTo(180, 0);
    });

    test('due east ≈ 90°', () => {
        const b = bearingTo(0, 0, 0, 1);
        expect(b).toBeCloseTo(90, 0);
    });

    test('due west ≈ 270°', () => {
        const b = bearingTo(0, 0, 0, -1);
        expect(b).toBeCloseTo(270, 0);
    });

    test('NE ≈ 45°', () => {
        // Due northeast is ~45° at the equator
        const b = bearingTo(0, 0, 1, 1);
        expect(b).toBeGreaterThan(40);
        expect(b).toBeLessThan(50);
    });

    test('result always in [0, 360)', () => {
        const b = bearingTo(37.0, -122.0, 36.9, -122.1);
        expect(b).toBeGreaterThanOrEqual(0);
        expect(b).toBeLessThan(360);
    });
});

// ── compassDir ────────────────────────────────────────────────────────────────

describe('compassDir', () => {
    const cases = [
        [0,     'N'],
        [22.5,  'NNE'],
        [45,    'NE'],
        [67.5,  'ENE'],
        [90,    'E'],
        [112.5, 'ESE'],
        [135,   'SE'],
        [157.5, 'SSE'],
        [180,   'S'],
        [202.5, 'SSW'],
        [225,   'SW'],
        [247.5, 'WSW'],
        [270,   'W'],
        [292.5, 'WNW'],
        [315,   'NW'],
        [337.5, 'NNW'],
    ];

    test.each(cases)('%s° → %s', (deg, expected) => {
        expect(compassDir(deg)).toBe(expected);
    });

    test('359° rounds to N', () => expect(compassDir(359)).toBe('N'));
    test('360° = N (wraps)', () => expect(compassDir(360)).toBe('N'));
});

// ── formatAprsPath ────────────────────────────────────────────────────────────

describe('formatAprsPath', () => {
    test('null path → empty div', () => {
        expect(formatAprsPath(null)).toContain('aprs-path-empty');
    });

    test('empty string → empty div', () => {
        expect(formatAprsPath('')).toContain('aprs-path-empty');
    });

    test('known Q-code qAR expands to description', () => {
        const html = formatAprsPath('WIDE2-1,qAR,K6DRK');
        expect(html).toContain('received by iGate');
    });

    test('known Q-code qAC expands', () => {
        const html = formatAprsPath('qAC');
        expect(html).toContain('bidirectional iGate');
    });

    test('star-marked hop labelled digipeated', () => {
        const html = formatAprsPath('WIDE2-1*,WIDE2-2');
        expect(html).toContain('digipeated');
    });

    test('unknown hop passes through as code', () => {
        const html = formatAprsPath('MYCALL-9');
        expect(html).toContain('<code>MYCALL-9</code>');
    });

    test('all hops wrapped in aprs-path-hop divs', () => {
        const html = formatAprsPath('A,B,C');
        // match the specific div class (not the outer "aprs-path-hops")
        const matches = html.match(/class="aprs-path-hop"/g) || [];
        expect(matches.length).toBe(3);
    });

    test('hops are HTML-escaped', () => {
        const html = formatAprsPath('<script>');
        expect(html).toContain('&lt;script&gt;');
        expect(html).not.toContain('<script>');
    });

    test('outer wrapper is aprs-path-hops', () => {
        const html = formatAprsPath('WIDE2-1');
        expect(html).toContain('aprs-path-hops');
    });

    test('Q_LABELS object has expected keys', () => {
        expect(Q_LABELS).toHaveProperty('qAR');
        expect(Q_LABELS).toHaveProperty('qAC');
        expect(Q_LABELS).toHaveProperty('qAI');
        expect(Q_LABELS['qAR']).toBe('received by iGate');
    });
});

// ── splitSentences ────────────────────────────────────────────────────────────
//
// The phrases a spoken message is broken into, with a pause between each. Shares its
// rules with splitSentences in app/lib/speaker.dart and both watch Announcers.

describe('splitSentences', () => {
    test('two sentences split, each keeping its period', () =>
        expect(splitSentences('Meet at the staging area. Bring the HT.'))
            .toEqual(['Meet at the staging area.', 'Bring the HT.']));

    test('a question mark ends a sentence', () =>
        expect(splitSentences('Are you mobile? Go ahead.'))
            .toEqual(['Are you mobile?', 'Go ahead.']));

    test('so does an exclamation', () =>
        expect(splitSentences('Break! Aid three needs a hand.'))
            .toEqual(['Break!', 'Aid three needs a hand.']));

    test('three sentences give three phrases', () =>
        expect(splitSentences('Three. Sentences. Here.'))
            .toEqual(['Three.', 'Sentences.', 'Here.']));

    // The one that matters most on this channel: a frequency must never be read as
    // two phrases with a quarter second of silence in the middle of the number.
    test('a frequency is never split down the middle', () =>
        expect(splitSentences('Monitoring 146.520 simplex tonight.'))
            .toEqual(['Monitoring 146.520 simplex tonight.']));

    test('initials stay with the name they belong to', () =>
        expect(splitSentences('J. Kaye is net control.'))
            .toEqual(['J. Kaye is net control.']));

    test('and a run of them does too', () =>
        expect(splitSentences('A. B. C. done.')).toEqual(['A. B. C. done.']));

    test('an ellipsis is one break, not three', () =>
        expect(splitSentences('Standing by... Nothing heard.'))
            .toEqual(['Standing by...', 'Nothing heard.']));

    test('a closing quote stays with the sentence it closes', () =>
        expect(splitSentences('He said "go ahead." Then he left.'))
            .toEqual(['He said "go ahead."', 'Then he left.']));

    test('one sentence with no terminator is still one phrase', () =>
        expect(splitSentences('One sentence only')).toEqual(['One sentence only']));

    test('empty text gives nothing to say', () => expect(splitSentences('')).toEqual([]));
    test('whitespace only gives nothing to say', () =>
        expect(splitSentences('   ')).toEqual([]));
    test('null is not a crash', () => expect(splitSentences(null)).toEqual([]));
    test('undefined is not a crash', () => expect(splitSentences(undefined)).toEqual([]));

    // Documented cost, asserted so it is a decision rather than a surprise.
    test('an abbreviation does split, which is the accepted trade', () =>
        expect(splitSentences('Mt. Tam repeater is down.'))
            .toEqual(['Mt.', 'Tam repeater is down.']));
});
