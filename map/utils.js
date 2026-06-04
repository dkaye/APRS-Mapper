function esc(s) {
	return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function relativeTime(ts) {
	const s = Math.floor(Date.now() / 1000) - ts;
	if (s < 10)  return 'just now';
	if (s < 60)  return s + 's ago';
	const m = Math.floor(s / 60), r = s % 60;
	if (m < 60)  return m + 'm ' + r + 's ago';
	const h = Math.floor(m / 60);
	return h + 'h ' + (m % 60) + 'm ago';
}

function haversineDistance(lat1, lng1, lat2, lng2) {
	const R = 3958.8, r = Math.PI / 180;
	const dLat = (lat2 - lat1) * r, dLng = (lng2 - lng1) * r;
	const a = Math.sin(dLat/2)**2 + Math.cos(lat1*r) * Math.cos(lat2*r) * Math.sin(dLng/2)**2;
	return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function bearingTo(lat1, lng1, lat2, lng2) {
	const r = Math.PI / 180, dLng = (lng2 - lng1) * r;
	const y = Math.sin(dLng) * Math.cos(lat2 * r);
	const x = Math.cos(lat1*r) * Math.sin(lat2*r) - Math.sin(lat1*r) * Math.cos(lat2*r) * Math.cos(dLng);
	return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

function compassDir(deg) {
	return ['N','NNE','NE','ENE','E','ESE','SE','SSE',
	        'S','SSW','SW','WSW','W','WNW','NW','NNW'][Math.round(deg / 22.5) % 16];
}

const Q_LABELS = {
	qAR:'received by iGate', qAC:'bidirectional iGate', qAI:'server generated',
	qAX:'rejected by iGate', qAZ:'server generated',   qAS:'third-party iGate',
	qAo:'received without RF', qAO:'received without RF',
};

function formatAprsPath(path) {
	if (!path) return '<div class="aprs-path-empty">No path data for this beacon.</div>';
	return '<div class="aprs-path-hops">' + path.split(',').map(hop => {
		const clean = hop.replace('*','');
		let desc = '';
		if (Q_LABELS[clean])      desc = `<span class="aprs-path-desc">${esc(Q_LABELS[clean])}</span>`;
		else if (hop.includes('*')) desc = `<span class="aprs-path-digi">digipeated</span>`;
		return `<div class="aprs-path-hop"><code>${esc(hop)}</code>${desc ? ' '+desc : ''}</div>`;
	}).join('') + '</div>';
}

if (typeof module !== 'undefined') module.exports = {
	esc, relativeTime, haversineDistance, bearingTo, compassDir, Q_LABELS, formatAprsPath
};
