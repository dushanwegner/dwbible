/**
 * DW Bible — the book search, in the browser.
 *
 * WHAT   Two things that must agree, so they live in one file:
 *          DwBibleSearch.norm()  — how a typed word is reduced before matching
 *          DwBibleSearch.parse() — splitting "<book> <chapter>[:<verse>]" apart
 *        …and the rail's Bible field, which uses them to NAME THE BOOKS what
 *        the reader is typing could mean, as they type.
 * WHY    "John 1:42" means something; "Zaphod 1:1" does not — and knowing
 *        WHICH book was understood is the other half of that. "2 thessa" is
 *        confidently typed and hard to be sure of; a row reading
 *        "2 Thessalonicenses · 2 Thessalonicher" ends the doubt without a
 *        round trip, and is already the link. The quiet check at the end of
 *        the field stays for the case where a citation is still being typed
 *        and no row can be offered yet. Never an error, because a half-typed
 *        word is not a mistake.
 * HOW    The vocabulary — every token that names a book in every language, how
 *        many verses each chapter has, and the name/slug/gloss each book is
 *        offered under — is ONE cached fetch of /bible-books.json, made the
 *        first time a reader opens the field and never again. A query never
 *        reaches the origin — the same shape dwsearch uses on dushanwegner.com.
 * INPUT  window.dwbibleSearchCfg = { pattern } (localized from PHP — it is
 *        DwBible_Reference::CITATION_PATTERN, so the grammar has one definition
 *        for the server, the index filter and this field), and the field's own
 *        data-dwbible-vocab, which carries a STAMPED URL: a payload that grows
 *        a new field must arrive at a new address, or browsers holding the old
 *        one keep answering with it for a day. Also data-dwbible-base (the
 *        index URL a suggestion's href is built on) and data-dwbible-gloss
 *        (which language's names sit beside the Latin ones).
 * USED BY the Bible index's inline filter, and the rail's Bible search panel
 *        (dwbible includes/class-dwbible-menu-search.php).
 */
(function (w, d) {
	"use strict";

	var cfg = w.dwbibleSearchCfg || {};
	var CITATION = new RegExp(cfg.pattern || '^(.*?)[\\s.]*(\\d+)\\s*(?:([:,.])\\s*(\\d+)?(?:\\s*[-–—]\\s*(?:(\\d+)(?:\\s*(?!\\.)\\3\\s*(\\d+))?)?)?)?\\s*$');

	/**
	 * Reduce a word to what matching sees: lowercase, accents folded, the
	 * ligatures Latin actually uses spelled out, everything else dropped.
	 * Mirrors DwBible_Plugin::search_normalize() on the server.
	 */
	function norm(s) {
		return (s || '').toLowerCase()
			.normalize('NFD').replace(/[̀-ͯ]/g, '')
			.replace(/æ/g, 'ae').replace(/œ/g, 'oe').replace(/ß/g, 'ss')
			.replace(/[^a-z0-9]/g, '');
	}

	/**
	 * Split a typed query into its book half and its citation half.
	 * → { q: <normalized book query>, ref: 'ch[:v[-[ch:]v]]' or '',
	 *     ch, v, vTo: the numbers, or null where nothing was typed,
	 *     chTo: the chapter a range ENDS in, null when it stays in one }
	 * The separator does not matter — ":" and "," are the same citation, and
	 * "-" opens a range — because that is what the shared grammar says.
	 * A range END is a CHAPTER only when a verse of its own follows it
	 * ("18:1-19:42"); a lone number is a verse of the chapter already named
	 * ("24:13-35"). One rule, written once, in the pattern PHP hands over.
	 */
	function parse(raw) {
		var s = (raw || '').trim();
		var m = CITATION.exec(s);
		var none = { q: norm(s), ref: '', ch: null, v: null, vTo: null, chTo: null };
		if (m && norm(m[1])) {
			// A range-END with no range-START ("John 3:-5") is malformed, not
			// a whole-chapter citation: mirrors the same guard in PHP's
			// parse_query() (quality loop tick 218) so the suggestion row
			// never silently drops the "-5" and offers the whole chapter.
			if (m[5] && !m[4]) {
				return none;
			}
			var ref = m[2];
			if (m[4]) { ref += ':' + m[4] + (m[5] ? '-' + (m[6] ? m[5] + ':' + m[6] : m[5]) : ''); }
			return {
				q: norm(m[1]),
				ref: ref,
				ch: m[2] ? parseInt(m[2], 10) : null,
				v: m[4] ? parseInt(m[4], 10) : null,
				// With group 6 present, group 5 was the end CHAPTER and 6 the
				// end verse; without it, group 5 was the end verse itself.
				vTo: m[5] ? parseInt(m[6] || m[5], 10) : null,
				chTo: m[6] ? parseInt(m[5], 10) : null
			};
		}
		return none;
	}

	/** Does this normalized query PREFIX any of these space-separated tokens? */
	function hits(tokens, q) {
		if (!q) { return false; }
		for (var i = 0; i < tokens.length; i++) {
			if (tokens[i] && tokens[i].lastIndexOf(q, 0) === 0) { return true; }
		}
		return false;
	}

	/**
	 * Could THIS book hold that chapter and verse? `verses` is the book's verse
	 * count per chapter; a book we have no lengths for is trusted.
	 *
	 * A number still being typed is not a wrong number: only what is already
	 * there is judged, and a range's END is never held against its start (on the
	 * way to "5:41-45" the reader passes through "5:41-4", which is not an
	 * error, only unfinished).
	 */
	function canHold(verses, p) {
		if (p.ch === null) { return true; }
		if (!verses || !verses.length) { return true; }
		if (p.ch < 1 || p.ch > verses.length) { return false; }
		var n = verses[p.ch - 1];
		if (p.v === null || !n) { return true; }
		if (p.v < 1 || p.v > n) { return false; }
		if (p.vTo === null) { return true; }
		// A range that crosses a boundary is judged against the chapter it
		// ENDS in — "Jn 18:1-19:42" asks nothing of chapter 18's length past
		// verse 1 — so a book without that chapter is ruled out, and one with
		// it is kept even though 42 is more verses than chapter 18 may have.
		if (p.chTo !== null) {
			if (p.chTo > verses.length) { return false; }
			var last = verses[p.chTo - 1];
			return !last || p.vTo <= last;
		}
		return p.vTo <= n;
	}

	/**
	 * The largest part of a citation this book can actually be sent to.
	 *
	 * The companion question to canHold(): that one asks whether what was typed
	 * MEANS something, this one asks where it can lead. A verse that does not
	 * exist falls back to its chapter, a chapter that does not exist to the book
	 * — because a link that promises John 3:16666 and lands on a page without it
	 * is worse than one that quietly offers John 3. A half-finished range end
	 * ("24:13-3") is dropped rather than followed, so what a row points at is
	 * valid on every keystroke.
	 *
	 * → 'ch[:v[-v]]', or '' for "just the book".
	 */
	function fit(verses, p) {
		if (p.ch === null) { return ''; }
		// A book we have no lengths for is trusted — except for a cross-chapter
		// range, which is no page's address at all, so it is cut back to where
		// the passage starts (the PHP twin does the same).
		if (!verses || !verses.length) {
			if (p.chTo === null) { return p.ref; }
			return p.v === null ? String(p.ch) : p.ch + ':' + p.v;
		}
		if (p.ch < 1 || p.ch > verses.length) { return ''; }

		var n = verses[p.ch - 1];
		if (p.v === null || !n) { return String(p.ch); }
		if (p.v < 1 || p.v > n) { return String(p.ch); }
		// A page here renders ONE chapter, so a range that crosses a boundary
		// is followed as far as a page goes: the tail of the chapter it starts
		// in. The whole passage, assembled, is /bible-ref.json's answer. The
		// PHP twin is class-dwbible-router.php → fit_reference_to_book().
		if (p.chTo !== null) { return p.v < n ? p.ch + ':' + p.v + '-' + n : p.ch + ':' + p.v; }
		// "<=", not "<": a range ending where it starts is that verse, and the
		// address carries the dash only when it buys one.
		if (p.vTo === null || p.vTo > n || p.vTo <= p.v) { return p.ch + ':' + p.v; }
		return p.ch + ':' + p.v + '-' + p.vTo;
	}

	w.DwBibleSearch = { norm: norm, parse: parse, hits: hits, canHold: canHold, fit: fit };

	// ── The rail's Bible field ───────────────────────────────────────────────
	// Everything below is optional chrome: with JS off, or before the fetch
	// lands, the field is still a plain GET form that the server answers.

	// Per book, in canonical Bible order: what finds it, how long it is, and
	// what it is offered as. The payload's arrays are parallel by design.
	var vocab = null;   // [{ tokens, verses, name, slug, gloss: {ds: name} }]
	var loading = null; // the in-flight fetch, so N fields share one request

	function loadVocabulary(url) {
		if (vocab) { return Promise.resolve(vocab); }
		if (loading) { return loading; }
		loading = fetch(url, { credentials: 'omit' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				var tokens = (data && data.tokens) || [];
				var verses = (data && data.verses) || [];
				var names = (data && data.names) || [];
				var slugs = (data && data.slugs) || [];
				var gloss = (data && data.gloss) || {};
				var langs = Object.keys(gloss);
				vocab = tokens.map(function (line, i) {
					var v = verses[i] ? String(verses[i]).split(',') : [];
					var g = {};
					for (var k = 0; k < langs.length; k++) {
						g[langs[k]] = (gloss[langs[k]] || [])[i] || '';
					}
					return {
						tokens: String(line).split(' '),
						verses: v.map(function (n) { return parseInt(n, 10) || 0; }),
						// A book with no name and no slug is one the search knows
						// and the directory does not: findable, not offerable.
						name: String(names[i] || ''),
						slug: String(slugs[i] || ''),
						gloss: g
					};
				});
				return vocab;
			})
			.catch(function () { vocab = []; return vocab; }); // a failed fetch just means no highlight
		return loading;
	}

	/**
	 * Every book this query could mean, in the order the index page lists them.
	 *
	 * Each answer carries the largest part of the citation THAT book can hold,
	 * exactly as the index filter decorates its rows: "John 3:16666" offers
	 * "Ioannes 3", because a row promising a verse the book has not got is a
	 * link to nowhere.
	 *
	 * Ambiguity is generous on purpose — "Io 3:16" is plausible for every book
	 * the query could name that has a 3:16 — but a book whose lengths RULE the
	 * citation out is dropped, which is what makes the list shrink to one row
	 * as a reference is finished.
	 */
	function candidates(value, glossDs) {
		var out = [];
		if (!vocab || !vocab.length) { return out; }
		var p = parse(value);
		if (!p.q) { return out; }
		for (var i = 0; i < vocab.length; i++) {
			var b = vocab[i];
			if (!hits(b.tokens, p.q) || !canHold(b.verses, p)) { continue; }
			out.push({
				name: b.name,
				slug: b.slug,
				gloss: (glossDs && b.gloss[glossDs]) || '',
				ref: fit(b.verses, p)
			});
		}
		return out;
	}

	function wire(form) {
		var input = form.querySelector('input[name="q"]');
		var url = form.getAttribute('data-dwbible-vocab');
		if (!input || !url) { return; }

		var list = form.querySelector('.dw-nav-search__answers');
		var base = form.getAttribute('data-dwbible-base') || '';
		var glossDs = form.getAttribute('data-dwbible-gloss') || '';
		var listId = list ? (list.getAttribute('id') || '') : '';
		var rows = [];    // the <a> currently drawn, in order
		var active = -1;  // which one the keyboard is on; -1 = none

		/** Where a suggestion leads: the index's own URL + the book (+ citation). */
		function href(c) {
			return base + c.slug + '/' + (c.ref ? c.ref + '/' : '');
		}

		function setActive(n) {
			if (!rows.length) { n = -1; }
			active = n;
			for (var i = 0; i < rows.length; i++) {
				var on = (i === active);
				rows[i].classList.toggle('is-active', on);
				rows[i].setAttribute('aria-selected', on ? 'true' : 'false');
			}
			if (active >= 0) {
				input.setAttribute('aria-activedescendant', rows[active].id);
				// The list scrolls when it is long; keep the keyboard's row in view.
				if (rows[active].scrollIntoView) { rows[active].scrollIntoView({ block: 'nearest' }); }
			} else {
				input.removeAttribute('aria-activedescendant');
			}
		}

		/**
		 * Draw the answers. Rebuilt wholesale on each keystroke: the list is at
		 * most a couple of dozen rows, and a diff would buy nothing but a way
		 * for the DOM and the answer to disagree.
		 */
		function offer(found) {
			if (!list) { return; }
			rows = [];
			list.textContent = '';
			for (var i = 0; i < found.length; i++) {
				var c = found[i];
				if (!c.slug) { continue; } // findable, not offerable
				var li = d.createElement('li');
				// An <li> between the listbox and its options breaks the
				// relationship ARIA requires; the row is the option, the item
				// is only the list's own markup.
				li.setAttribute('role', 'presentation');
				var a = d.createElement('a');
				a.id = listId + '-' + i;
				a.setAttribute('role', 'option');
				a.setAttribute('aria-selected', 'false');
				a.href = href(c);
				var name = d.createElement('span');
				name.className = 'dw-shell-subnav__name';
				// The name carries the citation the row actually leads to, so
				// what is promised and what is linked are the same string.
				name.textContent = c.ref ? c.name + ' ' + c.ref : c.name;
				a.appendChild(name);
				if (c.gloss) {
					var g = d.createElement('span');
					g.className = 'dw-shell-subnav__gloss';
					g.textContent = c.gloss;
					a.appendChild(g);
				}
				li.appendChild(a);
				list.appendChild(li);
				rows.push(a);
			}
			list.hidden = !rows.length;
			input.setAttribute('aria-expanded', rows.length ? 'true' : 'false');
			setActive(-1);
		}

		function reflect() {
			var found = candidates(input.value, glossDs);
			form.classList.toggle('is-resolved', found.length > 0);
			offer(found);
		}

		function close() {
			if (list) { list.hidden = true; }
			input.setAttribute('aria-expanded', 'false');
			rows = [];
			setActive(-1);
		}

		// The vocabulary is fetched on the first sign of interest, never on page
		// load: a reader who does not search never pays for it.
		function wake() {
			loadVocabulary(url).then(reflect);
		}
		input.addEventListener('focus', wake, { once: true });
		input.addEventListener('input', function () {
			if (!vocab) { wake(); return; }
			reflect();
		});

		input.addEventListener('keydown', function (e) {
			if (e.isComposing) { return; } // never steal an IME confirm
			if (e.key === 'Escape') {
				// The theme's rule, one level deeper: Escape closes the
				// innermost thing that is open. nav-action.js already stops the
				// panel's Escape from reaching the drawer; the answers stop it
				// from reaching the panel. A reader who overshot a suggestion
				// does not lose the whole menu for it.
				if (!rows.length) { return; }
				e.preventDefault();
				e.stopPropagation();
				close();
				return;
			}
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				if (!rows.length) { return; }
				e.preventDefault();
				var step = (e.key === 'ArrowDown') ? 1 : -1;
				// Past the last row the keyboard returns to the field itself
				// (-1), so a reader can always get back to editing what they
				// typed without reaching for the mouse.
				var n = active + step;
				if (n >= rows.length) { n = -1; } else if (n < -1) { n = rows.length - 1; }
				setActive(n);
				return;
			}
			if (e.key === 'Enter') {
				// A row the reader CHOSE is followed. Enter on the field itself
				// still submits the form, as it always has, and the server's
				// resolver answers — the same thing that happens with JS off.
				// Deliberately not short-circuited when only one row is showing:
				// two resolvers that agree today are two resolvers that can
				// drift, and the reader loses nothing by the redirect.
				if (active >= 0) {
					e.preventDefault();
					w.location.href = rows[active].getAttribute('href');
				}
			}
		});
	}

	function init() {
		var forms = d.querySelectorAll('form.dw-nav-search[data-dwbible-vocab]');
		for (var i = 0; i < forms.length; i++) { wire(forms[i]); }
	}

	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', init); } else { init(); }
})(window, document);