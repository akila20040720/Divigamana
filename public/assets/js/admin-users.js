/* Refresh the live data without replacing filter inputs or interrupting actions. */
(() => {
  let loading = false;
  const refresh = async () => {
    if (document.hidden || loading) return;
    const current = document.querySelector('[data-user-results]');
    if (!current || current.contains(document.activeElement)) return;
    loading = true;
    try {
      const response = await fetch(window.location.href, {cache: 'no-store', credentials: 'same-origin'});
      if (!response.ok) return;
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const updated = page.querySelector('[data-user-results]');
      if (!current.isConnected || current.contains(document.activeElement) || !updated) return;
      const metrics = document.querySelector('[data-dashboard-metrics]');
      const updatedMetrics = page.querySelector('[data-dashboard-metrics]');
      if (metrics && updatedMetrics) metrics.replaceWith(updatedMetrics);
      current.replaceWith(updated);
    } catch (_) { /* Keep the existing results until the next refresh. */ }
    finally { loading = false; }
  };
  window.setInterval(refresh, 30000);
})();
