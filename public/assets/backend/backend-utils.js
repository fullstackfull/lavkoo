document.addEventListener('DOMContentLoaded', function() {
    const setTodayMin = (el) => {
        el.setAttribute('min', new Date().toISOString().split('T')[0]);
    };

    const initTodayDateMinimum = () => {
        document.querySelectorAll('.set-today-date-minimum').forEach(setTodayMin);
    };

    // Initial run when page loads
    initTodayDateMinimum();

    // Observe DOM changes for dynamically added elements
    const observer = new MutationObserver((mutationsList) => {
        for (const mutation of mutationsList) {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1) { // element node
                    if (node.classList?.contains('set-today-date-minimum')) {
                        setTodayMin(node);
                    }
                    // Check inside added elements
                    node.querySelectorAll?.('.set-today-date-minimum').forEach(setTodayMin);
                }
            });
        }
    });

    observer.observe(document.body, { childList: true, subtree: true });
});
