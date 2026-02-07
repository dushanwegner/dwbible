(function(){
    // Main sticky bar and verse navigation logic for The Bible frontend
    var bar = document.querySelector('.thebible-sticky');
    if (!bar) return;

    // --- Helpers mirroring PHP clean_verse_text_for_output / clean_verse_quotes ---

    function normalizeWhitespace(s) {
        if (!s) return '';
        s = String(s);
        // Map a subset of Unicode spaces/invisibles to space or empty (aligned with PHP normalize_whitespace)
        var map = {
            '\u00A0': ' ', // NBSP
            '\u00AD': '',  // Soft hyphen
            '\u1680': ' ', // OGHAM space mark
            '\u2000': ' ', // En quad
            '\u2001': ' ', // Em quad
            '\u2002': ' ', // En space
            '\u2003': ' ', // Em space
            '\u2004': ' ', // Three-per-em space
            '\u2005': ' ', // Four-per-em space
            '\u2006': ' ', // Six-per-em space
            '\u2007': ' ', // Figure space
            '\u2008': ' ', // Punctuation space
            '\u2009': ' ', // Thin space
            '\u200A': ' ', // Hair space
            '\u200B': '',  // Zero width space
            '\u200C': '',  // Zero width non-joiner
            '\u200D': '',  // Zero width joiner
            '\u200E': '',  // LRM
            '\u200F': '',  // RLM
            '\u2028': ' ', // Line separator
            '\u2029': ' ', // Paragraph separator
            '\u202F': ' ', // Narrow no-break space
            '\u2060': ' ', // Word joiner
            '\uFEFF': ''   // BOM
        };
        s = s.replace(/[\u00A0\u00AD\u1680\u2000-\u200F\u2028\u2029\u202F\u2060\uFEFF]/g, function(ch){
            return Object.prototype.hasOwnProperty.call(map, ch) ? map[ch] : ' ';
        });
        // Collapse whitespace and trim
        s = s.replace(/\s+/g, ' ').trim();
        return s;
    }

    function cleanVerseTextForOutput(s, wrapOuter) {
        var qL = '\u00BB'; // »
        var qR = '\u00AB'; // «
        s = normalizeWhitespace(s);
        s = String(s);
        if (!s) return s;

        // Approximate control/combining removal: strip general C0/C1 controls except tab/newline, and some combining marks
        s = s.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/g, '');
        // Drop characters that are not "word-ish" or punctuation/symbol/space; this is a conservative approximation
        var reDisallowed = null;
        try {
            reDisallowed = new RegExp('[^\\p{L}\\p{N}\\p{P}\\p{S}\\s]', 'gu');
        } catch (e) {
            reDisallowed = null;
        }
        if (reDisallowed) {
            s = s.replace(reDisallowed, '');
        }

        var hasLeft = s.indexOf(qR) !== -1;  // «
        var hasRight = s.indexOf(qL) !== -1; // »

        // (1) Balance single-sided quotes
        if (hasRight && !hasLeft) {
            // Only » present: add a closing « at the very end
            s += qR;
            hasLeft = true;
        } else if (hasLeft && !hasRight) {
            // Only « present: add an opening » at the start
            s = qL + s;
            hasRight = true;
        }

        // (2) If there is both » and «, normalize them to inner guillemets › ‹
        if (hasLeft && hasRight) {
            s = s.replace(/[f]/g, function(ch){ return ch; }); // no-op safeguard
            s = s.replace(/[\u00AB\u00BB]/g, function(ch){
                return ch === qR ? '\u2039' : '\u203A'; // « -> ‹, » -> ›
            });
            s = s.replace(/\u2039/g, '\u2039').replace(/\u203A/g, '\u203A');
        }

        // (3) Post-pass for boundary »› ... ‹« -> » ... «
        if (s.length >= 2) {
            var starts = s.slice(0, 2) === (qL + '\u203A');
            var ends   = s.slice(-2) === ('\u2039' + qR);
            if (starts && ends) {
                s = qL + s.slice(2);
                if (s.length >= 2 && s.slice(-2) === ('\u2039' + qR)) {
                    s = s.slice(0, -2) + qR;
                }
            }
        }

        // Normalize surrounding whitespace once more after quote adjustments
        s = s.trim();

        // If the quote ends with a space + m- or n-dash immediately before
        // a guillemet (inner or outer), strip the space and dash but keep the
        // guillemet. Mirrors the PHP clean_verse_quotes() behavior.
        s = s.replace(/\s*[\u2013\u2014]\s*([\u00AB\u00BB\u2039\u203A])\s*$/u, '$1');

        // Also strip a trailing m-/n-dash at the very end (no closing
        // guillemet): "… und der Propheten. –" -> "… und der Propheten.".
        s = s.replace(/[\u2013\u2014]\s*$/u, '').trim();

        if (wrapOuter) {
            var len2 = s.length;
            // If already wrapped with the requested outer quotes, do not wrap again.
            if (len2 >= 2 && s.charAt(0) === qL && s.charAt(len2 - 1) === qR) {
                // no-op
            }
            // If wrapped in inner guillemets ›...‹, promote them to the requested outer quotes.
            else if (len2 >= 2 && s.charAt(0) === '\u203A' && s.charAt(len2 - 1) === '\u2039') {
                s = qL + s.substring(1, len2 - 1) + qR;
            } else {
                // Otherwise, wrap the whole text once using qL/qR.
                s = qL + s + qR;
            }
        }
        return s;
    }

    // Highlight / initial scroll configuration from data attributes
    function parseHighlightIds(attr) {
        if (!attr) return [];
        try {
            var parsed = JSON.parse(attr);
            if (Array.isArray(parsed)) return parsed;
        } catch (e) {}
        // Fallback: comma-separated list
        return attr.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    }

    var highlightAttr = bar.getAttribute('data-highlight-ids');
    var chapterScrollId = bar.getAttribute('data-chapter-scroll-id');
    var highlightIds = parseHighlightIds(highlightAttr);

    // Scroll offsets helper used by both initial scroll and sticky logic
    function computeOffset(extra) {
        var ab = document.getElementById('wpadminbar');
        var abH = (document.body.classList.contains('admin-bar') && ab) ? ab.offsetHeight : 0;
        var barH = bar ? bar.offsetHeight : 0;
        return abH + barH + (typeof extra === 'number' ? extra : 25);
    }

    // Initial highlighting / scrolling behavior
    if (highlightIds && highlightIds.length) {
        // Verse highlighting and scroll to first highlighted verse
        var ids = highlightIds.slice();
        var first = null;
        ids.forEach(function(id){
            var el = document.getElementById(id);
            if (el) {
                var group = el.closest && el.closest('.thebible-interlinear-verse');
                if (group) {
                    var lines = group.querySelectorAll('.thebible-interlinear-entry');
                    for (var i = 0; i < lines.length; i++) {
                        lines[i].classList.add('verse-highlight');
                    }
                    if (!first) first = group;
                } else {
                    el.classList.add('verse-highlight');
                    if (!first) first = el;
                }
            }
        });
        if (first) {
            var r = first.getBoundingClientRect();
            var y = window.pageYOffset + r.top - computeOffset(25);
            window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
        }
    } else if (chapterScrollId) {
        // Chapter-only: scroll to chapter heading
        var el = document.getElementById(chapterScrollId);
        if (el) {
            var r2 = el.getBoundingClientRect();
            var y2 = window.pageYOffset + r2.top - computeOffset(25);
            window.scrollTo({ top: Math.max(0, y2), behavior: 'smooth' });
        }
    }

    // Sticky updater script: detect current chapter and update bar on scroll; offset for admin bar
    var container = document.querySelector('.thebible.thebible-book') || document.querySelector('.thebible .thebible-book');

    function headsList(){
        var list = [];
        if (container) {
            list = Array.prototype.slice.call(container.querySelectorAll('h2[id]'));
        } else {
            list = Array.prototype.slice.call(document.querySelectorAll('.thebible .thebible-book h2[id]'));
        }
        return list.filter(function(h){ return /-ch-\d+$/.test(h.id); });
    }

    var heads = headsList();
    var controls = bar.querySelector('.thebible-sticky__controls');
    var origControlsHtml = controls ? controls.innerHTML : '';
    var linkPrev = bar.querySelector('[data-prev]');
    var linkNext = bar.querySelector('[data-next]');
    var linkTop = bar.querySelector('[data-top]');

    function isHashHref(href){
        return href && href.charAt(0) === '#';
    }

    function setTopOffset(){
        var ab = document.getElementById('wpadminbar');
        var off = (document.body.classList.contains('admin-bar') && ab) ? ab.offsetHeight : 0;
        if (off > 0) {
            bar.style.top = off + 'px';
        } else {
            bar.style.top = '';
        }
    }

    function disable(el, yes){
        if (!el) return;
        if (yes) {
            el.classList.add('is-disabled');
            el.setAttribute('aria-disabled', 'true');
            el.setAttribute('tabindex', '-1');
        } else {
            el.classList.remove('is-disabled');
            el.removeAttribute('aria-disabled');
            el.removeAttribute('tabindex');
        }
    }

    function setChText(el, text) {
        if (!el) return;
        var numEl = el.querySelector('[data-ch-num]');
        if (numEl) {
            numEl.textContent = text;
        } else {
            el.textContent = text;
        }
    }

    function smoothToEl(el, offsetPx){
        if (!el) return;
        var r = el.getBoundingClientRect();
        var y = window.pageYOffset + r.top - (offsetPx || 0);
        window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    }

    // Flash highlight on in-page verse link clicks
    document.addEventListener('click', function(e){
        var a = e.target && e.target.closest && e.target.closest('a[href*="#"]');
        if (!a) return;
        var href = a.getAttribute('href') || '';
        var hashIndex = href.indexOf('#');
        if (hashIndex === -1) return;
        var id = href.slice(hashIndex + 1);
        if (!id) return;
        var tgt = document.getElementById(id);
        if (!tgt) return;
        var verses = [];
        var group = tgt.closest && tgt.closest('.thebible-interlinear-verse');
        if (group) {
            verses = Array.prototype.slice.call(group.querySelectorAll('.thebible-interlinear-entry'));
        } else {
            var verse = null;
            if (tgt.matches && tgt.matches('p')) {
                verse = tgt;
            } else if (tgt.closest) {
                var p = tgt.closest('p');
                if (p) verse = p;
            }
            if (verse) {
                verses = [verse];
            }
        }
        if (!verses || !verses.length) return;
        for (var i = 0; i < verses.length; i++) {
            verses[i].classList.add('verse');
        }
        setTimeout(function(){
            for (var j = 0; j < verses.length; j++) {
                verses[j].classList.remove('verse-flash');
                void verses[j].offsetWidth;
                verses[j].classList.add('verse-flash');
            }
            setTimeout(function(){
                for (var k = 0; k < verses.length; k++) {
                    verses[k].classList.remove('verse-flash');
                }
            }, 2000);
        }, 0);
    }, true);

    function currentOffset(){
        return computeOffset(25);
    }

    function versesList(){
        var list = [];
        if (!container) return list;
        list = Array.prototype.slice.call(container.querySelectorAll('p[id]'));
        return list.filter(function(p){ return /-\d+-\d+$/.test(p.id); });
    }

    function getVerseFromNode(node){
        if (!node) return null;
        var el = (node.nodeType === 1 ? node : node.parentElement);
        while (el && el !== container) {
            if (el.matches && el.matches('p[id]') && /-\d+-\d+$/.test(el.id)) return el;
            el = el.parentElement;
        }
        return null;
    }

    var verses = versesList();

    function selectionInfo(){
        var sel = window.getSelection && window.getSelection();
        if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return null;
        var range = sel.getRangeAt(0);
        var aVerse = getVerseFromNode(sel.anchorNode);
        var fVerse = getVerseFromNode(sel.focusNode);
        var startIdx = -1, endIdx = -1;
        if (aVerse && fVerse) {
            for (var i = 0; i < verses.length; i++) {
                if (verses[i] === aVerse) startIdx = i;
                if (verses[i] === fVerse) endIdx = i;
            }
            if (startIdx > -1 && endIdx > -1 && startIdx > endIdx) {
                var t = startIdx; startIdx = endIdx; endIdx = t;
            }
        }
        if (startIdx === -1 || endIdx === -1) {
            startIdx = -1; endIdx = -1;
            for (var j = 0; j < verses.length; j++) {
                var v = verses[j];
                var r = document.createRange();
                r.selectNode(v);
                var intersects = !(range.compareBoundaryPoints(Range.END_TO_START, r) <= 0 || range.compareBoundaryPoints(Range.START_TO_END, r) >= 0);
                if (intersects) {
                    if (startIdx === -1) startIdx = j;
                    endIdx = j;
                }
            }
        }
        if (startIdx === -1) return null;
        var sid = verses[startIdx].id;
        var eid = verses[endIdx].id;
        var sm = sid.match(/-(\d+)-(\d+)$/);
        var em = eid.match(/-(\d+)-(\d+)$/);
        if (!sm || !em) return null;
        return {
            sCh: parseInt(sm[1], 10),
            sV: parseInt(sm[2], 10),
            eCh: parseInt(em[1], 10),
            eV: parseInt(em[2], 10)
        };
    }

    var selTimer = null;
    var lastSelectionTime = 0;
    function scheduleUpdate(){
        if (selTimer) clearTimeout(selTimer);
        selTimer = setTimeout(update, 50);
    }

    function buildRef(info){
        if (!info) return '';
        var labelEl = bar.querySelector('[data-label]');
        var book = labelEl ? labelEl.textContent.trim() : '';
        if (info.sCh === info.eCh) {
            return book + ' ' + info.sCh + ':' + (info.sV === info.eV ? info.sV : info.sV + '-' + info.eV);
        }
        return book + ' ' + info.sCh + ':' + info.sV + '-' + info.eCh + ':' + info.eV;
    }

    function buildLink(info){
        var base = location.origin + location.pathname
            .replace(/\/?(\d+(?::\d+(?:-\d+)?)?)\/?$/, '/')
            .replace(/#.*$/, '');
        if (info.sCh === info.eCh) {
            return base + info.sCh + ':' + (info.sV === info.eV ? info.sV : info.sV + '-' + info.eV);
        }
        return base + info.sCh + ':' + info.sV + '-' + info.eCh + ':' + info.eV;
    }

    function copyToClipboard(txt){
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(txt);
        }
        var ta = document.createElement('textarea');
        ta.value = txt;
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
        } finally {
            document.body.removeChild(ta);
        }
        return Promise.resolve();
    }

    function verseText(info){
        var out = [];
        if (!info) return '';
        for (var i = 0; i < verses.length; i++) {
            var pid = verses[i].id;
            var m = pid.match(/-(\d+)-(\d+)$/);
            if (!m) continue;
            var ch = parseInt(m[1], 10), v = parseInt(m[2], 10);
            var within = (ch > info.sCh || (ch === info.sCh && v >= info.sV)) &&
                         (ch < info.eCh || (ch === info.eCh && v <= info.eV));
            if (within) {
                var body = verses[i].querySelector('.verse-body');
                out.push(body ? body.textContent.trim() : verses[i].textContent.trim());
            }
        }
        return out.join(' ');
    }

    function renderSelectionControls(info){
        if (!controls) return;
        var ref = buildRef(info);
        var link = buildLink(info);
        var rawTxt = verseText(info).trim();
        var cleanedTxt = cleanVerseTextForOutput(rawTxt, true); // mirror PHP behavior and wrap in » «
        var payload = cleanedTxt + ' (' + ref + ') ' + link;

        // OG image download URL for the selection (served by PHP via query vars + thebible_og=1)
        var ogUrl = link + (link.indexOf('?') === -1 ? '?' : '&') + 'thebible_og=1&thebible_og_download=1';

        controls.innerHTML = 'share: <a href="#" data-copy-url>URL</a> <a href="#" data-copy-main>copy</a> <a href="#" data-post-x>post to X</a> <a href="#" data-download-image>image</a>';

        var aUrl = controls.querySelector('[data-copy-url]');
        if (aUrl) aUrl.addEventListener('click', function(e){
            e.preventDefault();
            copyToClipboard(link).then(function(){
                aUrl.textContent = 'copied';
                setTimeout(function(){ aUrl.textContent = 'URL'; }, 1000);
            });
        });

        var aCopy = controls.querySelector('[data-copy-main]');
        if (aCopy) aCopy.addEventListener('click', function(e){
            e.preventDefault();
            copyToClipboard(payload).then(function(){
                aCopy.textContent = 'copied';
                setTimeout(function(){ aCopy.textContent = 'copy'; }, 1000);
            });
        });

        var aX = controls.querySelector('[data-post-x]');
        if (aX) aX.addEventListener('click', function(e){
            e.preventDefault();
            var url = 'https://x.com/intent/tweet?text=' + encodeURIComponent(payload);
            window.open(url, '_blank', 'noopener');
        });

        var aImg = controls.querySelector('[data-download-image]');
        if (aImg) aImg.addEventListener('click', function(e){
            e.preventDefault();
            // Direct download handled by PHP (Content-Disposition: attachment)
            window.location.href = ogUrl;
        });
    }

    function ensureStandardControls(){
        if (!controls) return;
        if (controls.innerHTML !== origControlsHtml) {
            controls.innerHTML = origControlsHtml;
            bar._bound = false;
            linkPrev = bar.querySelector('[data-prev]');
            linkNext = bar.querySelector('[data-next]');
            linkTop = bar.querySelector('[data-top]');
        }
    }

    function update(){
        if (!heads.length) { heads = headsList(); }
        if (!verses.length) { verses = versesList(); }
        var info = selectionInfo();
        var elCh = bar.querySelector('[data-ch]');
        if (info && elCh) {
            lastSelectionTime = Date.now();
            setChText(elCh, buildRef(info).replace(/^.*?\s(.*)$/, '$1'));
            if (elCh.classList) elCh.classList.add('has-selection');
            if (typeof closeChapterPicker === 'function') closeChapterPicker();
            if (controls) renderSelectionControls(info);
        } else {
            ensureStandardControls();
            if (elCh && elCh.classList) elCh.classList.remove('has-selection');
            lastSelectionTime = 0;
        }
        var topCut = window.innerHeight * 0.2;
        var current = null;
        var currentIdx = 0;
        for (var i = 0; i < heads.length; i++) {
            var h = heads[i];
            var r = h.getBoundingClientRect();
            if (r.top <= topCut) {
                current = h;
                currentIdx = i;
            } else {
                break;
            }
        }
        if (!current) {
            current = heads[0] || null;
            currentIdx = 0;
        }

        if (!info) {
            // Determine the chapter number from a stable source first.
            // On initial load, PHP already renders the correct chapter; avoid reverting to 1
            // if heading detection temporarily fails during layout.
            var ch = 0;

            if (elCh) {
                var existing = parseInt((elCh.textContent || '').trim(), 10);
                if (!isNaN(existing) && existing > 0) {
                    ch = existing;
                }
            }

            if (!ch && chapterScrollId) {
                var mBar = String(chapterScrollId).match(/-ch-(\d+)$/);
                if (mBar) {
                    var fromBar = parseInt(mBar[1], 10);
                    if (!isNaN(fromBar) && fromBar > 0) {
                        ch = fromBar;
                    }
                }
            }

            if (!ch && current) {
                var m = current.id.match(/-ch-(\d+)$/);
                if (m) {
                    var fromHead = parseInt(m[1], 10);
                    if (!isNaN(fromHead) && fromHead > 0) {
                        ch = fromHead;
                    }
                }
            }

            if (!ch) { ch = 1; }

            if (elCh) {
                setChText(elCh, String(ch));
            }
        }
        var off = currentOffset();
        var prevHref = linkPrev ? (linkPrev.getAttribute('href') || '') : '';
        var nextHref = linkNext ? (linkNext.getAttribute('href') || '') : '';
        var topHref  = linkTop ? (linkTop.getAttribute('href') || '') : '';

        // Only manage/disable the controls when they are intended as in-page anchors.
        // If PHP provided real URLs (cross-book navigation), leave hrefs alone and never disable.
        if (isHashHref(prevHref) || prevHref === '#') {
            if (currentIdx <= 0) {
                disable(linkPrev, true);
                if (linkPrev) linkPrev.href = '#';
            } else {
                disable(linkPrev, false);
                if (linkPrev) linkPrev.href = '#' + heads[currentIdx - 1].id;
            }
        } else {
            disable(linkPrev, false);
        }

        if (isHashHref(nextHref) || nextHref === '#') {
            if (currentIdx >= heads.length - 1) {
                disable(linkNext, true);
                if (linkNext) linkNext.href = '#';
            } else {
                disable(linkNext, false);
                if (linkNext) linkNext.href = '#' + heads[currentIdx + 1].id;
            }
        } else {
            disable(linkNext, false);
        }

        if (isHashHref(topHref) || topHref.indexOf('#thebible-book-top') === 0 || topHref === '#') {
            if (currentIdx <= 0) {
                disable(linkTop, true);
            } else {
                disable(linkTop, false);
            }
        } else {
            // real URL to index-page -> never disable
            disable(linkTop, false);
        }
        if (!bar._bound) {
            bar._bound = true;
            if (linkPrev) linkPrev.addEventListener('click', function(e){
                if (this.classList.contains('is-disabled')) return;
                var href = this.getAttribute('href') || '';
                if (!href || href === '#') return;
                if (!isHashHref(href)) return; // allow normal navigation for real URLs
                e.preventDefault();
                var id = href.replace(/^#/, '');
                var el = document.getElementById(id);
                smoothToEl(el, off);
            });
            if (linkNext) linkNext.addEventListener('click', function(e){
                if (this.classList.contains('is-disabled')) return;
                var href = this.getAttribute('href') || '';
                if (!href || href === '#') return;
                if (!isHashHref(href)) return; // allow normal navigation for real URLs
                e.preventDefault();
                var id = href.replace(/^#/, '');
                var el = document.getElementById(id);
                smoothToEl(el, off);
            });
            if (linkTop) linkTop.addEventListener('click', function(e){
                if (this.classList.contains('is-disabled')) return;
                var href = this.getAttribute('href') || '';
                if (!href || href === '#') return;
                if (!isHashHref(href) && href.indexOf('#thebible-book-top') !== 0) return; // allow normal navigation
                e.preventDefault();
                var topEl = document.getElementById('thebible-book-top');
                smoothToEl(topEl, off);
            });
        }
    }

    // --- Chapter picker grid ---
    var maxCh = parseInt(bar.getAttribute('data-max-ch') || '0', 10);
    var bookUrl = bar.getAttribute('data-book-url') || '';
    var pickerBtn = bar.querySelector('.thebible-ch-picker');
    var pickerGrid = null;

    function buildChapterGrid() {
        var grid = document.createElement('div');
        grid.className = 'thebible-ch-grid';
        for (var i = 1; i <= maxCh; i++) {
            var a = document.createElement('a');
            a.href = bookUrl + i;
            a.textContent = i;
            a.className = 'thebible-ch-grid__cell';
            grid.appendChild(a);
        }
        bar.appendChild(grid);
        return grid;
    }

    function highlightCurrentChapter() {
        if (!pickerGrid) return;
        var elCh = bar.querySelector('[data-ch]');
        var numEl = elCh ? elCh.querySelector('[data-ch-num]') : null;
        var ch = numEl ? parseInt((numEl.textContent || '').trim(), 10)
                       : (elCh ? parseInt((elCh.textContent || '').trim(), 10) : 0);
        var cells = pickerGrid.querySelectorAll('.thebible-ch-grid__cell');
        for (var i = 0; i < cells.length; i++) {
            cells[i].classList.toggle('is-current', parseInt(cells[i].textContent, 10) === ch);
        }
    }

    function toggleChapterPicker() {
        if (!pickerGrid) {
            pickerGrid = buildChapterGrid();
        }
        var isOpen = pickerGrid.classList.toggle('is-open');
        if (pickerBtn) pickerBtn.classList.toggle('is-open', isOpen);
        if (isOpen) highlightCurrentChapter();
    }

    function closeChapterPicker() {
        if (pickerGrid && pickerGrid.classList.contains('is-open')) {
            pickerGrid.classList.remove('is-open');
            if (pickerBtn) pickerBtn.classList.remove('is-open');
        }
    }

    if (pickerBtn && maxCh > 1 && bookUrl) {
        pickerBtn.addEventListener('click', function(e) {
            if (this.classList.contains('has-selection')) return;
            e.preventDefault();
            e.stopPropagation();
            toggleChapterPicker();
        });
        document.addEventListener('click', function(e) {
            if (pickerGrid && pickerGrid.classList.contains('is-open')) {
                if (!pickerBtn.contains(e.target) && !pickerGrid.contains(e.target)) {
                    closeChapterPicker();
                }
            }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeChapterPicker();
        });
    }

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', function(){ heads = headsList(); setTopOffset(); update(); }, { passive: true });
    document.addEventListener('DOMContentLoaded', function(){ setTopOffset(); update(); });
    document.addEventListener('selectionchange', scheduleUpdate, { passive: true });
    document.addEventListener('mouseup', scheduleUpdate, { passive: true });
    document.addEventListener('keyup', scheduleUpdate, { passive: true });
    window.addEventListener('load', function(){ setTopOffset(); update(); });

    // Intercept in-content anchor clicks to scroll below sticky and adjust URL
    document.addEventListener('click', function(e){
        var a = e.target.closest && e.target.closest('a[href^="#"]');
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!href || href === '#') return;
        var id = href.replace(/^#/, '');
        var el = document.getElementById(id);
        if (!el) return;
        e.preventDefault();
        smoothToEl(el, currentOffset());
        var m = id.match(/-(\d+)-(\d+)$/);
        if (history && history.replaceState && m) {
            var ch = m[1], v = m[2];
            var base = location.origin + location.pathname
                .replace(/\/?(\d+(?::\d+(?:-\d+)?)?)\/?$/, '/')
                .replace(/#.*$/, '');
            history.replaceState(null, '', base + ch + ':' + v);
        } else if (history && history.replaceState) {
            history.replaceState(null, '', '#' + id);
        }
    }, { passive: false });

    // Adjust on hash navigation
    window.addEventListener('hashchange', function(){
        var id = location.hash.replace(/^#/, '');
        var el = document.getElementById(id);
        if (el) {
            smoothToEl(el, currentOffset());
            var m = id.match(/-(\d+)-(\d+)$/);
            if (history && history.replaceState && m) {
                var ch = m[1], v = m[2];
                var base = location.origin + location.pathname
                    .replace(/\/?(\d+(?::\d+(?:-\d+)?)?)\/?$/, '/');
                history.replaceState(null, '', base + ch + ':' + v);
            }
        }
    });

    setTopOffset();
    update();
})();
