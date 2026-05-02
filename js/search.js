(function () {
    var input    = document.getElementById('global-search');
    var dropdown = document.getElementById('search-dropdown');
    if (!input || !dropdown) return;

    // Prevent XSS when injecting text into HTML
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function positionDropdown() {
        var rect = input.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.top      = (rect.bottom + 4) + 'px';
        dropdown.style.left     = rect.left + 'px';
        dropdown.style.width    = rect.width + 'px';
        dropdown.style.zIndex   = '9999';
    }

    function hideDropdown() {
        dropdown.classList.add('hidden');
        dropdown.innerHTML = '';
        activeIndex = -1;
    }

    // Keyboard navigation state
    var activeIndex = -1;

    function getItems() {
        return dropdown.querySelectorAll('.search-item');
    }

    function setActive(index) {
        var items = getItems();
        if (!items.length) return;
        // Wrap around: going up from 0 goes to last, going down from last goes to -1 (input focus)
        if (index >= items.length) index = -1;
        if (index < -1) index = items.length - 1;
         items.forEach(function (el) { el.classList.remove('active'); });
        activeIndex = index;
        if (activeIndex >= 0) {
        items[activeIndex].classList.add('active');
        items[activeIndex].scrollIntoView({ block: 'nearest' }); // handles overflow scroll in dropdown
    }
}

    input.addEventListener('keydown', function (e) {
        if (dropdown.classList.contains('hidden')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(activeIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(activeIndex - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var items = getItems();
            // Default to first result if nothing is arrow-selected
            var target = activeIndex >= 0 ? items[activeIndex] : items[0];
            if (target) target.click();
        } else if (e.key === 'Escape') {
            hideDropdown();
            input.blur();
        }
    });

    // Reposition if user scrolls or resizes (dropdown is fixed but input may move)
    window.addEventListener('scroll', function () {
        if (!dropdown.classList.contains('hidden')) positionDropdown();
    }, true);
    window.addEventListener('resize', function () {
        if (!dropdown.classList.contains('hidden')) positionDropdown();
    });

    var timer;
    var currentQuery = '';

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        currentQuery = q;
        if (q.length < 2) { hideDropdown(); return; }

        // Show a loading indicator immediately
        positionDropdown();
        dropdown.innerHTML = '<div class="search-loading">Searching…</div>';
        dropdown.classList.remove('hidden');

        timer = setTimeout(function () {
            var fetchedQuery = q;
            fetch('/~g1154085/includes/search.php?q=' + encodeURIComponent(q))
                .then(function (r) {
                    if (!r.ok) throw new Error('Network error');
                    return r.json();
                })
                .then(function (data) {
                    // Discard stale responses if user kept typing
                    if (fetchedQuery !== currentQuery) return;
                    if (!data.length) { hideDropdown(); return; }

                    activeIndex = -1;
                    dropdown.innerHTML = '';

                    data.forEach(function (item) {
                        var el = document.createElement('div');
                        el.className = 'search-item';
                        el.innerHTML =
                            '<span class="search-item-type">'  + escapeHtml(item.type)  + '</span>'
                          + '<div class="search-item-label">'  + escapeHtml(item.label) + '</div>'
                          + '<div class="search-item-meta">'   + escapeHtml(item.meta)  + '</div>';

                        // Safe click — never interpolates url into HTML
                        el.addEventListener('click', function () {
                            window.location = item.url;
                        });
                        dropdown.appendChild(el);
                    });

                    positionDropdown();
                    dropdown.classList.remove('hidden');
                })
                .catch(function () { hideDropdown(); });
        }, 250);
    });

    // Re-open dropdown on focus if there's already a valid query
    input.addEventListener('focus', function () {
        if (input.value.trim().length >= 2) {
            input.dispatchEvent(new Event('input'));
        }
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            hideDropdown();
        }
    });
})();