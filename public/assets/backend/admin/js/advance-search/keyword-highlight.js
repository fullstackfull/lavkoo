window.addEventListener("load", function () {
     // Inject a small style tag once so the highlighted <mark> wraps render
    // INLINE with their surrounding word. Bootstrap + sb-admin-2 give
    // <mark> padding: .1875em–.2em, which visibly breaks words apart
    // ("Lan" + " guage" instead of "Language" with the "Lan" portion lit).
    // Loading from this file means the fix applies on every admin page,
    // classic or v2, without modifying any shared CSS or Blade partial.
    if (!document.getElementById('kw-hl-inline-style')) {
        var style = document.createElement('style');
        style.id = 'kw-hl-inline-style';
        // Warm orange (close to the system secondary accent) — high
        // contrast against both white page backgrounds AND coloured pills
        // / chips (active tabs, primary buttons), so the highlight stays
        // visible everywhere without clashing with the primary blue.
        // Forced `color: #1a1a1a` so the highlighted run stays readable
        // even when the parent has white text (e.g. inside a primary
        // blue pill button).
        style.textContent = [
            'mark.kw-hl, .kw-hl-wrap mark, mark, .mark {',
            '    padding: 0 1px !important;',
            '    margin: 0 !important;',
            '    background-color: #ff9d4a !important;',
            '    color: #1a1a1a !important;',
            '    border-radius: 2px;',
            '    font: inherit !important;',
            '    line-height: inherit !important;',
            '    display: inline !important;',
            '    box-decoration-break: clone;',
            '    -webkit-box-decoration-break: clone;',
            '}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function getQueryParam(param) {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(param);
    }

    function escapeRegex(s) {
        return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function highlightText(keyword) {
        if (!keyword) return;

        // Escape user input so special regex characters in the keyword
        // don't blow up the highlighter (e.g. searching "C++" used to
        // throw because of the unbalanced quantifier).
        const regex = new RegExp(`(${escapeRegex(keyword)})`, "gi");

        // Skip highlighting only inside form fields and explicit opt-outs
        // (`[data-no-highlight]`). Buttons, tabs, badges keep getting
        // highlighted — the warm orange + forced dark text in the
        // injected style above keeps the mark readable on every
        // background.
        const SKIP_SELECTOR = [
            'input',
            'textarea',
            'select',
            '[contenteditable="true"]',
            '[data-no-highlight]'
        ].join(',');

        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function (node) {
                    const parent = node.parentNode;
                    if (
                        parent &&
                        parent.nodeName !== "SCRIPT" &&
                        parent.nodeName !== "STYLE" &&
                        !parent.closest("mark") &&
                        !parent.closest(SKIP_SELECTOR) &&
                        node.nodeValue.trim().length > 0
                    ) {
                        return NodeFilter.FILTER_ACCEPT;
                    }
                    return NodeFilter.FILTER_REJECT;
                }
            }
        );

        const nodesToReplace = [];

        while (walker.nextNode()) {
            const node = walker.currentNode;
            if (regex.test(node.nodeValue)) {
                nodesToReplace.push(node);
            }
        }

        nodesToReplace.forEach(node => {
            const span = document.createElement("span");
            span.className = 'kw-hl-wrap';
            span.innerHTML = node.nodeValue.replace(regex, '<mark class="kw-hl">$1</mark>');
            node.parentNode.replaceChild(span, node);
        });
    }

    const keyword = getQueryParam("keyword");
    if (keyword) {
        highlightText(keyword);
    }
});
