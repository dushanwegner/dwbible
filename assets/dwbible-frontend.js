(function(){
    // Main sticky bar and verse navigation logic for The Bible frontend
    var bar = document.querySelector('.dwbible-sticky');
    if (!bar) return;


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
                var group = el.closest && el.closest('.dwbible-interlinear-verse');
                if (group) {
                    var lines = group.querySelectorAll('.dwbible-interlinear-entry');
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
    var container = document.querySelector('.dwbible.dwbible-book') || document.querySelector('.dwbible .dwbible-book');

    function headsList(){
        var list = [];
        if (container) {
            list = Array.prototype.slice.call(container.querySelectorAll('h2[id]'));
        } else {
            list = Array.prototype.slice.call(document.querySelectorAll('.dwbible .dwbible-book h2[id]'));
        }
        return list.filter(function(h){ return /-ch-\d+$/.test(h.id); });
    }

    var heads = headsList();
    var controls = bar.querySelector('.dwbible-sticky__controls');
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
        var group = tgt.closest && tgt.closest('.dwbible-interlinear-verse');
        if (group) {
            verses = Array.prototype.slice.call(group.querySelectorAll('.dwbible-interlinear-entry'));
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


    function update(){
        if (!heads.length) { heads = headsList(); }
        if (!verses.length) { verses = versesList(); }
        // Selection handling moved to dwtexttools plugin
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

        if (isHashHref(topHref) || topHref.indexOf('#dwbible-book-top') === 0 || topHref === '#') {
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
                if (!isHashHref(href) && href.indexOf('#dwbible-book-top') !== 0) return; // allow normal navigation
                e.preventDefault();
                var topEl = document.getElementById('dwbible-book-top');
                smoothToEl(topEl, off);
            });
        }
    }

    // --- Chapter picker grid ---
    var maxCh = parseInt(bar.getAttribute('data-max-ch') || '0', 10);
    var bookUrl = bar.getAttribute('data-book-url') || '';
    var pickerBtn = bar.querySelector('.dwbible-ch-picker');
    var pickerGrid = null;

    function buildChapterGrid() {
        var grid = document.createElement('div');
        grid.className = 'dwbible-ch-grid';
        for (var i = 1; i <= maxCh; i++) {
            var a = document.createElement('a');
            a.href = bookUrl + i;
            a.textContent = i;
            a.className = 'dwbible-ch-grid__cell';
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
        var cells = pickerGrid.querySelectorAll('.dwbible-ch-grid__cell');
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
    // Selection-driven updates moved to dwtexttools plugin
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

