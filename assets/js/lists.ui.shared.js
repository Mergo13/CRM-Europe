/* ======================================================
   Shared UI behavior for list pages
====================================================== */

function animateCount(el, newValue) {
    const oldValue = parseInt(el.dataset.value || '0', 10);
    el.dataset.value = newValue;

    if (oldValue === newValue) {
        el.textContent = `${newValue} ausgewählt`;
        return;
    }

    const duration = 180;
    const start = performance.now();

    function tick(now) {
        const progress = Math.min((now - start) / duration, 1);
        const value = Math.round(oldValue + (newValue - oldValue) * progress);
        el.textContent = `${value} ausgewählt`;

        if (progress < 1) {
            requestAnimationFrame(tick);
        } else {
            el.classList.add('count-pulse');
            setTimeout(() => el.classList.remove('count-pulse'), 180);
        }
    }

    requestAnimationFrame(tick);
}

/* Hook into existing list pages */
document.addEventListener('list:selectionChanged', (e) => {
    const { count } = e.detail;
    const el = document.getElementById('selectedCount');
    if (el) animateCount(el, count);
});
