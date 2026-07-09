<?php
/**
 * APRS Tracker Map — shared YAML config parser
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * @author    Doug Kaye
 * @copyright 2026 Doug Kaye. All Rights Reserved.
 *
 * Minimal YAML parser for config.yaml.
 * Handles the specific subset used by this project — no external PHP extension required.
 *
 * Supported structure:
 *   # comment lines and blank lines (skipped)
 *   section:              <- top-level section header (column 0)
 *     key: value          <- key/value pair (map section, or scalar map)
 *     - key: value        <- list-item start
 *       key: value        <- list-item continuation
 *
 * Value rules:
 *   "quoted" or 'quoted'  -> string with quotes stripped
 *   123 / 3.14 / -1.5    -> cast to int or float
 *   everything else       -> returned as a plain string
 *
 * Returns an array with keys: map, trackers, backgrounds, courses, aidstations, igates, mobile.
 * Missing sections default to null (map) or [] (lists/maps).
 */
function parseConfigYaml($filename) {
	$result = ['event' => '', 'legend' => '', 'tracker_style' => [], 'section_visibility' => [], 'map' => null, 'trackers' => [], 'backgrounds' => [], 'background_url' => '', 'courses' => [], 'aidstations' => [], 'igates' => [], 'mobile' => [], 'offline_map' => []];
	if (!file_exists($filename)) return $result;

	$lines   = file($filename, FILE_IGNORE_NEW_LINES);
	$section = null;
	$item    = null;

	foreach ($lines as $line) {
		// Skip blank lines and comment lines
		$trimmed = trim($line);
		if ($trimmed === '' || $trimmed[0] === '#') continue;

		// Top-level line: "section:" (header with no value) or "key: value" (scalar)
		if ($line[0] !== ' ' && $line[0] !== "\t" && preg_match('/^(\w+)\s*:\s*(.*)$/', $line, $m)) {
			$value = trim($m[2]);
			if ($value === '') {
				// Section header
				if ($item !== null && $section !== null && $section !== 'map' && $section !== 'tracker_style' && $section !== 'section_visibility' && $section !== 'mobile' && $section !== 'offline_map') {
					$result[$section][] = $item;
					$item = null;
				}
				$section = $m[1];
				if ($section === 'map') $result['map'] = [];
				elseif ($section === 'tracker_style') $result['tracker_style'] = [];
				elseif ($section === 'section_visibility') $result['section_visibility'] = [];
				elseif ($section === 'mobile') $result['mobile'] = [];
				elseif ($section === 'offline_map') $result['offline_map'] = [];
			} else {
				// Top-level scalar (e.g. event: My Race 2026)
				$result[$m[1]] = yamlScalar($value);
			}
			continue;
		}

		if ($section === null) continue;

		// List-item start: "  - key: value" or "- key: value" (column 0)
		if (preg_match('/^\s*-\s+(\w+)\s*:\s*(.*)$/', $line, $m)) {
			if ($item !== null) $result[$section][] = $item;
			$item = [trim($m[1]) => yamlScalar(trim($m[2]))];
			continue;
		}

		// Key/value continuation: "    key: value"  (map section or list-item field)
		if (preg_match('/^\s+(\w+)\s*:\s*(.*)$/', $line, $m)) {
			$k = trim($m[1]);
			$v = yamlScalar(trim($m[2]));
			if ($section === 'map' || $section === 'tracker_style' || $section === 'section_visibility' || $section === 'mobile' || $section === 'offline_map') {
				$result[$section][$k] = $v;
			} elseif ($item !== null) {
				$item[$k] = $v;
			}
			continue;
		}
	}

	// Flush last open list item
	if ($item !== null && $section !== null && $section !== 'map' && $section !== 'tracker_style' && $section !== 'section_visibility' && $section !== 'mobile' && $section !== 'offline_map') {
		$result[$section][] = $item;
	}

	return $result;
}

/**
 * Convert a raw YAML scalar string to a PHP value.
 */
function yamlScalar($str) {
	if ($str === '') return '';
	if (strlen($str) >= 2) {
		$q = $str[0];
		if ($q === '"' && $str[-1] === $q) {
			$inner = substr($str, 1, -1);
			return str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $inner);
		}
		if ($q === "'" && $str[-1] === $q) {
			return substr($str, 1, -1);
		}
	}
	if ($str === 'true')  return true;
	if ($str === 'false') return false;
	if (is_numeric($str)) return $str + 0;
	return $str;
}
