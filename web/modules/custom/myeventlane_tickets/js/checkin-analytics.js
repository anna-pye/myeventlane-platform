(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.myeventlaneTicketAnalytics = {
    attach: function attach(context) {
      once('myeventlane-ticket-analytics', '.mel-checkin-analytics-page', context).forEach(function init() {
        var settings = drupalSettings.myeventlaneTicketsCheckinAnalytics || {};
        if (!settings.endpoint) {
          return;
        }

        var issuedEl = document.getElementById('mel-analytics-issued');
        var assignedEl = document.getElementById('mel-analytics-assigned');
        var checkedInEl = document.getElementById('mel-analytics-checked-in');
        var progressBarEl = document.getElementById('mel-analytics-progress-bar');
        var progressPercentEl = document.getElementById('mel-analytics-progress-percent');
        var recentBodyEl = document.getElementById('mel-analytics-recent-body');
        var devicesEl = document.getElementById('mel-analytics-devices');
        var usersEl = document.getElementById('mel-analytics-users');
        var refreshMs = Number(settings.refreshMs || 5000);

        async function refresh() {
          try {
            var response = await fetch(settings.endpoint, { credentials: 'same-origin' });
            if (!response.ok) {
              return;
            }
            var data = await response.json();
            var issued = Number(data.issued_count || 0);
            var assigned = Number(data.assigned_count || 0);
            var checkedIn = Number(data.checked_in_count || 0);
            var pct = issued > 0 ? Math.round((checkedIn / issued) * 100) : 0;

            if (issuedEl) {
              issuedEl.textContent = String(issued);
            }
            if (assignedEl) {
              assignedEl.textContent = String(assigned);
            }
            if (checkedInEl) {
              checkedInEl.textContent = String(checkedIn);
            }
            if (progressBarEl) {
              progressBarEl.style.width = String(pct) + '%';
            }
            if (progressPercentEl) {
              progressPercentEl.textContent = String(pct) + '%';
            }

            if (recentBodyEl) {
              renderRecent(recentBodyEl, Array.isArray(data.recent) ? data.recent : []);
            }
            if (devicesEl) {
              renderBreakdown(devicesEl, data.device_breakdown || {});
            }
            if (usersEl) {
              renderBreakdown(usersEl, data.user_breakdown || {});
            }
          } catch (e) {}
        }

        function renderRecent(tbody, rows) {
          if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="5">No scans yet.</td></tr>';
            return;
          }
          tbody.innerHTML = rows.slice(0, 20).map(function (row) {
            var time = formatTimestamp(Number(row.time || 0));
            var result = escapeHtml(String(row.result || ''));
            var ticket = escapeHtml(String(row.ticket_label_masked || '----'));
            var device = escapeHtml(String(row.device_id_short || 'unknown'));
            var user = escapeHtml(String(row.user_label || 'System'));
            return '<tr><td>' + time + '</td><td><span class="result result-' + result + '">' + result + '</span></td><td>' + ticket + '</td><td>' + device + '</td><td>' + user + '</td></tr>';
          }).join('');
        }

        function formatTimestamp(ts) {
          if (!ts) {
            return '-';
          }
          var d = new Date(ts * 1000);
          return d.toLocaleTimeString();
        }

        function escapeHtml(str) {
          return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
        }

        function renderBreakdown(target, breakdown) {
          var entries = Object.entries(breakdown || {});
          if (!entries.length) {
            target.innerHTML = '<li>None yet.</li>';
            return;
          }
          target.innerHTML = entries.slice(0, 10).map(function (item) {
            return '<li><span>' + escapeHtml(String(item[0])) + '</span> <strong>' + String(item[1]) + '</strong></li>';
          }).join('');
        }

        refresh();
        window.setInterval(refresh, refreshMs);
      });
    }
  };
})(Drupal, drupalSettings, once);

