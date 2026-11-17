/* Refresh the live data without replacing filter inputs or interrupting actions. */
(() => {
  let loading = false;
  const refresh = async () => {
    if (document.hidden || loading) return;
    const current = document.querySelector('[data-user-results]');
    if (!current) return;
    loading = true;
    try {
      const response = await fetch(window.location.href, {cache: 'no-store', credentials: 'same-origin'});
      if (!response.ok) return;
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const updated = page.querySelector('[data-user-results]');
      if (document.hidden || !current.isConnected || !updated) return;
      const metrics = document.querySelector('[data-dashboard-metrics]');
      const updatedMetrics = page.querySelector('[data-dashboard-metrics]');
      if (metrics && updatedMetrics) metrics.replaceWith(updatedMetrics);
      // Leave a focused action or pagination link intact until focus moves away.
      if (!current.contains(document.activeElement)) current.replaceWith(updated);
    } catch (_) { /* Keep the existing results until the next refresh. */ }
    finally { loading = false; }
  };
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refresh();
  });
  document.addEventListener('focusout', event => {
    const results = document.querySelector('[data-user-results]');
    if (!results || !results.contains(event.target) || results.contains(event.relatedTarget)) return;
    // Wait for activeElement to reflect the new focus before fetching.
    window.setTimeout(refresh, 0);
  });
  window.setInterval(refresh, 10000);
})();
